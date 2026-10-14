<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 設定（画面設計書 5.12）の第 1 段階の項目を確かめる
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ログインしないと設定は開けない(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_口座_分類_相手の順にリンクが並ぶ(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings')->assertOk()
            ->assertSeeInOrder(['お金の入れ物と分け方', '口座', '分類', '相手', 'ログイン', 'ログアウト'])
            ->assertSeeInOrder([route('accounts.index'), route('categories.index'), route('partners.index')], false);
    }

    public function test_件数は使うものだけを数え_分類は支出と収入に分けて数える(): void
    {
        $this->actingAs(User::factory()->create());
        Account::factory()->count(2)->create();
        Account::factory()->create(['is_active' => false]);
        Partner::factory()->count(3)->create();
        Partner::factory()->create(['is_active' => false]);
        // 最初の分類は支出 15・収入 4。小分類を 1 つ足し、大分類を 1 つ「使わない」にする
        $food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        Category::factory()->create(['name' => '外食', 'kind' => CategoryKind::Expense, 'parent_id' => $food->id]);
        Category::query()->where('kind', CategoryKind::Income)->where('name', '賞与')->update(['is_active' => false]);

        $response = $this->get('/settings');

        $response->assertSee('銀行口座・現金・交通系 IC・カード（2 件）');
        $response->assertSee('食費・日用品など（支出 16・収入 3）');
        // 相手は「その他」が最初からある（相手の表を作ったときに入る）
        $partners = Partner::query()->where('is_active', true)->count();
        $response->assertSee('お店・会社など（'.$partners.' 件）');
    }

    public function test_ログイン中のメールアドレスが出て_ログアウトはフォームで送る(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'me@example.com']));

        $this->get('/settings')
            ->assertSee('me@example.com でログイン中')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false);

        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_第2段階と第3段階の画面はまだ出さない(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings')
            ->assertDontSee('毎月の予定と目標')
            ->assertDontSee('定期的な記録')
            ->assertDontSee('予算')
            ->assertDontSee('貯金の目標');
    }
}
