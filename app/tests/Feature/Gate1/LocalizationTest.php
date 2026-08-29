<?php

namespace Tests\Feature\Gate1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_guest_can_switch_to_chinese_and_the_choice_persists_in_session(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Company sign in')
            ->assertSee('lang="en"', false)
            ->assertSee('data-portal-translations=', false)
            ->assertDontSee('window.portalTranslations', false);

        $this->from('/login')->post(route('locale.update'), ['locale' => 'zh_CN'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'zh_CN')
            ->assertCookie('locale', 'zh_CN');

        $this->get('/login')
            ->assertOk()
            ->assertSee('公司账号登录')
            ->assertSee('lang="zh-CN"', false);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->from('/login')->post(route('locale.update'), ['locale' => 'fr'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('locale')
            ->assertSessionMissing('locale');
    }

    public function test_authenticated_portal_and_admin_use_the_selected_language(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->withSession(['locale' => 'zh_CN'])->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('控制台')
            ->assertSee('工作区');

        $this->withSession(['locale' => 'zh_CN'])->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('门户首页')
            ->assertSee('用户');
    }

    public function test_english_and_chinese_ui_packs_have_identical_keys(): void
    {
        $english = require lang_path('en/ui.php');
        $chinese = require lang_path('zh_CN/ui.php');

        $this->assertSame($this->leafKeys($english), $this->leafKeys($chinese));
        $this->assertSame(
            '终端会先检查你的独立登录状态：已登录会直接进入 Codex；首次使用会自动启动设备码登录并在完成后进入 Codex。',
            $chinese['workspace']['personal_runtime_help'],
        );
        $this->assertStringNotContainsString('~/.codex/auth.json', $chinese['workspace']['personal_runtime_help']);
    }

    /** @return array<int, string> */
    private function leafKeys(array $messages, string $prefix = ''): array
    {
        $keys = [];

        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                array_push($keys, ...$this->leafKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        sort($keys);

        return $keys;
    }
}
