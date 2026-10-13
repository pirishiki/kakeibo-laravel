<?php

namespace Tests\Feature\Accounts;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 要件定義書 10.1「残高の計算（No.8、データベース設計書 3.5）」のうち、記録の画面がなくても確かめられるもの。
 * 記録の画面ができたら、記録を直す・削除したときの項目を足す。
 */
class AccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function record(RecordType $type, int $amount, Account $account, ?Account $to = null, ?string $date = null): Record
    {
        return Record::factory()->create([
            'type' => $type,
            'amount' => $amount,
            'account_id' => $account->id,
            'to_account_id' => $to?->id,
            'date' => $date ?? today()->toDateString(),
        ]);
    }

    public function test_残高は最初の残高に収入と調整を足し支出を引いたもの(): void
    {
        $bank = Account::factory()->create(['initial_balance' => 100_000]);

        $this->record(RecordType::Income, 250_000, $bank);
        $this->record(RecordType::Expense, 80_000, $bank);
        $this->record(RecordType::Adjustment, -500, $bank);
        $this->record(RecordType::Adjustment, 200, $bank);

        $this->assertSame(100_000 + 250_000 - 80_000 - 500 + 200, $bank->balance());
    }

    public function test_移動は出た口座から引いて入った口座に足すので全体の合計は変わらない(): void
    {
        $bank = Account::factory()->create(['initial_balance' => 50_000]);
        $cash = Account::factory()->create(['type' => AccountType::Cash, 'initial_balance' => 3_000]);

        $this->record(RecordType::Transfer, 10_000, $bank, $cash);

        $this->assertSame([$bank->id => 40_000, $cash->id => 13_000], Account::balances());
        $this->assertSame(53_000, array_sum(Account::balances()));
    }

    public function test_カードで買うとマイナスになり引き落としで0円に戻る(): void
    {
        $bank = Account::factory()->create(['initial_balance' => 100_000]);
        $card = Account::factory()->create(['type' => AccountType::CreditCard]);

        $this->record(RecordType::Expense, 4_800, $card);
        $this->assertSame(-4_800, $card->balance());

        $this->record(RecordType::Transfer, 4_800, $bank, $card);
        $this->assertSame(0, $card->balance());
        $this->assertSame(95_200, $bank->balance());
    }

    public function test_先の日付の記録は今の残高に入らずその日になると入る(): void
    {
        $cash = Account::factory()->create(['type' => AccountType::Cash, 'initial_balance' => 10_000]);
        $this->record(RecordType::Expense, 3_000, $cash, date: today()->addDays(3)->toDateString());

        $this->assertSame(10_000, $cash->balance());
        $this->assertSame(7_000, $cash->balance(today()->addDays(3)));

        $this->travel(3)->days();
        $this->assertSame(7_000, $cash->balance());
    }

    public function test_前の日の残高はその日の終わりまでの記録で出す(): void
    {
        $cash = Account::factory()->create(['type' => AccountType::Cash, 'initial_balance' => 10_000]);
        $this->record(RecordType::Expense, 1_000, $cash, date: today()->subDays(5)->toDateString());
        $this->record(RecordType::Expense, 2_000, $cash, date: today()->subDays(2)->toDateString());

        $this->assertSame(10_000, $cash->balance(today()->subDays(6)));
        $this->assertSame(9_000, $cash->balance(today()->subDays(5)));
        $this->assertSame(7_000, $cash->balance());
    }

    public function test_一覧は種類ごと_同じ種類の中は追加した順に並ぶ(): void
    {
        $card = Account::factory()->create(['name' => 'カードA', 'type' => AccountType::CreditCard]);
        $bank1 = Account::factory()->create(['name' => 'みずほ銀行', 'type' => AccountType::Bank]);
        $ic = Account::factory()->create(['name' => '交通系IC', 'type' => AccountType::TransitIc]);
        $cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash]);
        $bank2 = Account::factory()->create(['name' => 'ゆうちょ', 'type' => AccountType::Bank]);

        $this->assertSame(
            [$bank1->id, $bank2->id, $cash->id, $ic->id, $card->id],
            Account::query()->ordered()->pluck('id')->all(),
        );
    }

    public function test_記録がある口座はデータベースでも削除できない(): void
    {
        $bank = Account::factory()->create();
        $cash = Account::factory()->create(['type' => AccountType::Cash]);
        $this->record(RecordType::Transfer, 1_000, $bank, $cash);

        $this->assertTrue($bank->hasRecords());
        $this->assertTrue($cash->hasRecords(), '移動の「どこへ」になっているだけでも記録があると数える');

        $this->expectException(QueryException::class);
        $cash->delete();
    }

    public function test_日付は日本時間で数えるので夜中の0時すぎもその日の記録が入る(): void
    {
        // 日本時間 10月14日 0時30分 ＝ 世界の標準時（UTC）では まだ 10月13日
        $this->travelTo(CarbonImmutable::parse('2026-10-14 00:30', 'Asia/Tokyo'));
        $cash = Account::factory()->create(['type' => AccountType::Cash, 'initial_balance' => 10_000]);
        $this->record(RecordType::Expense, 1_000, $cash, date: '2026-10-14');

        $this->assertSame(9_000, $cash->balance());
    }
}
