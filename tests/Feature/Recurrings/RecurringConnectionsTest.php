<?php

namespace Tests\Feature\Recurrings;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Recurring;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 要件定義書 10.2「ほかの画面とのつながり」と「定期的な記録の登録・変更」の削除の項目（画面設計書 3.1・4 章の 9・5.9・5.12）
 */
class RecurringConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Category $food;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行']);
        $this->food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        $this->shop = Partner::factory()->create(['name' => '生協']);
    }

    /** 口座・分類・相手を使う支出の予定 */
    private function delivery(array $attributes = []): Recurring
    {
        return Recurring::factory()->create([
            'name' => '生協の宅配', 'type' => RecordType::Expense, 'account_id' => $this->bank->id, 'to_account_id' => null,
            'category_id' => $this->food->id, 'partner_id' => $this->shop->id, ...$attributes,
        ]);
    }

    public function test_メニューに定期的な記録が出る(): void
    {
        $this->get(route('home'))->assertSee(route('recurrings.index'), false)->assertSee('定期的な記録');
    }

    public function test_記録がなくても_定期的な記録が使っている口座は削除できず_使っている予定とリンクが出る(): void
    {
        $recurring = $this->delivery();

        $this->get(route('accounts.edit', $this->bank))
            ->assertSee('定期的な記録が使っているので、削除できません。')
            ->assertSee(route('recurrings.edit', $recurring), false)
            ->assertSee('生協の宅配');

        $this->delete(route('accounts.destroy', $this->bank))->assertRedirect(route('accounts.edit', $this->bank));
        $this->assertTrue(Account::query()->whereKey($this->bank->id)->exists());
        $this->get(route('accounts.edit', $this->bank))->assertSee(route('recurrings.edit', $recurring), false);
    }

    public function test_使うで終わっていない予定が使っている口座は使わないにできない(): void
    {
        $this->delivery();

        $this->patch(route('accounts.deactivate', $this->bank))->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        $this->put(route('accounts.update', $this->bank), ['name' => 'みずほ銀行', 'type' => 'bank', 'initial_balance' => 0, 'is_active' => '0'])
            ->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        $this->assertTrue($this->bank->refresh()->is_active);
    }

    public function test_終わった予定と使わない予定だけが使っている口座は使わないにできる(): void
    {
        $this->delivery(['is_active' => false]);
        $this->delivery(['start_month' => '2026-01-01', 'end_month' => '2026-09-01']);

        $this->patch(route('accounts.deactivate', $this->bank))->assertSessionHasNoErrors();
        $this->assertFalse($this->bank->refresh()->is_active);
    }

    public function test_今月で終わる予定は_まだ終わっていない(): void
    {
        $this->delivery(['end_month' => '2026-10-01']);

        $this->patch(route('accounts.deactivate', $this->bank))->assertSessionHasErrors('is_active');
    }

    public function test_移動のどこへに使われている口座も削除できない(): void
    {
        $card = Account::factory()->create();
        Recurring::factory()->create(['account_id' => $this->bank->id, 'to_account_id' => $card->id]);

        $this->delete(route('accounts.destroy', $card))->assertSessionHasErrors(['delete' => Recurring::DELETE_BLOCKED_MESSAGE]);
    }

    public function test_定期的な記録が使っている分類は削除できず_使わないにもできない(): void
    {
        $sub = Category::factory()->childOf($this->food)->create(['name' => '宅配']);
        $recurring = $this->delivery(['category_id' => $sub->id]);

        $this->delete(route('categories.destroy', $sub))->assertSessionHasErrors(['delete' => Recurring::DELETE_BLOCKED_MESSAGE]);
        $this->get(route('categories.edit', $sub))->assertSee(route('recurrings.edit', $recurring), false);

        $this->patch(route('categories.deactivate', $sub))->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        // 小分類を使っているときは、その上の大分類も「使わない」にできない（大分類を外すと、小分類も選べなくなるため）
        $this->patch(route('categories.deactivate', $this->food))->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        $this->put(route('categories.update', $this->food), ['name' => '食費', 'is_active' => '0'])
            ->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        $this->assertTrue($this->food->refresh()->is_active);

        $recurring->update(['is_active' => false]);
        $this->patch(route('categories.deactivate', $this->food))->assertSessionHasNoErrors();
    }

    public function test_定期的な記録が使っている相手は削除できず_使わないにもできない(): void
    {
        $recurring = $this->delivery();

        $this->delete(route('partners.destroy', $this->shop))->assertSessionHasErrors(['delete' => Recurring::DELETE_BLOCKED_MESSAGE]);
        $this->get(route('partners.edit', $this->shop))
            ->assertSee('定期的な記録が使っているので、削除できません。')
            ->assertSee(route('recurrings.edit', $recurring), false);

        $this->patch(route('partners.deactivate', $this->shop))->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        $this->put(route('partners.update', $this->shop), ['name' => '生協', 'is_active' => '0'])
            ->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        $this->assertTrue($this->shop->refresh()->is_active);
    }

    public function test_使わないの相手は_名前だけ直して保存できる(): void
    {
        $this->delivery();
        $this->shop->update(['is_active' => false]);

        $this->put(route('partners.update', $this->shop), ['name' => '生協（宅配）', 'is_active' => '0'])->assertSessionHasNoErrors();
    }

    public function test_相手をまとめると_その相手の定期的な記録もまとめ先へ移る(): void
    {
        $recurring = $this->delivery();
        $target = Partner::factory()->create(['name' => 'コープ']);

        $this->get(route('partners.edit', $this->shop))->assertSee('定期的な記録 1 件');
        $this->post(route('partners.merge', $this->shop), ['merge_target' => 'コープ'])->assertRedirect(route('partners.index'));

        $this->assertSame($target->id, $recurring->refresh()->partner_id);
        $this->assertFalse(Partner::query()->whereKey($this->shop->id)->exists());
    }
}
