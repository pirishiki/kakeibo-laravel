<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Recurring;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 設定（画面設計書 5.12）の第 1・第 2 段階の項目を確かめる
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
        // 最初の分類は支出 15・収入 4
        $expense = fn (string $name) => Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', $name)->firstOrFail();
        // 小分類を足す（数える）・「使わない」小分類（数えない）
        Category::factory()->create(['name' => '外食', 'kind' => CategoryKind::Expense, 'parent_id' => $expense('食費')->id]);
        Category::factory()->create(['name' => '古い小分類', 'kind' => CategoryKind::Expense, 'parent_id' => $expense('食費')->id, 'is_active' => false]);
        // 「使わない」大分類と、その下の「使う」小分類（どちらも数えない。記録の登録で選べないため）
        $car = $expense('自動車');
        Category::factory()->create(['name' => 'ガソリン', 'kind' => CategoryKind::Expense, 'parent_id' => $car->id]);
        $car->update(['is_active' => false]);
        Category::query()->where('kind', CategoryKind::Income)->where('name', '賞与')->update(['is_active' => false]);

        $response = $this->get('/settings');

        $response->assertSee('銀行口座・現金・交通系 IC・カード（2 件）');
        // 支出 15 ＋ 外食 − 自動車 ＝ 15、収入 4 − 賞与 ＝ 3
        $response->assertSee('食費・日用品など（支出 15・収入 3）');
        // 相手は、足した 3 件と、最初からある「その他」
        $response->assertSee('お店・会社など（4 件）');
    }

    public function test_口座がまだないときは0件と出る(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings')->assertSee('銀行口座・現金・交通系 IC・カード（0 件）');
    }

    public function test_ログイン中のメールアドレスが出て_ログアウトはフォームで送る(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'me@example.com']));

        $this->get('/settings')
            ->assertSee('me@example.com でログイン中')
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_第3段階の画面はまだ出さない(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings')
            ->assertDontSee('予算')
            ->assertDontSee('貯金の目標');
    }

    public function test_定期的な記録は毎月の予定と目標に出て_使うで終わっていないものだけ数える(): void
    {
        $this->actingAs(User::factory()->create());
        Recurring::factory()->count(2)->create();
        Recurring::factory()->create(['is_active' => false]);
        // 先月で終わったもの
        Recurring::factory()->create(['start_month' => today()->subMonths(3)->startOfMonth(), 'end_month' => today()->subMonth()->startOfMonth()]);

        $this->get('/settings')
            ->assertSeeInOrder(['お金の入れ物と分け方', '相手', '毎月の予定と目標', '定期的な記録', 'ログイン'])
            ->assertSee('給料・家賃・ローンなど（2 件）')
            ->assertSee(route('recurrings.index'), false);
    }
}
