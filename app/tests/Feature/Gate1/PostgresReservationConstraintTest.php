<?php

namespace Tests\Feature\Gate1;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use PDOException;
use Tests\TestCase;

class PostgresReservationConstraintTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Gate 1 constraint tests require PostgreSQL.');
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_two_concurrent_transactions_cannot_reserve_the_same_lock_window(): void
    {
        $user = User::factory()->create();
        DB::disconnect();

        $resultPath = tempnam(sys_get_temp_dir(), 'movie-gate1-result-');
        $signalPath = $resultPath.'.go';
        $childPid = pcntl_fork();

        if ($childPid === 0) {
            $deadline = microtime(true) + 5;
            while (! file_exists($signalPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            try {
                $pdo = $this->newPdo();
                $pdo->exec("SET lock_timeout = '4s'");
                $pdo->beginTransaction();
                $this->insertRawReservation($pdo, Str::uuid()->toString(), $user->id);
                $pdo->commit();
                file_put_contents($resultPath, 'unexpected-success');
            } catch (PDOException $exception) {
                file_put_contents($resultPath, (string) $exception->getCode());
            }
            exit(0);
        }

        $pdo = $this->newPdo();
        $pdo->beginTransaction();
        $this->insertRawReservation($pdo, Str::uuid()->toString(), $user->id);
        file_put_contents($signalPath, 'go');
        usleep(300_000);
        $pdo->commit();

        pcntl_waitpid($childPid, $status);
        $result = trim((string) file_get_contents($resultPath));
        @unlink($signalPath);
        @unlink($resultPath);

        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame('23P01', $result);
        $this->assertSame(1, Reservation::count());
    }

    public function test_back_to_back_user_windows_are_allowed(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T00:00:00Z');
        $service = app(ReservationService::class);
        $service->create(User::factory()->create(), CarbonImmutable::parse('2026-08-24T12:00:00-07:00'), CarbonImmutable::parse('2026-08-24T13:00:00-07:00'));
        $service->create(User::factory()->create(), CarbonImmutable::parse('2026-08-24T13:00:00-07:00'), CarbonImmutable::parse('2026-08-24T14:00:00-07:00'));

        $this->assertSame(2, Reservation::count());
    }

    public function test_expired_resource_locked_row_still_blocks_provisioning(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T00:00:00Z');
        $locked = $this->reservation(User::factory()->create(), '2026-08-20T10:00:00Z', '2026-08-20T11:00:00Z', ReservationStatus::ResourceLocked);
        $future = app(ReservationService::class)->create(
            User::factory()->create(),
            CarbonImmutable::parse('2026-08-24T10:00:00Z'),
            CarbonImmutable::parse('2026-08-24T11:00:00Z'),
        );

        $this->assertTrue($locked->lock_ends_at->isPast());
        $this->expectException(DomainException::class);
        app(ReservationService::class)->transitionToProvisioning($future);
    }

    public function test_end_must_be_on_the_hour_and_duration_cannot_exceed_eight_absolute_hours(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T00:00:00Z');
        $service = app(ReservationService::class);
        $user = User::factory()->create();

        try {
            $service->create($user, CarbonImmutable::parse('2026-08-24T10:15:00Z'), CarbonImmutable::parse('2026-08-24T11:30:00Z'));
            $this->fail('Non-hour end was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_at', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->create($user, CarbonImmutable::parse('2026-08-24T10:00:00Z'), CarbonImmutable::parse('2026-08-24T19:00:00Z'));
    }

    public function test_dst_windows_use_absolute_elapsed_time(): void
    {
        CarbonImmutable::setTestNow('2026-01-01T00:00:00Z');
        config()->set('movie.booking_horizon_days', 365);
        $service = app(ReservationService::class);
        $user = User::factory()->create();

        $spring = $service->create(
            $user,
            CarbonImmutable::parse('2026-03-07T22:00:00-08:00'),
            CarbonImmutable::parse('2026-03-08T06:00:00-07:00'),
        );
        $this->assertSame(7 * 3600, (int) $spring->starts_at->diffInRealSeconds($spring->ends_at));

        $this->expectException(ValidationException::class);
        $service->create(
            $user,
            CarbonImmutable::parse('2026-10-31T22:00:00-07:00'),
            CarbonImmutable::parse('2026-11-01T06:00:00-08:00'),
        );
    }

    public function test_early_provisioning_does_not_start_no_show_clock_early(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T00:00:00Z');
        $reservation = $this->reservation(User::factory()->create(), '2026-08-24T17:00:00Z', '2026-08-24T18:00:00Z', ReservationStatus::Provisioning, '2026-08-24T16:57:00Z');
        $service = app(ReservationService::class);

        $this->assertSame(0, $service->markNoShows(CarbonImmutable::parse('2026-08-24T17:14:00Z')));
        $this->assertSame(ReservationStatus::Provisioning, $reservation->refresh()->status);
        $this->assertSame(1, $service->markNoShows(CarbonImmutable::parse('2026-08-24T17:16:00Z')));
        $this->assertSame(ReservationStatus::Ending, $reservation->refresh()->status);
        $this->assertSame('no_show', $reservation->end_reason);
    }

    public function test_audit_table_is_immutable_and_required_indexes_exist(): void
    {
        $eventId = DB::table('audit_events')->insertGetId(['action' => 'test', 'metadata' => '{}', 'created_at' => now()]);
        $constraints = DB::table('pg_constraint')->whereIn('conname', ['reservations_no_overlap', 'reservations_duration_check', 'reservations_ends_on_hour'])->pluck('conname');
        $indexes = DB::table('pg_indexes')->where('tablename', 'reservations')->pluck('indexname');

        $this->assertCount(3, $constraints);
        $this->assertContains('reservations_one_runtime_owner', $indexes);
        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $eventId)->update(['action' => 'tampered']);
    }

    private function reservation(User $user, string $startsAt, string $endsAt, ReservationStatus $status, ?string $activatedAt = null): Reservation
    {
        $start = CarbonImmutable::parse($startsAt);
        $end = CarbonImmutable::parse($endsAt);

        return Reservation::create([
            'user_id' => $user->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'lock_starts_at' => $start,
            'lock_ends_at' => $end,
            'status' => $status,
            'activated_at' => $activatedAt ? CarbonImmutable::parse($activatedAt) : null,
        ]);
    }

    private function newPdo(): PDO
    {
        $host = (string) config('database.connections.pgsql.host');
        $port = (string) config('database.connections.pgsql.port');
        $database = (string) config('database.connections.pgsql.database');
        $user = (string) config('database.connections.pgsql.username');
        $password = (string) config('database.connections.pgsql.password');

        return new PDO("pgsql:host={$host};port={$port};dbname={$database}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function insertRawReservation(PDO $pdo, string $id, string $userId): void
    {
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO reservations
              (id, user_id, starts_at, ends_at, lock_starts_at, lock_ends_at, status, created_at, updated_at)
            VALUES
              (:id, :user_id, '2026-08-24T17:00:00Z', '2026-08-24T18:00:00Z',
               '2026-08-24T17:00:00Z', '2026-08-24T18:00:00Z', 'confirmed', now(), now())
            SQL);
        $statement->execute(['id' => $id, 'user_id' => $userId]);
    }
}
