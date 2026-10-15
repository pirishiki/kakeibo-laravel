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
 * 要件定義書 10.1「口座の管理・最初の残高（No.2・3、画面設計書 5.6）」のうち、一覧・追加・変更の項目。
 */
class AccountManageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge(['name' => 'みずほ銀行', 'type' => 'bank', 'initial_balance' => '350000', 'is_active' => '1'], $overrides);
    }

    public function test_ログインしないと口座の画面は開けない(): void
    {
        auth()->logout();

        $this->get('/accounts')->assertRedirect('/login');
        $this->get('/accounts/create')->assertRedirect('/login');
        $this->post('/accounts', $this->form())->assertRedirect('/login');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_口座がないときは案内と追加のボタンが出る(): void
    {
        $this->get('/accounts')
            ->assertOk()
            ->assertSee('まだ口座がありません')
            ->assertSee('＋ 口座を追加');
    }

    public function test_4つの種類の口座を追加でき_同じ種類を2つ以上持てる(): void
    {
        $this->post('/accounts', $this->form())->assertRedirect('/accounts')->assertSessionHas('status', '保存しました');
        $this->post('/accounts', $this->form(['name' => 'ゆうちょ']));
        $this->post('/accounts', $this->form(['name' => '財布', 'type' => 'cash', 'initial_balance' => '8500']));
        $this->post('/accounts', $this->form(['name' => '交通系IC', 'type' => 'transit_ic', 'initial_balance' => '2300']));
        $this->post('/accounts', $this->form(['name' => 'カードA', 'type' => 'credit_card', 'initial_balance' => '-48300']));

        $this->assertSame(2, Account::where('type', AccountType::Bank)->count());
        $card = Account::where('name', 'カードA')->firstOrFail();
        $this->assertSame(AccountType::CreditCard, $card->type);
        $this->assertSame(-48_300, $card->initial_balance, '最初の残高にマイナスも入れられる');
        $this->assertTrue($card->is_active, '追加した口座は「使う」になる');
    }

    public function test_一覧は種類ごと_同じ種類の中は追加した順で_使わない口座はいちばん下(): void
    {
        Account::factory()->create(['name' => 'カードA', 'type' => AccountType::CreditCard, 'initial_balance' => -48_300]);
        Account::factory()->create(['name' => 'みずほ銀行', 'type' => AccountType::Bank, 'initial_balance' => 412_000]);
        Account::factory()->create(['name' => '旧カード', 'type' => AccountType::Bank, 'is_active' => false]);
        Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 8_500]);

        $this->get('/accounts')
            ->assertOk()
            ->assertSeeInOrder(['みずほ銀行', '412,000', '財布', '8,500', 'カードA', '-48,300', '使わない口座', '旧カード']);
    }

    public function test_一覧の残高は記録をふくめた今日までの残高(): void
    {
        $cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 10_000]);
        Record::factory()->create(['account_id' => $cash->id, 'type' => RecordType::Expense, 'amount' => 1_500]);
        Record::factory()->create(['account_id' => $cash->id, 'type' => RecordType::Expense, 'amount' => 3_000, 'date' => today()->addDay()]);

        $this->get('/accounts')->assertSee('8,500')->assertDontSee('5,500');
    }

    public function test_名前は必須で50文字まで(): void
    {
        $this->post('/accounts', $this->form(['name' => '']))
            ->assertSessionHasErrors(['name' => '名前を入れてください。']);
        $this->post('/accounts', $this->form(['name' => str_repeat('あ', 51)]))
            ->assertSessionHasErrors(['name' => '名前は 50 文字以内で入れてください。']);
        $this->post('/accounts', $this->form(['name' => str_repeat('あ', 50)]))->assertSessionHasNoErrors();
    }

    public function test_ほかの口座と同じ名前はつけられない(): void
    {
        $other = Account::factory()->create(['name' => 'みずほ銀行']);
        $self = Account::factory()->create(['name' => '財布']);

        $this->post('/accounts', $this->form())
            ->assertSessionHasErrors(['name' => 'この名前は、もう使われています。']);
        $this->put("/accounts/{$self->id}", $this->form(['name' => 'みずほ銀行']))
            ->assertSessionHasErrors('name');
        // 自分の名前のまま保存するのはよい
        $this->put("/accounts/{$other->id}", $this->form())->assertSessionHasNoErrors();
    }

    public function test_種類は4つのどれか(): void
    {
        $this->post('/accounts', $this->form(['type' => 'paypay']))
            ->assertSessionHasErrors(['type' => '種類は、出ている中から選んでください。']);
    }

    public function test_最初の残高は99999999円まで_マイナスも同じ大きさまで_小数は不可(): void
    {
        $message = '最初の残高は -99,999,999〜99,999,999 円で入れてください。';

        $this->post('/accounts', $this->form(['initial_balance' => '100000000']))->assertSessionHasErrors(['initial_balance' => $message]);
        $this->post('/accounts', $this->form(['initial_balance' => '-100000000']))->assertSessionHasErrors(['initial_balance' => $message]);
        $this->post('/accounts', $this->form(['initial_balance' => '1.5']))
            ->assertSessionHasErrors(['initial_balance' => '最初の残高は、小数のない数で入れてください。']);
        $this->post('/accounts', $this->form(['initial_balance' => '']))->assertSessionHasErrors('initial_balance');

        $this->post('/accounts', $this->form(['name' => 'A', 'initial_balance' => '99999999']))->assertSessionHasNoErrors();
        $this->post('/accounts', $this->form(['name' => 'B', 'initial_balance' => '-99999999']))->assertSessionHasNoErrors();
    }

    public function test_記録がない口座は最初の残高を直せる(): void
    {
        $bank = Account::factory()->create(['name' => 'みずほ銀行', 'initial_balance' => 350_000]);

        $this->get("/accounts/{$bank->id}/edit")->assertOk()->assertDontSee('記録があるので変えられません');
        $this->put("/accounts/{$bank->id}", $this->form(['name' => 'みずほ', 'type' => 'cash', 'initial_balance' => '360000']))
            ->assertRedirect('/accounts');

        $bank->refresh();
        $this->assertSame(['みずほ', AccountType::Cash, 360_000], [$bank->name, $bank->type, $bank->initial_balance]);
    }

    public function test_記録が1件でもある口座は最初の残高を変えられない(): void
    {
        $bank = Account::factory()->create(['name' => 'みずほ銀行', 'initial_balance' => 350_000]);
        Record::factory()->create(['account_id' => $bank->id]);

        $this->get("/accounts/{$bank->id}/edit")->assertOk()->assertSee('記録があるので変えられません');

        // 画面の欄は押せない。開いたあとに記録ができて、ちがう最初の残高が送られてきたら、黙って捨てずに知らせる
        $this->from("/accounts/{$bank->id}/edit")
            ->put("/accounts/{$bank->id}", $this->form(['name' => 'みずほ', 'initial_balance' => '1']))
            ->assertRedirect("/accounts/{$bank->id}/edit")
            ->assertSessionHasErrors('initial_balance');
        $this->assertSame(350_000, $bank->refresh()->initial_balance);

        // 同じ最初の残高のまま（欄が押せない画面から送ったとき）なら、名前などのほかの欄は直せる
        $this->put("/accounts/{$bank->id}", $this->form(['name' => 'みずほ', 'initial_balance' => '350,000']))
            ->assertRedirect('/accounts');

        $bank->refresh();
        $this->assertSame('みずほ', $bank->name);
        $this->assertSame(350_000, $bank->initial_balance);
    }

    public function test_濁点やひらがなとカタカナがちがえば別の名前としてつけられる(): void
    {
        $this->post('/accounts', $this->form(['name' => 'ハハ']))->assertSessionHasNoErrors();
        $this->post('/accounts', $this->form(['name' => 'パパ']))->assertSessionHasNoErrors();
        $this->post('/accounts', $this->form(['name' => 'ゆうちょ']))->assertSessionHasNoErrors();
        $this->post('/accounts', $this->form(['name' => 'ユウチョ']))->assertSessionHasNoErrors();

        // 全角と半角の英数字は同じ名前として扱う
        $this->post('/accounts', $this->form(['name' => 'ＡＢ銀行']))->assertSessionHasNoErrors();
        $this->post('/accounts', $this->form(['name' => 'AB銀行']))->assertSessionHasErrors('name');

        $this->assertDatabaseCount('accounts', 5);
    }

    public function test_最初の残高はカンマや全角_マイナスで打っても受けとる(): void
    {
        $this->post('/accounts', $this->form(['name' => 'カード', 'initial_balance' => '－12,800']))->assertSessionHasNoErrors();
        $this->post('/accounts', $this->form(['name' => '財布2', 'initial_balance' => '１,０００']))->assertSessionHasNoErrors();

        $this->assertSame(-12800, Account::query()->where('name', 'カード')->sole()->initial_balance);
        $this->assertSame(1000, Account::query()->where('name', '財布2')->sole()->initial_balance);
        $this->get(route('accounts.edit', Account::query()->where('name', 'カード')->sole()))->assertSee('value="-12,800"', false);
    }
}
