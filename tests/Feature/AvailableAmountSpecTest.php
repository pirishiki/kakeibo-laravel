<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Enums\ReserveType;
use App\Models\Account;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\RecurringSkip;
use App\Support\AvailableAmount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 使える額・カレンダーの予定（要件定義書 5.5・10.2「予定の表示」「使える額」、画面設計書 5.2、データベース設計書 3.2・3.5〜3.8）
 * 実装コードではなく設計書だけから書いたテスト（読みちがいを見つけるため）。
 *
 * 基準の日：2026年10月14日（水）。10/20 火、10/27 火、10/30 金、10/31 土、11/1 日、11/2 月
 */
class AvailableAmountSpecTest extends TestCase
{
    use RefreshDatabase;

    private function bank(int $balance = 0, array $extra = []): Account
    {
        return Account::factory()->create(array_merge(['type' => AccountType::Bank, 'initial_balance' => $balance], $extra));
    }

    private function reserve(ReserveType $type, ?int $amount = null): array
    {
        return ['reserve_type' => $type, 'reserve_amount' => $amount];
    }

    /** 定期的な記録を作る。最初の月は 2026年10月、決まった金額の支出が基本 */
    private function recurring(Account $account, array $attrs = []): Recurring
    {
        return Recurring::factory()->create(array_merge([
            'name' => '予定',
            'type' => RecordType::Expense,
            'day' => 20,
            'shift' => RecurringShift::Next,
            'is_fixed' => true,
            'amount' => 10000,
            'estimated_amount' => null,
            'account_id' => $account->id,
            'to_account_id' => null,
            'start_month' => '2026-10-01',
            'end_month' => null,
            'is_active' => true,
        ], $attrs));
    }

