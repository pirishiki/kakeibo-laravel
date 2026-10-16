<?php

namespace Tests\Feature\Goals;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 貯金の目標の決まり（要件定義書 5.4・10.3、画面設計書 4章・5.2・5.6・5.13、データベース設計書 3.10）を、設計書だけを読んで確かめる。
 * 実装は見ずに、画面に出る文と保存された行で確かめる
 */
class GoalSpecTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 2026年10月24日（土）
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    }

    private function account(string $name, int $balance = 0, AccountType $type = AccountType::Bank, bool $active = true): Account
    {
        return Account::factory()->create(['name' => $name, 'type' => $type, 'initial_balance' => $balance, 'is_active' => $active]);
    }

    private function goal(string $name, Account $account, int $target = 300000, ?string $deadline = null): Goal
    {
        return Goal::create(['name' => $name, 'target_amount' => $target, 'account_id' => $account->id, 'deadline_month' => $deadline]);
    }

    /**
     * @param  array<string, mixed>  $override
     */
    private function store(Account $account, array $override = []): TestResponse
    {
        return $this->from('/goals/create')->post('/goals', array_merge([
            'name' => '旅行',
            'target_amount' => '300,000',
            'account_id' => $account->id,
            'deadline_year' => '',
            'deadline_month' => '',
        ], $override));
    }

    private function accountSelect(TestResponse $response): string
    {
        preg_match('/<select[^>]*name="account_id".*?<\/select>/s', $response->getContent(), $m);

        return $m[0] ?? '';
    }

    public function test_名前も目標額も口座も正しければ追加できる(): void
    {
        $a = $this->account('貯金用A');

        $this->store($a, ['deadline_year' => '2027', 'deadline_month' => '8'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('goals', ['name' => '旅行', 'target_amount' => 300000, 'account_id' => $a->id, 'deadline_month' => '2027-08-01']);
    }

    public function test_期限は空のままなら期限なしで保存される(): void
    {
        $a = $this->account('貯金用A');

        $this->store($a)->assertSessionHasNoErrors();

        $this->assertNull(Goal::query()->firstOrFail()->deadline_month);
    }

    public function test_名前は必須で50文字まで(): void
    {
        $a = $this->account('貯金用A');

        $this->store($a, ['name' => ''])->assertSessionHasErrors('name');
        $this->store($a, ['name' => str_repeat('あ', 51)])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('goals', 0);

        $this->store($a, ['name' => str_repeat('あ', 50)])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('goals', 1);
    }

    public function test_目標額は1円以上の整数だけ(): void
    {
        $a = $this->account('貯金用A');

        foreach (['', '0', '-1', '1.5', 'abc'] as $bad) {
            $this->store($a, ['target_amount' => $bad])->assertSessionHasErrors('target_amount');
        }
        $this->assertDatabaseCount('goals', 0);

        $this->store($a, ['target_amount' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('goals', ['target_amount' => 1]);
    }

    public function test_口座は必須(): void
    {
        $this->store($this->account('貯金用A'), ['account_id' => ''])->assertSessionHasErrors('account_id');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_ほかの目標が使っている口座は選べず送っても断られる(): void
    {
        $used = $this->account('使用中の口座');
        $this->account('空いている口座');
        $this->goal('車', $used);

        $select = $this->accountSelect($this->get('/goals/create')->assertOk());
        $this->assertStringContainsString('空いている口座', $select);
        $this->assertStringNotContainsString('使用中の口座', $select);

        $this->store($used)->assertSessionHasErrors('account_id');
        $this->assertDatabaseCount('goals', 1);
    }

    public function test_クレジットカードは選べず送っても断られる(): void
    {
        $card = $this->account('カードX', 0, AccountType::CreditCard);
        $this->account('普通口座');

        $select = $this->accountSelect($this->get('/goals/create')->assertOk());
        $this->assertStringContainsString('普通口座', $select);
        $this->assertStringNotContainsString('カードX', $select);

        $this->store($card)->assertSessionHasErrors('account_id');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_使わない口座は選べず送っても断られる(): void
    {
        $off = $this->account('休み口座', 0, AccountType::Bank, false);
        $this->account('普通口座');

        $select = $this->accountSelect($this->get('/goals/create')->assertOk());
        $this->assertStringContainsString('普通口座', $select);
        $this->assertStringNotContainsString('休み口座', $select);

        $this->store($off)->assertSessionHasErrors('account_id');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_変更の画面では自分の口座は選べる(): void
    {
        $mine = $this->account('自分の口座');
        $goal = $this->goal('旅行', $mine);

        $select = $this->accountSelect($this->get("/goals/{$goal->id}/edit")->assertOk());
        $this->assertStringContainsString('自分の口座', $select);
    }

    public function test_変更でほかの目標が使う口座には変えられない(): void
    {
        $a = $this->account('A口座');
        $b = $this->account('B口座');
        $goalA = $this->goal('旅行', $a);
        $this->goal('車', $b);

        $this->from("/goals/{$goalA->id}/edit")->put("/goals/{$goalA->id}", [
            'name' => '旅行', 'target_amount' => '300000', 'account_id' => $b->id, 'deadline_year' => '', 'deadline_month' => '',
        ])->assertSessionHasErrors('account_id');

        $this->assertSame($a->id, $goalA->fresh()->account_id);
    }

    public function test_パーセントは1パーセント未満を切り捨てる(): void
    {
        $a = $this->account('貯金用A', 299000);
        $this->goal('旅行', $a);

        $this->get('/goals')->assertOk()->assertSee('99%')->assertDontSee('100%');
    }

    public function test_100パーセントと出るのは達成したときだけ(): void
    {
        $a = $this->account('貯金用A', 299999);
        $this->goal('旅行', $a);
        $this->get('/goals')->assertOk()->assertDontSee('100%')->assertDontSee('✓ 達成');

        $a->update(['initial_balance' => 300000]);
        $this->get('/goals')->assertOk()->assertSee('✓ 達成');
    }

    public function test_残高がマイナスなら0パーセント(): void
    {
        $a = $this->account('貯金用A', -5000);
        $this->goal('旅行', $a);

        $this->get('/goals')->assertOk()->assertSee('（0%）', false)->assertDontSee('-1%')->assertDontSee('-5,000 /');
    }

    public function test_口座に記録をつけると進み具合が変わる_先の日付は数えない(): void
    {
        $a = $this->account('貯金用A', 0);
        $b = $this->account('元の口座', 50000);
        $this->goal('旅行', $a);
        $this->get('/goals')->assertSee('（0%）', false);

        Record::factory()->create(['type' => RecordType::Income, 'amount' => 30000, 'account_id' => $a->id, 'date' => '2026-10-20']);
        Record::factory()->create(['type' => RecordType::Transfer, 'amount' => 60000, 'account_id' => $b->id, 'to_account_id' => $a->id, 'date' => '2026-10-24']);
        Record::factory()->create(['type' => RecordType::Expense, 'amount' => 30000, 'account_id' => $a->id, 'date' => '2026-10-24']);
        Record::factory()->create(['type' => RecordType::Income, 'amount' => 100000, 'account_id' => $a->id, 'date' => '2026-10-25']);

        // 30,000 + 60,000 - 30,000 = 60,000 → 20%（明日の収入は数えない）
        $this->get('/goals')->assertOk()->assertSee('60,000')->assertSee('（20%）', false)->assertDontSee('160,000');
    }

    public function test_一覧は期限の近い順で期限なしはその下に追加した順(): void
    {
        $this->goal('期限なし先', $this->account('口座1'), 100000);
        $this->goal('遠い期限', $this->account('口座2'), 100000, '2027-12-01');
        $this->goal('期限なし後', $this->account('口座3'), 100000);
        $this->goal('近い期限', $this->account('口座4'), 100000, '2026-12-01');

        $this->get('/goals')->assertOk()->assertSeeInOrder(['近い期限', '遠い期限', '期限なし先', '期限なし後']);
    }

    public function test_あと何か月_1か月あたりは今月をふくんで切り上げる(): void
    {
        // 2026年10月〜2027年8月＝11 か月。180,000 ÷ 11 ＝ 16,363.6… → 16,364
        $this->goal('旅行', $this->account('貯金用A', 120000), 300000, '2027-08-01');

        $this->get('/goals')->assertOk()->assertSee('2027年8月')->assertSee('あと 11 か月')->assertSee('1 か月あたり 16,364');
    }

    public function test_1か月あたりの1円未満は切り上げ(): void
    {
        // 10月〜12月＝3 か月。100,000 ÷ 3 ＝ 33,333.3… → 33,334
        $this->goal('旅行', $this->account('貯金用A', 0), 100000, '2026-12-01');

        $this->get('/goals')->assertOk()->assertSee('あと 3 か月')->assertSee('1 か月あたり 33,334');
    }

    public function test_期限が今月なら残りの額そのもの(): void
    {
        $this->goal('旅行', $this->account('貯金用A', 20000), 200000, '2026-10-01');

        $this->get('/goals')->assertOk()->assertSee('あと 1 か月')->assertSee('1 か月あたり 180,000')->assertDontSee('期限を過ぎました');
    }

    public function test_期限の月が終わって未達成なら期限を過ぎましたと出て目標は残る(): void
    {
        $this->goal('家電', $this->account('貯金用A', 80000), 150000, '2026-09-01');

        $this->get('/goals')->assertOk()->assertSee('家電')->assertSee('期限を過ぎました');
        $this->assertDatabaseCount('goals', 1);
    }

    public function test_達成していれば期限を過ぎていても期限切れとは出ない(): void
    {
        $this->goal('家電', $this->account('貯金用A', 150000), 150000, '2026-09-01');

        $this->get('/goals')->assertOk()->assertDontSee('期限を過ぎました')->assertSee('✓ 達成');
    }

    public function test_期限を過ぎた目標は期限を変えずに名前と目標額を直して保存できる(): void
    {
        $goal = $this->goal('家電', $this->account('貯金用A', 80000), 150000, '2026-09-01');

        $this->from("/goals/{$goal->id}/edit")->put("/goals/{$goal->id}", [
            'name' => '新しい家電', 'target_amount' => '200,000', 'account_id' => $goal->account_id,
            'deadline_year' => '2026', 'deadline_month' => '9',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('goals', ['id' => $goal->id, 'name' => '新しい家電', 'target_amount' => 200000, 'deadline_month' => '2026-09-01']);
    }

    public function test_期限に今月より前は選べない_追加でも変更でも(): void
    {
        $a = $this->account('貯金用A');
        $this->store($a, ['deadline_year' => '2026', 'deadline_month' => '9'])->assertSessionHasErrors();
        $this->assertDatabaseCount('goals', 0);

        $goal = $this->goal('旅行', $a, 300000, '2027-01-01');
        $this->from("/goals/{$goal->id}/edit")->put("/goals/{$goal->id}", [
            'name' => '旅行', 'target_amount' => '300000', 'account_id' => $a->id,
            'deadline_year' => '2026', 'deadline_month' => '9',
        ])->assertSessionHasErrors();
        $this->assertDatabaseHas('goals', ['id' => $goal->id, 'deadline_month' => '2027-01-01']);
    }

    public function test_期限を過ぎた目標で別の過去の月に変えるのは断られる(): void
    {
        $goal = $this->goal('家電', $this->account('貯金用A'), 150000, '2026-09-01');

        $this->from("/goals/{$goal->id}/edit")->put("/goals/{$goal->id}", [
            'name' => '家電', 'target_amount' => '150000', 'account_id' => $goal->account_id,
            'deadline_year' => '2026', 'deadline_month' => '8',
        ])->assertSessionHasErrors();
        $this->assertDatabaseHas('goals', ['id' => $goal->id, 'deadline_month' => '2026-09-01']);
    }

    public function test_今月の期限は選べる(): void
    {
        $this->store($this->account('貯金用A'), ['deadline_year' => '2026', 'deadline_month' => '10'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('goals', ['deadline_month' => '2026-10-01']);
    }

    public function test_達成した目標は達成した目標の欄に下に出てホームには出ない(): void
    {
        $this->goal('引っ越し', $this->account('口座1', 200000), 200000);
        $this->goal('旅行', $this->account('口座2', 120000), 300000);

        $this->get('/goals')->assertOk()->assertSeeInOrder(['旅行', '達成した目標', '引っ越し', '✓ 達成']);

        $this->get('/')->assertOk()->assertSee('旅行')->assertDontSee('引っ越し');
    }

    public function test_残高が目標額を下回ると上に戻る(): void
    {
        $a = $this->account('口座1', 200000);
        $this->goal('引っ越し', $a, 200000);
        $this->goal('旅行', $this->account('口座2', 0), 300000);

        $this->get('/goals')->assertSeeInOrder(['旅行', '達成した目標', '引っ越し']);

        Record::factory()->create(['type' => RecordType::Expense, 'amount' => 1, 'account_id' => $a->id, 'date' => '2026-10-24']);

        $this->get('/goals')->assertOk()->assertDontSee('✓ 達成')->assertDontSee('達成した目標')->assertSee('引っ越し');
        $this->get('/')->assertSee('引っ越し');
    }

    public function test_ホームには達成していない目標の進み具合が一覧と同じ順で出る(): void
    {
        $this->goal('旅行', $this->account('口座1', 120000), 300000, '2027-08-01');
        $this->goal('車の買いかえ', $this->account('口座2', 450000), 1500000);
        $this->goal('家電', $this->account('口座3', 100000), 100000);
        $this->goal('近い', $this->account('口座4', 10000), 100000, '2026-12-01');

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['近い', '10%', '旅行', '40%', '車の買いかえ', '30%'])
            ->assertDontSee('家電');
    }

    public function test_削除しても口座と残高は変わらない(): void
    {
        $a = $this->account('貯金用A', 120000);
        $goal = $this->goal('旅行', $a);

        $this->delete("/goals/{$goal->id}")->assertRedirect();

        $this->assertDatabaseMissing('goals', ['id' => $goal->id]);
        $this->assertDatabaseHas('accounts', ['id' => $a->id, 'initial_balance' => 120000, 'is_active' => true]);
        $this->assertSame(120000, $a->fresh()->balance());
    }

    public function test_削除したあとは口座をほかの目標で使える(): void
    {
        $a = $this->account('貯金用A');
        $goal = $this->goal('旅行', $a);
        $this->delete("/goals/{$goal->id}");

        $this->store($a, ['name' => '車'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('goals', ['name' => '車', 'account_id' => $a->id]);
    }

    public function test_目標が使っている口座は削除できず目標の名前が出る(): void
    {
        $a = $this->account('貯金用A');
        $this->goal('旅行', $a);

        $this->followingRedirects()->from('/accounts')->delete("/accounts/{$a->id}")->assertSee('旅行');

        $this->assertDatabaseHas('accounts', ['id' => $a->id]);
    }

    public function test_目標が使っている口座は残高0円でも使わないにできず目標の名前が出る(): void
    {
        $a = $this->account('貯金用A', 0);
        $this->goal('旅行', $a);

        $this->followingRedirects()->from('/accounts')->patch("/accounts/{$a->id}/deactivate")->assertSee('旅行');

        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_目標が使っている口座は変更の画面で使うを外しても使わないにできない(): void
    {
        $a = $this->account('貯金用A', 0);
        $this->goal('旅行', $a);

        $this->followingRedirects()->from("/accounts/{$a->id}/edit")->put("/accounts/{$a->id}", [
            'name' => '貯金用A', 'type' => AccountType::Bank->value, 'initial_balance' => '0',
            'reserve_type' => 'none', 'is_active' => '0',
        ])->assertSee('旅行');

        $this->assertTrue($a->fresh()->is_active);
    }

    public function test_目標が使っている口座はクレジットカードに変えられず目標の名前が出る(): void
    {
        $a = $this->account('貯金用A', 0);
        $this->goal('旅行', $a);

        $this->followingRedirects()->from("/accounts/{$a->id}/edit")->put("/accounts/{$a->id}", [
            'name' => '貯金用A', 'type' => AccountType::CreditCard->value, 'initial_balance' => '0',
            'reserve_type' => 'none', 'is_active' => '1',
        ])->assertSee('旅行')->assertSee('クレジットカードにできません');

        $this->assertSame(AccountType::Bank, $a->fresh()->type);
    }

    public function test_メニューと設定の画面に貯金の目標が出る(): void
    {
        $this->get('/settings')->assertOk()->assertSee('貯金の目標');
        $this->get('/')->assertOk()->assertSee('貯金の目標');
    }
}
