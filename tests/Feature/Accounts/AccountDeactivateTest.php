<?php

namespace Tests\Feature\Accounts;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 要件定義書 10.1「口座の管理・最初の残高（No.2・3、画面設計書 5.6）」のうち、削除と「使わない」の項目。
 */
class AccountDeactivateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function cash(int $initialBalance = 0): Account
    {
        return Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => $initialBalance]);
    }

    private function spend(Account $account, int $amount, ?string $date = null): Record
    {
        return Record::factory()->create([
            'account_id' => $account->id,
            'type' => RecordType::Expense,
            'amount' => $amount,
            'date' => $date ?? today()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(Account $account, bool $isActive): array
    {
        return [
            'name' => $account->name,
            'type' => $account->type->value,
            'initial_balance' => (string) $account->initial_balance,
            'is_active' => $isActive ? '1' : '0',
        ];
    }

    public function test_記録がない口座は確認の小窓のあと削除できる(): void
    {
        $cash = $this->cash(3_000);

        $this->get("/accounts/{$cash->id}/edit")->assertSee('「財布」を削除しますか？')->assertSee('削除する');
        $this->delete("/accounts/{$cash->id}")->assertRedirect('/accounts')->assertSessionHas('status', '削除しました');

        $this->assertModelMissing($cash);
    }

    public function test_記録がある口座は削除できず_使わないにするかを聞かれる(): void
    {
        $cash = $this->cash(1_000);
        $this->spend($cash, 1_000);

        $this->get("/accounts/{$cash->id}/edit")
            ->assertSee('記録があるので削除できません。『使わない』にしますか？')
            ->assertSee('「使わない」にする')
            ->assertDontSee('削除する');

        $this->delete("/accounts/{$cash->id}")
            ->assertRedirect("/accounts/{$cash->id}/edit")
            ->assertSessionHasErrors(['delete' => '記録があるので削除できません']);
        $this->assertModelExists($cash);

        $this->patch("/accounts/{$cash->id}/deactivate")->assertRedirect('/accounts');
        $this->assertFalse($cash->refresh()->is_active);
    }

    public function test_使わない口座は一覧のいちばん下に出て_使うに戻せる(): void
    {
        $old = Account::factory()->create(['name' => '旧カード', 'type' => AccountType::Bank]);
        $this->cash(8_500);

        $this->put("/accounts/{$old->id}", $this->form($old, false))->assertRedirect('/accounts');
        $this->assertFalse($old->refresh()->is_active);
        $this->get('/accounts')->assertSeeInOrder(['財布', '使わない口座', '旧カード']);

        $this->put("/accounts/{$old->id}", $this->form($old, true))->assertRedirect('/accounts');
        $this->assertTrue($old->refresh()->is_active);
        $this->get('/accounts')->assertDontSee('使わない口座');
    }

    public function test_残高が0円でない口座は使わないにできない(): void
    {
        $cash = $this->cash(5_000);
        $this->spend($cash, 2_000);
        $message = '残高が 3,000 円あるので『使わない』にできません。お金をほかの口座へ移す記録をつけるか、残高を合わせて 0 円にしてください';

        $this->put("/accounts/{$cash->id}", $this->form($cash, false))->assertSessionHasErrors(['is_active' => $message]);
        $this->assertTrue($cash->refresh()->is_active);

        // 削除から「使わない」にするときも同じ
        $this->get("/accounts/{$cash->id}/edit")->assertSee($message)->assertDontSee('「使わない」にする');
        $this->patch("/accounts/{$cash->id}/deactivate")->assertSessionHasErrors(['is_active' => $message]);
        $this->assertTrue($cash->refresh()->is_active);
    }

    public function test_マイナスの残高でも使わないにできない(): void
    {
        $card = Account::factory()->create(['type' => AccountType::CreditCard, 'initial_balance' => -48_300]);

        $this->put("/accounts/{$card->id}", $this->form($card, false))
            ->assertSessionHasErrors(['is_active' => '残高が -48,300 円あるので『使わない』にできません。お金をほかの口座へ移す記録をつけるか、残高を合わせて 0 円にしてください']);
    }

    public function test_今日の残高が0円でも先の日付の記録で残高が変わる口座は使わないにできない(): void
    {
        $cash = $this->cash(0);
        Record::factory()->create(['account_id' => $cash->id, 'type' => RecordType::Income, 'amount' => 5_000, 'date' => today()->addDays(10)]);

        $this->assertSame(0, $cash->balance());
        $this->put("/accounts/{$cash->id}", $this->form($cash, false))
            ->assertSessionHasErrors(['is_active' => '先の日付の記録で残高が変わるので、『使わない』にできません']);
        $this->patch("/accounts/{$cash->id}/deactivate")->assertSessionHasErrors('is_active');
        $this->assertTrue($cash->refresh()->is_active);
    }

    public function test_移動の先の日付の記録も数える(): void
    {
        $bank = Account::factory()->create(['initial_balance' => 10_000]);
        $cash = $this->cash(0);
        Record::factory()->create([
            'account_id' => $bank->id, 'to_account_id' => $cash->id,
            'type' => RecordType::Transfer, 'amount' => 3_000, 'date' => today()->addDay(),
        ]);

        $this->put("/accounts/{$cash->id}", $this->form($cash, false))->assertSessionHasErrors('is_active');
    }

    public function test_最初の残高を0円に直すのと同時なら使わないにできる(): void
    {
        $cash = $this->cash(3_000);

        $this->put("/accounts/{$cash->id}", ['initial_balance' => '0'] + $this->form($cash, false))->assertSessionHasNoErrors();

        $cash->refresh();
        $this->assertSame(0, $cash->initial_balance);
        $this->assertFalse($cash->is_active);
    }

    public function test_残高が0円になれば使わないにできる(): void
    {
        $cash = $this->cash(2_000);
        $this->spend($cash, 2_000);

        $this->put("/accounts/{$cash->id}", $this->form($cash, false))->assertRedirect('/accounts')->assertSessionHasNoErrors();
        $this->assertFalse($cash->refresh()->is_active);
    }

    public function test_使わない口座の最初の残高を0円でない額に直すことはできない(): void
    {
        $old = Account::factory()->create(['name' => '旧カード', 'is_active' => false]);

        $this->put("/accounts/{$old->id}", ['initial_balance' => '5000'] + $this->form($old, false))
            ->assertSessionHasErrors(['is_active' => '残高が 5,000 円あるので『使わない』にできません。お金をほかの口座へ移す記録をつけるか、残高を合わせて 0 円にしてください']);
        $this->assertSame(0, $old->refresh()->initial_balance);

        // 「使う」に戻すのと同時なら直せる
        $this->put("/accounts/{$old->id}", ['initial_balance' => '5000'] + $this->form($old, true))->assertSessionHasNoErrors();
        $this->assertSame(5_000, $old->refresh()->initial_balance);
    }

    public function test_使わないにできないときは小窓に理由と閉じるだけが出る(): void
    {
        $cash = $this->cash(1_000);
        $this->spend($cash, 300);

        $this->get("/accounts/{$cash->id}/edit")
            ->assertSee('記録があるので削除できません。')
            ->assertSee('残高が 700 円あるので『使わない』にできません')
            ->assertSee('閉じる')
            ->assertDontSee('「使わない」にする')
            ->assertDontSee('削除する');
    }

    public function test_記録がある使わない口座で削除を押すと_もう使わないになっていると出る(): void
    {
        $cash = $this->cash(1_000);
        $this->spend($cash, 1_000);
        $cash->update(['is_active' => false]);

        $this->get("/accounts/{$cash->id}/edit")
            ->assertSee('この口座は、もう「使わない」になっています。')
            ->assertDontSee('「使わない」にする');
    }

    public function test_先の日付の記録があるときは今日の残高が0円でなくても先の日付の文を出す(): void
    {
        $cash = $this->cash(3_000);
        $this->spend($cash, 3_000, today()->addDays(2)->toDateString());

        $this->put("/accounts/{$cash->id}", $this->form($cash, false))
            ->assertSessionHasErrors(['is_active' => '先の日付の記録で残高が変わるので、『使わない』にできません']);
    }
}
