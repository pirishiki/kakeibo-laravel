<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Livewire\AdjustBalance;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\RecurringSkip;
use App\Models\User;
use App\Support\AvailableAmount;
use App\Support\RecurringRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * お金の場面の固定（実装は見ずに、設計書の決まりだけを根拠に書いた）。
 * 今の実装で通るはずの場面で、落ちたら実装のバグを疑う。
 * 今日は 2026年10月24日（土）12:00。
 * 2026年のカレンダー：10/30 金、10/31 土、11/1 日、9/5 土（次の平日は 9/7 月）、10/5 月、11/5 木
 */
class MoneyScenesGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0, 0));
    }

    private function parent(string $name): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', $name)->firstOrFail();
    }

    /** 手で入れた支出（分類と相手つき） */
    private function expense(Account $account, string $date, int $amount): Record
    {
        return Record::factory()->create([
            'date' => $date, 'type' => RecordType::Expense, 'amount' => $amount,
            'account_id' => $account->id,
            'category_id' => $this->parent('食費')->id,
            'partner_id' => Partner::factory()->create()->id,
        ]);
    }

    /** 毎月 $day 日の、決まった金額の支出の定期的な記録（口座・分類・相手つき） */
    private function recurringExpense(Account $account, array $attributes = []): Recurring
    {
        return Recurring::factory()->create([
            'name' => '予定', 'type' => RecordType::Expense, 'day' => 5, 'shift' => RecurringShift::Next,
            'is_fixed' => true, 'amount' => 50000, 'estimated_amount' => null,
            'account_id' => $account->id, 'to_account_id' => null,
            'category_id' => $this->parent('食費')->id,
            'partner_id' => Partner::factory()->create()->id,
            'start_month' => '2026-09-01', 'end_month' => null, 'is_active' => true,
            ...$attributes,
        ]);
    }

    // ==================================================================
    // 場面 1：カードへの支払いと、先の日付の支出が、使える額で二重に数えられない
    // 根拠：画面設計書 5.2「計算の決まり」（今の残高は今日までの記録／使える額＝今の合計残高−取っておく額−今月末までの予定／
    //   予定には手で入れた明日から今月末までの支出をふくむ）、5.6（クレジットカードはマイナスの残高も口座の残高）。
    //   要件定義書 5.5「カードの引き落とし（銀行口座→カードの移動）は引かない」
    //
    // 手計算（今日 10/24）：
    //   銀行   最初 200,000 − 移動 30,000（10/20）                    ＝ 170,000
    //   カード 最初 -30,000 − 支出 12,000（10/10）＋ 移動 30,000（10/20）＝ -12,000
    //   合計 ＝ 170,000 + (-12,000) ＝ 158,000
    //   予定 ＝ カードの先の日付の支出 5,000（10/28）を 1 回だけ ＝ 5,000
    //   使える額 ＝ 158,000 − 0 − 5,000 ＝ 153,000
    //   1 日あたり ＝ 153,000 ÷ 8 日（10/24〜10/31、今日をふくむ）＝ 19,125、1 週間あたり ＝ 19,125 × 7 ＝ 133,875
    // ==================================================================

    public function test_カードへの支払いと先の日付の支出は_使える額で二重に数えられない(): void
    {
        $bank = Account::factory()->create(['type' => AccountType::Bank, 'initial_balance' => 200000]);
        $card = Account::factory()->create(['type' => AccountType::CreditCard, 'initial_balance' => -30000]);

        // 今日までの記録：カードで買い物（10/10）と、銀行からカードへの支払い（10/20）
        $this->expense($card, '2026-10-10', 12000);
        Record::factory()->create([
            'date' => '2026-10-20', 'type' => RecordType::Transfer, 'amount' => 30000,
            'account_id' => $bank->id, 'to_account_id' => $card->id,
        ]);
        // 先の日付（今月末まで）の、手で入れたカードの支出
        $future = $this->expense($card, '2026-10-28', 5000);

        $r = AvailableAmount::calculate(CarbonImmutable::parse('2026-10-24'));

        $this->assertSame(158000, $r['total'], '合計はカードのマイナス残高をふくむ。移動は合計を変えない');
        $this->assertSame(0, $r['reserved']);
        $this->assertSame(5000, $r['planned'], '先の日付の支出は 1 回だけ引く');
        $this->assertCount(1, $r['plans']);
        $this->assertSame($future->id, $r['plans'][0]['recordId']);
        $this->assertSame(5000, $r['plans'][0]['amount']);
        $this->assertSame(153000, $r['available']);

        // ホームの画面にも、同じ数字が出る
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['使える額', '153,000', '1日あたり', '19,125', '1週間', '133,875'])
            ->assertSeeInOrder(['今の合計', '158,000', '− 取っておく額', '0', '− 今月末までの予定（1 件）', '5,000']);
    }

    // ==================================================================
    // 場面 2：「決まっている」から「毎月変わる」に変えて保存
    // 根拠：データベース設計書 3.6（変更して保存すると、まだ記録されていない月だけが新しい内容）、3.7（概算＝前回の実際の金額、
    //   なければ登録時の概算。金額の行は入れた月だけ）、画面設計書 5.10「決まり」（記録された月は変わらない／
    //   小窓は「毎月変わる」→「決まっている」のときだけ）、5.2（日付が過ぎたのに金額がまだの予定は概算で引く）
    //
    // 9 月分（52,000）は自動で記録済み。10 月・11 月は未記録。毎月 5 日・次の平日。
    //   10 月の分は 10/5 が過ぎているので、金額がまだ＝「前回の実際の金額」52,000 を概算として今月末までの予定に引く。
    //   11 月の分（11/5）は来月なので、今月の使える額には入らない
    // ==================================================================

    public function test_決まっているから毎月変わるに変えて保存しても_記録済みの月は変わらず_まだの月は概算で予定になる(): void
    {
        $bank = Account::factory()->create(['type' => AccountType::Bank, 'initial_balance' => 500000]);
        $recurring = $this->recurringExpense($bank, ['name' => '電気代', 'amount' => 50000]);

        // 9 月の分は自動で記録済み（5 日は土曜なので次の平日の 9/7）
        $sep = $this->expense($bank, '2026-09-07', 52000);
        $sep->forceFill(['recurring_id' => $recurring->id, 'recurring_month' => '2026-09-01'])->save();

        Livewire::test(RecurringForm::class, ['recurring' => $recurring])
            ->set('isFixed', '0')->set('estimatedAmount', '8000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('fixedNotice', '')
            ->assertRedirect();

        $recurring->refresh();
        $this->assertFalse($recurring->is_fixed);
        $this->assertSame(8000, $recurring->estimated_amount);

        // 9 月の記録は、金額も日付もそのまま・増えも減りもしない
        $this->assertSame(1, Record::query()->count());
        $this->assertSame(52000, $sep->refresh()->amount);
        $this->assertSame('2026-09-07', $sep->date->toDateString());
        // 10 月・11 月には金額の行がない（保存で作られない）
        $this->assertSame(0, RecurringAmount::query()->count());

        // 使える額：10 月の分だけが、概算（前回の実際の金額 52,000）で予定に入る。11 月の分は入らない
        $r = AvailableAmount::calculate(CarbonImmutable::parse('2026-10-24'));
        $this->assertSame(448000, $r['total'], '合計は 9 月の記録（52,000）を引いた額');
        $this->assertCount(1, $r['plans']);
        $this->assertSame($recurring->id, $r['plans'][0]['recurringId']);
        $this->assertSame('2026-10-05', $r['plans'][0]['date']->toDateString());
        $this->assertTrue($r['plans'][0]['estimated']);
        $this->assertSame(52000, $r['plans'][0]['amount']);
        $this->assertSame(52000, $r['planned']);

        // ホームのお知らせにも「金額がまだ」が出る（10 月の分）
        $this->get('/')->assertOk()->assertSee('金額がまだのものが 1 件あります');
    }

    // ==================================================================
    // 場面 3：残高がマイナスのときの貯金の目標
    // 根拠：画面設計書 5.13「進み具合（%）…口座の残高がマイナスのときは 0% とし、棒は出さない」「1 か月あたり＝残りの額÷残りの月数
    //   （今月をふくむ）、1 円未満は切り上げ」
    //
    // 目標 100,000、残高 -5,000、期限 2027年2月、今日 2026年10月24日。
    //   残りの額 ＝ 100,000 − (-5,000) ＝ 105,000、残りの月数 ＝ 10,11,12,1,2 の 5 か月、105,000 ÷ 5 ＝ 21,000
    //   （残高を 0 とみなして 100,000 ÷ 5 ＝ 20,000 にはならない。設計書は残高を 0 に直すとは書いていない）
    // ==================================================================

    public function test_残高がマイナスの貯金の目標は_進み具合0パーセントで_1か月あたりは目標額との差から数える(): void
    {
        $account = Account::factory()->create(['name' => '貯金用A', 'type' => AccountType::Bank, 'initial_balance' => -5000]);
        Goal::create(['name' => '旅行', 'target_amount' => 100000, 'account_id' => $account->id, 'deadline_month' => '2027-02-01']);

        $this->get('/goals')
            ->assertOk()
            ->assertSee('（0%）', false)
            ->assertDontSee('-1%')
            ->assertDontSee('✓ 達成')
            ->assertSee('2027年2月')
            ->assertSee('あと 5 か月')
            ->assertSee('1 か月あたり 21,000')
            ->assertDontSee('1 か月あたり 20,000');

        // ホームの貯金の目標のカードも 0%
        $this->get('/')->assertOk()->assertSeeInOrder(['貯金の目標', '旅行', '0%']);
    }

    // ==================================================================
    // 場面 4：前の平日にずれて前の月になる分と「今回はなし」
    // 根拠：データベース設計書 3.6（記録する日＝ずらしたあとの日。「今回はなし」の月は記録しない）、3.8、
    //   画面設計書 5.10（ずらした結果がとなりの月になっても、もとの予定の月の分。予定と使える額は実際にお金が動く日で数える）、
    //   5.12・5.2（「今回はなし」にした分は使える額にも入らない）
    //
    // 毎月 1 日・前の平日・20,000 円。2026-11-01 は日曜なので 2026-10-30（金）に動く。最初の月は 11 月
    // ==================================================================

    private function novemberFirst(Account $account): Recurring
    {
        return $this->recurringExpense($account, [
            'name' => '引き落とし', 'day' => 1, 'shift' => RecurringShift::Previous,
            'amount' => 20000, 'start_month' => '2026-11-01',
        ]);
    }

    public function test_11月分を今回はなしにすると_10月30日を過ぎても記録されず_使える額の予定にも入らない(): void
    {
        $bank = Account::factory()->create(['type' => AccountType::Bank, 'initial_balance' => 100000]);
        $recurring = $this->novemberFirst($bank);
        RecurringSkip::create(['recurring_id' => $recurring->id, 'month' => '2026-11-01']);

        // (a) 10/30 当日・10/31（動く日を過ぎた）・翌月になっても、記録する月にならず、記録もされない
        foreach (['2026-10-30', '2026-10-31', '2026-11-05'] as $today) {
            $this->assertSame([], RecurringRecorder::dueMonths($recurring, CarbonImmutable::parse($today)), $today.' は記録する月がない');
            $this->assertSame(0, RecurringRecorder::recordAllDue(CarbonImmutable::parse($today)), $today.' は 0 件');
        }
        $this->assertSame(0, Record::query()->count());

        // (b) 使える額の予定に入らない
        $r = AvailableAmount::calculate(CarbonImmutable::parse('2026-10-24'));
        $this->assertSame(0, $r['planned']);
        $this->assertSame([], $r['plans']);
        $this->assertSame(100000, $r['available']);
    }

    public function test_今回はなしにしていない11月分は_10月29日までは記録されず_10月30日から記録される(): void
    {
        $bank = Account::factory()->create(['type' => AccountType::Bank, 'initial_balance' => 100000]);
        $recurring = $this->novemberFirst($bank);

        // 今日（10/24）の使える額には、10/30 に動く 11 月分が予定として入る（お金が動く日で数える）
        $r = AvailableAmount::calculate(CarbonImmutable::parse('2026-10-24'));
        $this->assertSame(20000, $r['planned']);
        $this->assertSame('2026-10-30', $r['plans'][0]['date']->toDateString());
        $this->assertSame(80000, $r['available']);

        // 10/29 まではまだ（動く日の前）
        $this->assertSame([], RecurringRecorder::dueMonths($recurring, CarbonImmutable::parse('2026-10-29')));
        $this->assertSame(0, RecurringRecorder::recordAllDue(CarbonImmutable::parse('2026-10-29')));

        // 10/31 に動かすと、11 月の分（もとの予定の月）が 10/30 の日付で 1 件だけ記録される
        $this->assertSame(['2026-11-01'], array_map(fn (CarbonImmutable $m) => $m->toDateString(), RecurringRecorder::dueMonths($recurring, CarbonImmutable::parse('2026-10-31'))));
        $this->assertSame(1, RecurringRecorder::recordAllDue(CarbonImmutable::parse('2026-10-31')));
        $record = Record::query()->sole();
        $this->assertSame('2026-10-30', $record->date->toDateString());
        $this->assertSame('2026-11-01', CarbonImmutable::parse($record->getAttribute('recurring_month'))->toDateString());
        $this->assertSame(20000, $record->amount);

        // もう一度動かしても増えない
        $this->assertSame(0, RecurringRecorder::recordAllDue(CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(1, Record::query()->count());
    }

    // ==================================================================
    // 場面 5：調整の記録の日付だけを編集で変える
    // 根拠：画面設計書 5.4（調整の記録の編集：金額・日付・口座・メモ。「使わない」口座の記録は、残高が 0 円でなくなる直し
    //   〔金額・口座・日付を変える〕はできない）、5.7（「アプリの残高」は選んだ日の終わりまでの残高。調整は最初の残高を書きかえない）、
    //   5.2（今の残高は今日までの記録で計算）、データベース設計書 3.2（残高＝最初の残高＋記録＋調整）
    //
    // 現金 最初 10,000。10/5 支出 1,000、10/15 支出 500、10/20 調整 -2,000。
    //   その日の終わりまでの残高：10/12 ＝ 9,000、10/17 ＝ 8,500、今日（10/24）＝ 6,500
    //   調整の日付だけを 10/12 に変えると：10/12 ＝ 7,000、10/17 ＝ 6,500（途中の日だけが動く）。今日は 6,500 のまま
    // ==================================================================

    public function test_調整の記録の日付だけを変えると_最終残高は変わらず_途中の日の残高だけが動く(): void
    {
        $cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 10000]);
        $this->expense($cash, '2026-10-05', 1000);
        $this->expense($cash, '2026-10-15', 500);
        $adjustment = Record::factory()->create([
            'date' => '2026-10-20', 'type' => RecordType::Adjustment, 'amount' => -2000, 'account_id' => $cash->id,
        ]);

        // 変える前の途中の日の残高（残高を合わせる画面の「アプリの残高」）
        Livewire::test(AdjustBalance::class, ['account' => $cash])
            ->assertSet('appBalance', 6500)
            ->set('date', '2026-10-12')->assertSet('appBalance', 9000)
            ->set('date', '2026-10-17')->assertSet('appBalance', 8500);

        // 調整の日付だけを 10/12 に変えて保存（金額・口座・メモはそのまま）
        Livewire::test(RecordForm::class, ['record' => $adjustment])
            ->assertSet('type', 'adjustment')
            ->set('date', '2026-10-12')
            ->call('save')
            ->assertHasNoErrors();

        $adjustment->refresh();
        $this->assertSame('2026-10-12', $adjustment->date->toDateString());
        $this->assertSame(-2000, $adjustment->amount);
        $this->assertSame($cash->id, $adjustment->account_id);

        // 今日までの残高（最終残高）は変わらない：口座の管理の画面とアプリの残高
        $this->get('/accounts')->assertOk()->assertSee('6,500');
        // 途中の日の残高だけが動く
        Livewire::test(AdjustBalance::class, ['account' => $cash])
            ->assertSet('appBalance', 6500)
            ->set('date', '2026-10-12')->assertSet('appBalance', 7000)
            ->set('date', '2026-10-17')->assertSet('appBalance', 6500)
            // 調整より前の日（10/10）は、まだ調整が入らない
            ->set('date', '2026-10-10')->assertSet('appBalance', 9000);
    }

    /**
     * 「使わない」口座（いつも 0 円）の調整の記録：
     *   最初 0、10/1 に調整 +5,000、10/10 に支出 5,000 → 今日の残高 0 円で「使わない」にした
     */
    private function inactiveAccountWithAdjustment(): Record
    {
        $old = Account::factory()->create(['name' => '旧カード', 'type' => AccountType::Bank, 'initial_balance' => 0]);
        $adjustment = Record::factory()->create([
            'date' => '2026-10-01', 'type' => RecordType::Adjustment, 'amount' => 5000, 'account_id' => $old->id,
        ]);
        $this->expense($old, '2026-10-10', 5000);
        $old->update(['is_active' => false]);

        return $adjustment;
    }

    public function test_使わない口座の調整の記録は_今日の残高が0円でなくなる日付の変更はできず理由が出る(): void
    {
        $adjustment = $this->inactiveAccountWithAdjustment();

        // 調整を 10/28（今日より先）に動かすと、今日の残高が -5,000 円になる。5.4 の「0 円でなくなる直し」の中でもはっきりした場面
        Livewire::test(RecordForm::class, ['record' => $adjustment])
            ->set('date', '2026-10-28')
            ->call('save')
            ->assertHasErrors('blocker')
            ->assertSee('使わない');

        $this->assertSame('2026-10-01', $adjustment->refresh()->date->toDateString());
    }

    /**
     * 設計書の読み方が分かれる場面：5.4 は「金額・口座・日付を変える」を、まとめて「0 円でなくなる直し」と書いている。
     * 日付を過去の別の日に動かすだけだと、今日の残高は 0 円のまま（途中の日の残高だけが動く）。
     * 「日付を変える」を文字どおり禁止と読んで、保存できないことを確かめる（通れば、設計書の読み方を見直す）
     */
    public function test_使わない口座の調整の記録は_過去の別の日への日付の変更も保存できない(): void
    {
        $adjustment = $this->inactiveAccountWithAdjustment();

        // 10/10 の支出をまたいで 10/15 に動かす（10/10 の終わりの残高が -5,000 円になる）
        Livewire::test(RecordForm::class, ['record' => $adjustment])
            ->set('date', '2026-10-15')
            ->call('save')
            ->assertHasErrors('blocker')
            ->assertSee('使わない');

        $this->assertSame('2026-10-01', $adjustment->refresh()->date->toDateString());
    }
}
