<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Enums\ReserveType;
use App\Models\Account;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 要件定義書 10.2「予定の表示」「使える額」のうち、画面に出るところと口座の取っておく額の欄（画面設計書 5.2・5.6）。
 * 計算の決まりは AvailableAmountSpecTest（設計書だけから作ったテスト）で確かめる
 */
class AvailableAmountScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 2026年10月14日（水）。今月の残りは 18 日
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge(['name' => '貯金', 'type' => 'bank', 'initial_balance' => '0', 'reserve_type' => 'none', 'reserve_amount' => ''], $overrides);
    }

    public function test_口座に取っておく額を金額で決められ_一覧に小さく出る(): void
    {
        $this->post('/accounts', $this->form(['reserve_type' => 'amount', 'reserve_amount' => '２００，０００']))->assertRedirect('/accounts');

        $account = Account::query()->sole();
        $this->assertSame(ReserveType::Amount, $account->reserve_type);
        $this->assertSame(200000, $account->reserve_amount);
        $this->get('/accounts')->assertSee('取っておく額 200,000');
    }

    public function test_全部取っておくを選ぶと_金額は持たない(): void
    {
        $this->post('/accounts', $this->form(['reserve_type' => 'all', 'reserve_amount' => '5000']));

        $account = Account::query()->sole();
        $this->assertSame(ReserveType::All, $account->reserve_type);
        $this->assertNull($account->reserve_amount);
        $this->get('/accounts')->assertSee('全部取っておく');
    }

    public function test_金額を選んで金額が空や0円だと_まちがいが出る(): void
    {
        $this->post('/accounts', $this->form(['reserve_type' => 'amount', 'reserve_amount' => '']))
            ->assertSessionHasErrors(['reserve_amount' => '「金額」を選んだときは、取っておく金額を入れてください。']);
        $this->post('/accounts', $this->form(['reserve_type' => 'amount', 'reserve_amount' => '0']))
            ->assertSessionHasErrors(['reserve_amount' => '取っておく金額は 1〜99,999,999 円で入れてください。']);
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_クレジットカードに変えて保存すると_取っておく額はなしに戻る(): void
    {
        $account = Account::factory()->create(['name' => 'カードA', 'reserve_type' => ReserveType::Amount, 'reserve_amount' => 1000]);

        $this->put('/accounts/'.$account->id, $this->form(['name' => 'カードA', 'type' => 'credit_card', 'reserve_type' => 'amount', 'reserve_amount' => '1000', 'is_active' => '1']))
            ->assertRedirect('/accounts');

        $account->refresh();
        $this->assertSame(ReserveType::None, $account->reserve_type);
        $this->assertNull($account->reserve_amount);
    }

    public function test_取っておく額の欄がない古い画面から保存しても_取っておく額は変わらない(): void
    {
        $account = Account::factory()->create(['name' => '貯金', 'reserve_type' => ReserveType::All]);

        $this->put('/accounts/'.$account->id, ['name' => '貯金口座', 'type' => 'bank', 'initial_balance' => '0', 'is_active' => '1'])
            ->assertRedirect('/accounts');

        $account->refresh();
        $this->assertSame('貯金口座', $account->name);
        $this->assertSame(ReserveType::All, $account->reserve_type);
    }

    public function test_ホームに使える額と内訳と1日あたりが出る(): void
    {
        $bank = Account::factory()->create(['name' => 'みどり銀行', 'initial_balance' => 300000, 'reserve_type' => ReserveType::Amount, 'reserve_amount' => 100000]);
        Record::factory()->create(['type' => RecordType::Expense, 'amount' => 50000, 'account_id' => $bank->id, 'date' => '2026-10-20']);

        // 300,000 − 100,000 − 50,000 ＝ 150,000。1 日あたり 150,000 ÷ 18 ＝ 8,333、1 週間あたり 58,331
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['使える額', '150,000', '1日あたり', '8,333', '1週間', '58,331'])
            ->assertSeeInOrder(['今の合計', '300,000', '− 取っておく額', '100,000', '− 今月末までの予定（1 件）', '50,000'])
            ->assertSee('取っておく額 100,000');
    }

    public function test_月末まで7日ないときは_あと何日と出る(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        Account::factory()->create(['initial_balance' => 4000]);

        // 残りは 28〜31 日の 4 日。1 日あたり 1,000、あと4日 4,000
        $this->get('/')->assertSeeInOrder(['1日あたり', '1,000', 'あと4日', '4,000']);
    }

    public function test_下回っている口座のお知らせが出て_押すと口座の管理へ(): void
    {
        Account::factory()->create(['name' => 'みどり銀行', 'initial_balance' => 30000, 'reserve_type' => ReserveType::Amount, 'reserve_amount' => 50000]);
        Account::factory()->create(['name' => '貯金', 'initial_balance' => -100, 'reserve_type' => ReserveType::All]);

        $this->get('/')
            ->assertSee('お知らせ')
            ->assertSee('みどり銀行 が取っておく額を下回っています')
            ->assertSee(route('accounts.index'))
            ->assertDontSee('貯金 が取っておく額を下回っています');
    }

    public function test_カレンダーに_まだ記録されていない予定が灰色で出る(): void
    {
        $bank = Account::factory()->create(['initial_balance' => 500000]);
        // 毎月 25 日・決まった金額 70,000 の支出（10 月 25 日は日曜 → 次の平日の 26 日）
        Recurring::factory()->create([
            'name' => '家賃', 'type' => RecordType::Expense, 'day' => 25, 'amount' => 70000,
            'account_id' => $bank->id, 'to_account_id' => null, 'start_month' => '2026-10-01',
        ]);

        $this->get('/')
            ->assertSee('灰色＝これからの予定')
            ->assertSeeInOrder(['10月26日', '支出の予定', '70,000']);
    }

    public function test_クレジットカードの口座には_取っておく額が出ない(): void
    {
        Account::factory()->create(['name' => 'カードA', 'type' => AccountType::CreditCard, 'reserve_type' => ReserveType::All]);

        $this->get('/')->assertDontSee('全部取っておく');
    }
}
