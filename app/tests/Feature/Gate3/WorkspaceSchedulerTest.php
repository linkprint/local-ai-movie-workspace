<?php

namespace Tests\Feature\Gate3;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class WorkspaceSchedulerTest extends TestCase
{
    public function test_workspace_reconcile_mutex_expires_within_one_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->firstWhere('description', 'movie-workspace-reconcile');

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1, $event->expiresAt);
    }

    public function test_reservation_no_show_cleanup_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->firstWhere('description', 'movie-reservation-no-shows');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);
    }
}
