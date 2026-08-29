<?php

namespace Tests\Unit;

use App\Support\UiTranslationGuard;
use PHPUnit\Framework\TestCase;

class UiTranslationGuardTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/movie-ui-translation-guard-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot.'/lang/en', 0770, true);
        mkdir($this->fixtureRoot.'/lang/zh_CN', 0770, true);
        mkdir($this->fixtureRoot.'/views', 0770, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_repository_translations_match_the_canonical_baseline_and_blade_references(): void
    {
        $applicationRoot = dirname(__DIR__, 2);

        $errors = (new UiTranslationGuard)->validate(
            $applicationRoot.'/lang',
            $applicationRoot.'/resources/views',
            $applicationRoot.'/lang/ui-required-keys.txt',
        );

        $this->assertSame([], $errors);
    }

    public function test_missing_required_key_and_unregistered_key_are_rejected(): void
    {
        $this->writeTranslations('en', ['recovery' => ['title' => 'Private recovery area']]);
        $this->writeTranslations('zh_CN', ['recovery' => ['intro' => '恢复区说明']]);
        $this->writeBaseline(['ui.recovery.intro', 'ui.recovery.title']);

        $errors = $this->guard()->validate(...$this->paths());

        $this->assertContains('en is missing required key ui.recovery.intro', $errors);
        $this->assertContains('zh_CN is missing required key ui.recovery.title', $errors);
    }

    public function test_stale_complete_language_bundle_is_rejected_by_canonical_baseline(): void
    {
        $staleTranslations = ['workspace' => ['title' => 'Workspace']];
        $this->writeTranslations('en', $staleTranslations);
        $this->writeTranslations('zh_CN', $staleTranslations);
        $this->writeBaseline(['ui.recovery.title', 'ui.workspace.title']);

        $errors = $this->guard()->validate(...$this->paths());

        $this->assertContains('en is missing required key ui.recovery.title', $errors);
        $this->assertContains('zh_CN is missing required key ui.recovery.title', $errors);
    }

    public function test_unregistered_key_requires_an_explicit_baseline_update(): void
    {
        $translations = ['recovery' => ['intro' => 'Intro', 'title' => 'Title']];
        $this->writeTranslations('en', $translations);
        $this->writeTranslations('zh_CN', $translations);
        $this->writeBaseline(['ui.recovery.title']);

        $errors = $this->guard()->validate(...$this->paths());

        $this->assertContains(
            'en has unregistered key ui.recovery.intro; update the canonical baseline explicitly',
            $errors,
        );
        $this->assertContains(
            'zh_CN has unregistered key ui.recovery.intro; update the canonical baseline explicitly',
            $errors,
        );
    }

    public function test_static_blade_reference_must_exist_in_both_languages(): void
    {
        $translations = ['recovery' => ['title' => 'Title']];
        $this->writeTranslations('en', $translations);
        $this->writeTranslations('zh_CN', $translations);
        $this->writeBaseline(['ui.recovery.title']);
        file_put_contents(
            $this->fixtureRoot.'/views/recovery.blade.php',
            "{{ __('ui.recovery.intro') }}\n{{ __('ui.statuses.'.\$status) }}\n",
        );

        $errors = $this->guard()->validate(...$this->paths());

        $this->assertContains(
            'Blade reference ui.recovery.intro at recovery.blade.php:1 is missing from en and zh_CN',
            $errors,
        );
        $this->assertContains(
            'Blade reference ui.statuses at recovery.blade.php:2 is missing from en and zh_CN',
            $errors,
        );
    }

    public function test_dynamic_blade_key_prefix_is_valid_when_the_group_exists_in_both_languages(): void
    {
        $translations = ['statuses' => ['active' => 'Active']];
        $this->writeTranslations('en', $translations);
        $this->writeTranslations('zh_CN', $translations);
        $this->writeBaseline(['ui.statuses.active']);
        file_put_contents(
            $this->fixtureRoot.'/views/status.blade.php',
            "{{ __('ui.statuses.'.\$status) }}\n",
        );

        $errors = $this->guard()->validate(...$this->paths());

        $this->assertSame([], $errors);
    }

    public function test_baseline_must_be_sorted_and_unique(): void
    {
        $translations = ['recovery' => ['intro' => 'Intro', 'title' => 'Title']];
        $this->writeTranslations('en', $translations);
        $this->writeTranslations('zh_CN', $translations);
        file_put_contents(
            $this->fixtureRoot.'/lang/ui-required-keys.txt',
            "ui.recovery.title\nui.recovery.intro\nui.recovery.title\n",
        );

        $errors = $this->guard()->validate(...$this->paths());

        $this->assertContains(
            'canonical baseline must be sorted: '.$this->fixtureRoot.'/lang/ui-required-keys.txt',
            $errors,
        );
        $this->assertContains(
            'duplicate canonical key at '.$this->fixtureRoot.'/lang/ui-required-keys.txt:3: ui.recovery.title',
            $errors,
        );
    }

    private function guard(): UiTranslationGuard
    {
        return new UiTranslationGuard;
    }

    /** @return array{string, string, string} */
    private function paths(): array
    {
        return [
            $this->fixtureRoot.'/lang',
            $this->fixtureRoot.'/views',
            $this->fixtureRoot.'/lang/ui-required-keys.txt',
        ];
    }

    /** @param array<string, mixed> $translations */
    private function writeTranslations(string $locale, array $translations): void
    {
        file_put_contents(
            $this->fixtureRoot.'/lang/'.$locale.'/ui.php',
            "<?php\n\nreturn ".var_export($translations, true).";\n",
        );
    }

    /** @param list<string> $keys */
    private function writeBaseline(array $keys): void
    {
        file_put_contents(
            $this->fixtureRoot.'/lang/ui-required-keys.txt',
            implode("\n", $keys)."\n",
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $path.'/'.$item;
            if (is_dir($itemPath) && ! is_link($itemPath)) {
                $this->deleteDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