    /** 定期的な記録から自動で作られた記録 */
    private function recorded(Recurring $r, string $month, string $date, int $amount): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => $r->type,
            'amount' => $amount,
            'account_id' => $r->account_id,
            'to_account_id' => $r->to_account_id,
            'recurring_id' => $r->id,
            'recurring_month' => $month,
        ]);
    }

    /** 手で入れた記録 */
    private function manual(RecordType $type, string $date, int $amount, Account $account, ?Account $to = null): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => $type,
            'amount' => $amount,
            'account_id' => $account->id,
            'to_account_id' => $to?->id,
        ]);
    }

    private function calc(string $today = '2026-10-14'): array
    {
        $this->travelTo(CarbonImmutable::parse($today.' 12:00'));

        return AvailableAmount::calculate(CarbonImmutable::parse($today));
    }

    private function cal(string $month, string $today = '2026-10-14'): array
    {
        $this->travelTo(CarbonImmutable::parse($today.' 12:00'));

        return AvailableAmount::calendarPlans(CarbonImmutable::parse($month), CarbonImmutable::parse($today));
    }

    // ---- 今の合計 ----

    public function test_total_is_sum_of_active_accounts_balances_up_to_today(): void
    {
        // 要件定義書 5.5「今の合計残高」／画面設計書 5.2「今の残高は、今日までの日付の記録で計算する」
        $a = $this->bank(100000);
        $b = $this->bank(50000);
        $this->manual(RecordType::Expense, '2026-10-14', 3000, $a);
        $this->manual(RecordType::Income, '2026-10-10', 1000, $b);

        $r = $this->calc();

        $this->assertSame(148000, $r['total']);
        $this->assertSame(0, $r['reserved']);
        $this->assertSame(148000, $r['available']);
    }

    public function test_inactive_account_is_excluded_from_total(): void
    {
        // 画面設計書 5.2「『使わない』口座は出さない」
        $this->bank(100000);
        $this->bank(0, ['is_active' => false]);

        $this->assertSame(100000, $this->calc()['total']);
    }

    // ---- 予定（定期的な記録） ----

    public function test_unrecorded_recurring_expense_this_month_is_deducted(): void
    {
        // 要件定義書 5.5「まだ記録されていないもの」を引く
        $a = $this->bank(100000);
        $rec = $this->recurring($a, ['name' => '家賃', 'amount' => 30000]);

        $r = $this->calc();

        $this->assertSame(30000, $r['planned']);
        $this->assertSame(70000, $r['available']);
        $this->assertCount(1, $r['plans']);
        $this->assertSame('家賃', $r['plans'][0]['name']);
        $this->assertSame(30000, $r['plans'][0]['amount']);
        $this->assertFalse($r['plans'][0]['estimated']);
        $this->assertSame($rec->id, $r['plans'][0]['recurringId']);
        $this->assertNull($r['plans'][0]['recordId']);
        $this->assertSame('2026-10-20', $r['plans'][0]['date']->toDateString());
    }

    public function test_already_recorded_recurring_is_not_a_plan(): void
    {
        // 要件定義書 5.5「すでに記録されたものは、今の合計残高から引かれているため」
        $a = $this->bank(100000);
        $rec = $this->recurring($a, ['day' => 10, 'amount' => 30000]);
        $this->recorded($rec, '2026-10-01', '2026-10-12', 30000);

        $r = $this->calc();

        $this->assertSame(70000, $r['total']);
        $this->assertSame(0, $r['planned']);
        $this->assertSame([], $r['plans']);
    }

    public function test_recording_a_plan_on_its_day_does_not_change_available(): void
    {
        // 要件定義書 10.2「予定が記録されても、使える額は変わらない」
        $a = $this->bank(500000);
        $rec = $this->recurring($a, ['day' => 20, 'amount' => 30000]);

        $before = $this->calc('2026-10-19');
        $this->assertSame(470000, $before['available']);

        $this->recorded($rec, '2026-10-01', '2026-10-20', 30000);
        $after = $this->calc('2026-10-20');

        $this->assertSame(470000, $after['total']);
        $this->assertSame(0, $after['planned']);
        $this->assertSame($before['available'], $after['available']);
    }

    public function test_recurring_income_is_never_added(): void
    {
        // 要件定義書 5.5「これから入る予定の収入は足さない」
        $a = $this->bank(100000);
        $this->recurring($a, ['type' => RecordType::Income, 'day' => 15, 'shift' => RecurringShift::Previous, 'amount' => 250000]);

        $r = $this->calc();

        $this->assertSame(100000, $r['available']);
        $this->assertSame(0, $r['planned']);
        $this->assertSame([], $r['plans']);
    }

    public function test_transfer_to_credit_card_does_not_change_total_or_available(): void
    {
        // 要件定義書 5.5「カードの引き落とし（銀行口座 → カードの移動）は引かない」
        $bank = $this->bank(100000);
        $card = Account::factory()->create(['type' => AccountType::CreditCard, 'initial_balance' => -30000]);
        $this->recurring($bank, ['type' => RecordType::Transfer, 'day' => 27, 'amount' => 30000, 'to_account_id' => $card->id]);

        $r = $this->calc();

        $this->assertSame(70000, $r['total']);
        $this->assertSame(0, $r['planned']);
        $this->assertSame(70000, $r['available']);
        $this->assertSame([], $r['plans']);
    }

    public function test_credit_card_has_no_reserve_entry(): void
    {
        // 要件定義書 10.2「クレジットカードには、この欄が出ない」→ reserves に出ない
        $bank = $this->bank(100000);
        $card = Account::factory()->create(['type' => AccountType::CreditCard, 'initial_balance' => -30000]);
        $this->recurring($bank, ['type' => RecordType::Transfer, 'day' => 27, 'amount' => 30000, 'to_account_id' => $card->id]);

        $r = $this->calc();

        $this->assertArrayNotHasKey($card->id, $r['reserves']);
        $this->assertArrayNotHasKey($bank->id, $r['reserves']);
        $this->assertSame(0, $r['reserved']);
    }

    // ---- 概算 ----

    public function test_variable_item_without_amount_uses_registered_estimate(): void
    {
        // 要件定義書 5.5「前回の記録がないときは、登録するときに入れた概算を使う」
        $a = $this->bank(100000);
        $this->recurring($a, ['is_fixed' => false, 'amount' => null, 'estimated_amount' => 5000]);

        $r = $this->calc();

        $this->assertSame(5000, $r['planned']);
        $this->assertTrue($r['plans'][0]['estimated']);
        $this->assertSame(5000, $r['plans'][0]['amount']);
    }

    public function test_estimate_is_latest_recorded_amount_by_month_not_by_id(): void
    {
        // 要件定義書 5.5「前回（いちばん新しい月）、実際に記録された金額」。9月を先に作り、id の順とは逆にしてある
        $a = $this->bank(1000000);
        $rec = $this->recurring($a, ['is_fixed' => false, 'amount' => null, 'estimated_amount' => 5000, 'start_month' => '2026-08-01']);
        $this->recorded($rec, '2026-09-01', '2026-09-20', 8000);
        $this->recorded($rec, '2026-08-01', '2026-08-20', 9000);

        $r = $this->calc();

        $this->assertSame(8000, $r['planned']);
        $this->assertTrue($r['plans'][0]['estimated']);
    }

    public function test_estimate_uses_latest_record_even_when_last_month_was_skipped(): void
    {
        // 要件定義書 5.5「先月が『今回はなし』だったときでも、概算を出せる」
        $a = $this->bank(1000000);
        $rec = $this->recurring($a, ['is_fixed' => false, 'amount' => null, 'estimated_amount' => 5000, 'start_month' => '2026-08-01']);
        $this->recorded($rec, '2026-08-01', '2026-08-20', 7000);
        RecurringSkip::create(['recurring_id' => $rec->id, 'month' => '2026-09-01']);

        $r = $this->calc();

        $this->assertSame(7000, $r['planned']);
        $this->assertCount(1, $r['plans']);
    }

    public function test_entered_amount_is_used_instead_of_estimate(): void
    {
        // データベース設計書 3.7：その月の金額が入っていれば、それを使う（概算ではない）
        $a = $this->bank(100000);
        $rec = $this->recurring($a, ['is_fixed' => false, 'amount' => null, 'estimated_amount' => 5000]);
        RecurringAmount::create(['recurring_id' => $rec->id, 'month' => '2026-10-01', 'amount' => 12000]);

        $r = $this->calc();

        $this->assertSame(12000, $r['planned']);
        $this->assertFalse($r['plans'][0]['estimated']);
    }

    // ---- 日付が過ぎたのに金額がまだ ----

    public function test_past_day_this_month_without_amount_is_still_deducted_with_estimate(): void
    {
        // 画面設計書 5.2「日付が過ぎたのに金額がまだの予定も、概算で予定として引く」
        $a = $this->bank(100000);
        $this->recurring($a, ['day' => 5, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 4000]);

        $r = $this->calc();

        $this->assertSame(4000, $r['planned']);
        $this->assertSame('2026-10-05', $r['plans'][0]['date']->toDateString());
        $this->assertTrue($r['plans'][0]['estimated']);
    }

    public function test_past_months_without_amount_are_also_deducted(): void
    {
        // 画面設計書 5.2「過ぎた月の分も、使える額からは引く」。8・9・10月が全部まだ → 3 件
        $a = $this->bank(100000);
        $this->recurring($a, ['day' => 5, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 4000, 'start_month' => '2026-08-01']);

        $r = $this->calc();

        $this->assertCount(3, $r['plans']);
        $this->assertSame(12000, $r['planned']);
        $this->assertSame(88000, $r['available']);
    }

    // ---- 除外 ----

    public function test_skipped_month_is_excluded(): void
    {
        // 要件定義書 10.2「『今回はなし』にした予定は、カレンダーにも使える額にも入らない」
        $a = $this->bank(100000);
        $rec = $this->recurring($a);
        RecurringSkip::create(['recurring_id' => $rec->id, 'month' => '2026-10-01']);

        $r = $this->calc();

        $this->assertSame(0, $r['planned']);
        $this->assertSame([], $r['plans']);
    }

    public function test_inactive_recurring_is_excluded(): void
    {
        // データベース設計書 3.6「『使わない』の予定」は記録しない＝予定でもない
        $a = $this->bank(100000);
        $this->recurring($a, ['is_active' => false]);

        $this->assertSame(0, $this->calc()['planned']);
    }

    public function test_recurring_not_in_selected_months_or_starting_later_is_excluded(): void
    {
        // データベース設計書 3.6：選んだ月に入っていない月・最初の月より前は対象外
        $a = $this->bank(100000);
        $this->recurring($a, ['months' => [6, 12]]);
        $this->recurring($a, ['start_month' => '2026-12-01']);

        $this->assertSame(0, $this->calc()['planned']);
    }

    // ---- ずらした日と月 ----

    public function test_next_months_item_shifted_back_into_this_month_is_included(): void
    {
        // 画面設計書 5.2「お金が動く日で数える」：11月1日（日）・前の平日 → 10月30日（金）。10月の使える額に入る
        $a = $this->bank(100000);
        $this->recurring($a, ['day' => 1, 'shift' => RecurringShift::Previous, 'amount' => 20000, 'start_month' => '2026-11-01']);

        $r = $this->calc();

        $this->assertSame(20000, $r['planned']);
        $this->assertSame('2026-10-30', $r['plans'][0]['date']->toDateString());
    }

    public function test_this_months_item_shifted_into_next_month_is_excluded(): void
    {
        // 要件定義書 10.2：10月分の家賃が 10月31日（土）→ 11月2日（月）なら、10月の使える額には入らない
        $a = $this->bank(100000);
        $this->recurring($a, ['day' => 31, 'shift' => RecurringShift::Next, 'amount' => 70000]);

        $r = $this->calc();

        $this->assertSame(0, $r['planned']);
        $this->assertSame([], $r['plans']);
        $this->assertSame(100000, $r['available']);
    }

    public function test_shifted_item_moves_to_next_month_in_calendar_and_back_in_other_case(): void
    {
        // 同上のカレンダー版：10月のカレンダーには出ず、11月2日のマスに出る。11月1日の分は 10月30日のマスに出る
        $a = $this->bank(100000);
        $this->recurring($a, ['day' => 31, 'shift' => RecurringShift::Next, 'amount' => 70000, 'start_month' => '2026-10-01', 'end_month' => '2026-10-01']);
        $this->recurring($a, ['day' => 1, 'shift' => RecurringShift::Previous, 'amount' => 20000, 'start_month' => '2026-11-01', 'end_month' => '2026-11-01']);

        $oct = $this->cal('2026-10-01');
        $nov = $this->cal('2026-11-01');

        $this->assertArrayNotHasKey('2026-10-31', $oct);
        $this->assertArrayNotHasKey('2026-11-02', $oct);
        $this->assertSame(['expense' => 20000, 'income' => 0], $oct['2026-10-30']);
        $this->assertSame(['expense' => 70000, 'income' => 0], $nov['2026-11-02']);
        $this->assertArrayNotHasKey('2026-10-30', $nov);
    }

    // ---- 手で入れた先の日付の記録 ----

    public function test_manual_future_expenses_until_end_of_month_are_planned(): void
    {
        // 要件定義書 5.5：手で入れた明日から今月末までの支出も予定として引く
        $a = $this->bank(100000);
        $m1 = $this->manual(RecordType::Expense, '2026-10-15', 5000, $a);
        $m2 = $this->manual(RecordType::Expense, '2026-10-31', 7000, $a);

        $r = $this->calc();

        $this->assertSame(12000, $r['planned']);
        $this->assertSame(100000, $r['total']);
        $this->assertSame(88000, $r['available']);
        $this->assertCount(2, $r['plans']);
        $this->assertSame($m1->id, $r['plans'][0]['recordId']);
        $this->assertSame($m2->id, $r['plans'][1]['recordId']);
        $this->assertNull($r['plans'][0]['recurringId']);
        $this->assertFalse($r['plans'][0]['estimated']);
    }

    public function test_manual_expense_today_is_in_total_not_in_plans(): void
    {
        // 今日の記録は残高に入っているので、予定にしない（二重に引かない）
        $a = $this->bank(100000);
        $this->manual(RecordType::Expense, '2026-10-14', 5000, $a);

        $r = $this->calc();

        $this->assertSame(95000, $r['total']);
        $this->assertSame(0, $r['planned']);
        $this->assertSame(95000, $r['available']);
    }

    public function test_manual_expense_next_month_is_not_planned(): void
    {
        // 要件定義書 10.2「来月以降の日付の記録は、今月の使える額に入らない」
        $a = $this->bank(100000);
        $this->manual(RecordType::Expense, '2026-11-01', 5000, $a);

        $r = $this->calc();

        $this->assertSame(0, $r['planned']);
        $this->assertSame(100000, $r['available']);
    }

    public function test_manual_future_income_is_never_added(): void
    {
        // 要件定義書 5.5「収入は足さない」（手で入れた先の日付の収入も）
        $a = $this->bank(100000);
        $this->manual(RecordType::Income, '2026-10-20', 90000, $a);

        $r = $this->calc();

        $this->assertSame(100000, $r['available']);
        $this->assertSame([], $r['plans']);
    }

    public function test_plans_are_sorted_by_date_across_recurring_and_manual(): void
    {
        // 「予定は日付の順」：定期的な記録 15 日・手で入れた 16 日・定期的な記録 20 日
        $a = $this->bank(100000);
        $this->recurring($a, ['name' => '二十日', 'day' => 20]);
        $this->manual(RecordType::Expense, '2026-10-16', 1000, $a);
        $this->recurring($a, ['name' => '十五日', 'day' => 15]);

        $dates = array_map(fn ($p) => $p['date']->toDateString(), $this->calc()['plans']);

        $this->assertSame(['2026-10-15', '2026-10-16', '2026-10-20'], $dates);
    }

    // ---- 1 日あたり・1 週間あたり ----

    public function test_per_day_and_per_week_with_many_days_left(): void
    {
        // 14日〜31日で 18 日（今日をふくむ）。180,000 ÷ 18 = 10,000、× 7
        $this->bank(180000);

        $r = $this->calc();

        $this->assertSame(18, $r['daysLeft']);
        $this->assertSame(10000, $r['perDay']);
        $this->assertSame(70000, $r['perWeek']);
    }

    public function test_per_week_uses_days_left_when_fewer_than_seven(): void
    {
        // 10月28日は残り 4 日。100,001 ÷ 4 → 25,000（切り捨て）、1 週間あたりは 4 日分
        $this->bank(100001);

        $r = $this->calc('2026-10-28');

        $this->assertSame(4, $r['daysLeft']);
        $this->assertSame(25000, $r['perDay']);
        $this->assertSame(100000, $r['perWeek']);
    }

    public function test_last_day_of_month_has_one_day_left(): void
    {
        $this->bank(12345);

        $r = $this->calc('2026-10-31');

        $this->assertSame(1, $r['daysLeft']);
        $this->assertSame(12345, $r['perDay']);
        $this->assertSame(12345, $r['perWeek']);
    }

    public function test_per_week_boundary_seven_and_eight_days_left(): void
    {
        // 10月25日＝残り 7 日、24日＝残り 8 日（8 日でも 1 週間あたりは 7 日分）
        $this->bank(80000);

        $seven = $this->calc('2026-10-25');
        $eight = $this->calc('2026-10-24');

        $this->assertSame(7, $seven['daysLeft']);
        $this->assertSame(11428, $seven['perDay']);
        $this->assertSame(11428 * 7, $seven['perWeek']);
        $this->assertSame(8, $eight['daysLeft']);
        $this->assertSame(10000, $eight['perDay']);
        $this->assertSame(70000, $eight['perWeek']);
    }

    public function test_negative_per_day_truncates_toward_zero(): void
    {
        // 要件定義書 10.2：-11,531 ÷ 7 日 → -1,647（-1,648 ではない）
        $this->bank(-11531);

        $r = $this->calc('2026-10-25');

        $this->assertSame(-11531, $r['available']);
        $this->assertSame(7, $r['daysLeft']);
        $this->assertSame(-1647, $r['perDay']);
        $this->assertSame(-1647 * 7, $r['perWeek']);
    }

    // ---- 取っておく額 ----

    public function test_reserve_none_counts_zero_and_has_no_entry(): void
    {
        $a = $this->bank(100000);

        $r = $this->calc();

        $this->assertSame(0, $r['reserved']);
        $this->assertArrayNotHasKey($a->id, $r['reserves']);
    }

    public function test_reserve_all_counts_whole_projected_balance_and_is_never_short(): void
    {
        // 要件定義書 5.5「全部」＝残高と同じ額。お知らせは出さない
        $a = $this->bank(500000, $this->reserve(ReserveType::All));

        $r = $this->calc();

        $this->assertSame(500000, $r['reserved']);
        $this->assertSame(0, $r['available']);
        $this->assertSame(['counted' => 500000, 'projected' => 500000, 'short' => false], $r['reserves'][$a->id]);
    }

    public function test_reserve_amount_counts_full_amount_when_balance_is_enough(): void
    {
        $a = $this->bank(300000, $this->reserve(ReserveType::Amount, 100000));

        $r = $this->calc();

        $this->assertSame(100000, $r['reserved']);
        $this->assertSame(200000, $r['available']);
        $this->assertSame(['counted' => 100000, 'projected' => 300000, 'short' => false], $r['reserves'][$a->id]);
    }

    public function test_reserve_amount_larger_than_balance_is_capped_and_short(): void
    {
        // 画面設計書 5.2「銀行口座が 150,000 円で取っておく額が 200,000 円なら、引くのは 150,000 円」
        $a = $this->bank(150000, $this->reserve(ReserveType::Amount, 200000));

        $r = $this->calc();

        $this->assertSame(150000, $r['reserved']);
        $this->assertSame(0, $r['available']);
        $this->assertSame(['counted' => 150000, 'projected' => 150000, 'short' => true], $r['reserves'][$a->id]);
    }

    public function test_reserve_amount_equal_to_projected_is_not_short(): void
    {
        // 「見込みの残高が取っておく額より少ない」ときだけ short（等しいときは short ではない）
        $a = $this->bank(200000, $this->reserve(ReserveType::Amount, 200000));

        $this->assertFalse($this->calc()['reserves'][$a->id]['short']);
    }

    public function test_negative_projected_balance_counts_zero_for_amount_and_all(): void
    {
        // 画面設計書 5.2「見込みの残高がマイナスのときは、『金額』でも『全部取っておく』でも 0 円」
        $amount = $this->bank(-5000, $this->reserve(ReserveType::Amount, 10000));
        $all = $this->bank(-2000, $this->reserve(ReserveType::All));

        $r = $this->calc();

        $this->assertSame(0, $r['reserved']);
        $this->assertSame(['counted' => 0, 'projected' => -5000, 'short' => true], $r['reserves'][$amount->id]);
        $this->assertSame(['counted' => 0, 'projected' => -2000, 'short' => false], $r['reserves'][$all->id]);
        $this->assertSame(-7000, $r['available']);
    }

    public function test_planned_expense_from_reserve_account_lowers_projected_balance(): void
    {
        // 見込みの残高＝今の残高 − 今月末までの予定の支出。120,000 − 50,000 = 70,000 < 100,000 → 足りない
        $a = $this->bank(120000, $this->reserve(ReserveType::Amount, 100000));
        $this->recurring($a, ['amount' => 50000]);

        $r = $this->calc();

        $this->assertSame(['counted' => 70000, 'projected' => 70000, 'short' => true], $r['reserves'][$a->id]);
        $this->assertSame(120000, $r['total']);
        $this->assertSame(70000, $r['reserved']);
        $this->assertSame(50000, $r['planned']);
        $this->assertSame(0, $r['available']);
    }

    public function test_manual_future_expense_lowers_projected_balance_too(): void
    {
        // 画面設計書 5.2「予定は上と同じく、手で入れた先の日付の記録もふくむ」
        $a = $this->bank(120000, $this->reserve(ReserveType::All));
        $this->manual(RecordType::Expense, '2026-10-30', 20000, $a);

        $r = $this->calc();

        $this->assertSame(100000, $r['reserves'][$a->id]['projected']);
        $this->assertSame(100000, $r['reserved']);
        $this->assertSame(0, $r['available']);
    }

    // ---- 予定の移動 ----

    public function test_recurring_transfer_to_all_reserve_account_counts_before_transfer_date(): void
    {
        // 画面設計書 5.2：貯金用の口座（全部取っておく）へ移す予定は、移す前からその分を取っておく額に数える
        $bank = $this->bank(300000);
        $savings = $this->bank(0, $this->reserve(ReserveType::All));
        $this->recurring($bank, ['type' => RecordType::Transfer, 'day' => 27, 'amount' => 30000, 'to_account_id' => $savings->id]);

        $r = $this->calc();

        $this->assertSame(300000, $r['total']);
        $this->assertSame(0, $r['planned']);
        $this->assertSame(30000, $r['reserved']);
        $this->assertSame(270000, $r['available']);
        $this->assertSame(['counted' => 30000, 'projected' => 30000, 'short' => false], $r['reserves'][$savings->id]);
    }

    public function test_manual_future_transfer_behaves_like_recurring_transfer(): void
    {
        // 手で入れた先の日付の移動も同じ。来月の移動は動かさない
        $bank = $this->bank(300000);
        $savings = $this->bank(0, $this->reserve(ReserveType::All));
        $this->manual(RecordType::Transfer, '2026-10-20', 30000, $bank, $savings);
        $this->manual(RecordType::Transfer, '2026-11-05', 99000, $bank, $savings);

        $r = $this->calc();

        $this->assertSame(30000, $r['reserved']);
        $this->assertSame(270000, $r['available']);
        $this->assertSame(0, $r['planned']);
    }

    public function test_transfer_out_lowers_projected_balance_of_source_reserve_account(): void
    {
        // 見込みの残高＝今の残高 − 予定の移動（出る側）。300,000 − 30,000 = 270,000 < 280,000
        $bank = $this->bank(300000, $this->reserve(ReserveType::Amount, 280000));
        $other = $this->bank(0);
        $this->recurring($bank, ['type' => RecordType::Transfer, 'day' => 27, 'amount' => 30000, 'to_account_id' => $other->id]);

        $r = $this->calc();

        $this->assertSame(['counted' => 270000, 'projected' => 270000, 'short' => true], $r['reserves'][$bank->id]);
        $this->assertSame(300000, $r['total']);
        $this->assertSame(30000, $r['available']);
    }

    public function test_recording_the_transfer_does_not_change_available(): void
    {
        // 「移した日に使える額が変わらない」
        $bank = $this->bank(300000);
        $savings = $this->bank(0, $this->reserve(ReserveType::All));
        $rec = $this->recurring($bank, ['type' => RecordType::Transfer, 'day' => 27, 'amount' => 30000, 'to_account_id' => $savings->id]);

        $before = $this->calc('2026-10-26');
        $this->recorded($rec, '2026-10-01', '2026-10-27', 30000);
        $after = $this->calc('2026-10-27');

        $this->assertSame(270000, $before['available']);
        $this->assertSame(270000, $after['available']);
        $this->assertSame(30000, $after['reserved']);
    }

    public function test_planned_income_into_reserve_account_is_not_added_to_projected_balance(): void
    {
        // 「見込みの残高…収入は足さない」
        $a = $this->bank(100000, $this->reserve(ReserveType::All));
        $this->recurring($a, ['type' => RecordType::Income, 'day' => 20, 'amount' => 50000]);

        $r = $this->calc();

        $this->assertSame(100000, $r['reserves'][$a->id]['projected']);
        $this->assertSame(100000, $r['reserved']);
        $this->assertSame(0, $r['available']);
    }

    // ---- 既定の今日 ----

    public function test_today_defaults_to_current_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 09:00'));
        $this->bank(100001);

        $this->assertSame(4, AvailableAmount::calculate()['daysLeft']);
    }

    // ---- カレンダーの予定 ----

    public function test_calendar_plans_show_expense_and_income_on_shifted_day_without_transfers(): void
    {
        // 画面設計書 5.2「カレンダー」：まだ記録されていない定期的な記録（灰色）。移動は出さない
        $a = $this->bank(0);
        $b = $this->bank(0);
        $this->recurring($a, ['day' => 20, 'amount' => 10000]);
        // 10月25日は日曜 → 前の平日で 23 日（金）
        $this->recurring($a, ['type' => RecordType::Income, 'day' => 24, 'shift' => RecurringShift::Previous, 'amount' => 200000]);
        $this->recurring($a, ['type' => RecordType::Transfer, 'day' => 27, 'amount' => 30000, 'to_account_id' => $b->id]);

        $plans = $this->cal('2026-10-01');

        $this->assertSame(['expense' => 10000, 'income' => 0], $plans['2026-10-20']);
        $this->assertSame(['expense' => 0, 'income' => 200000], $plans['2026-10-23']);
        $this->assertArrayNotHasKey('2026-10-27', $plans);
        $this->assertCount(2, $plans);
    }

    public function test_calendar_plans_exclude_recorded_skipped_and_inactive(): void
    {
        // 要件定義書 10.2：記録された予定は二重に出ない／今回はなしは入らない。「使わない」の予定も出ない
        $a = $this->bank(0);
        $recorded = $this->recurring($a, ['day' => 15]);
        $this->recorded($recorded, '2026-10-01', '2026-10-15', 10000);
        $skipped = $this->recurring($a, ['day' => 16]);
        RecurringSkip::create(['recurring_id' => $skipped->id, 'month' => '2026-10-01']);
        $this->recurring($a, ['day' => 19, 'is_active' => false]);
        $this->recurring($a, ['day' => 20]);

        $plans = $this->cal('2026-10-01');

        $this->assertSame(['2026-10-20'], array_keys($plans));
    }

    public function test_calendar_plans_use_estimate_unless_amount_entered(): void
    {
        // 金額がまだの予定は概算で出る。入力済みならその金額
        $a = $this->bank(0);
        $this->recurring($a, ['day' => 20, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 5000]);
        $entered = $this->recurring($a, ['day' => 27, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 5000]);
        RecurringAmount::create(['recurring_id' => $entered->id, 'month' => '2026-10-01', 'amount' => 6500]);

        $plans = $this->cal('2026-10-01');

        $this->assertSame(5000, $plans['2026-10-20']['expense']);
        $this->assertSame(6500, $plans['2026-10-27']['expense']);
    }

    public function test_calendar_plans_keep_showing_past_unrecorded_item_this_month(): void
    {
        // 画面設計書 5.2「今月の分は、カレンダーの記録する日のマスにも灰色で出しつづける」
        $a = $this->bank(0);
        $this->recurring($a, ['day' => 5, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 4000]);

        $plans = $this->cal('2026-10-01');

        $this->assertSame(['expense' => 4000, 'income' => 0], $plans['2026-10-05']);
    }

    public function test_calendar_plans_sum_items_on_same_day(): void
    {
        $a = $this->bank(0);
        $this->recurring($a, ['day' => 20, 'amount' => 10000]);
        $this->recurring($a, ['day' => 20, 'amount' => 2500]);
        $this->recurring($a, ['type' => RecordType::Income, 'day' => 20, 'amount' => 90000]);

        $this->assertSame(['expense' => 12500, 'income' => 90000], $this->cal('2026-10-01')['2026-10-20']);
    }

    public function test_calendar_plans_do_not_include_manual_future_records(): void
    {
        // 手で入れた先の日付の記録は、ふつうの記録の字でカレンダーに出る（灰色の予定ではない）
        $a = $this->bank(0);
        $this->manual(RecordType::Expense, '2026-10-20', 5000, $a);

        $this->assertSame([], $this->cal('2026-10-01'));
    }

    public function test_calendar_plans_work_for_another_month_with_any_day_of_month(): void
    {
        // 「$month の中の日」で決める。月のどの日を渡しても同じ
        $a = $this->bank(0);
        $this->recurring($a, ['day' => 20, 'amount' => 10000]);

        $plans = $this->cal('2026-11-15');

        $this->assertSame(['2026-11-20'], array_keys($plans));
        $this->assertSame(['expense' => 10000, 'income' => 0], $plans['2026-11-20']);
    }
}
