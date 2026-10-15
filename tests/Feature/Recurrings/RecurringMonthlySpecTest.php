<?php

namespace Tests\Feature\Recurrings;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\RecurringSkip;
use App\Support\RecurringMonthly;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 月ごとの金額・今回はなし・ホームの「金額がまだ」のお知らせを、設計書だけから確かめる
 * （画面設計書 5.2「お知らせ」・5.10「月ごとの金額」、要件定義書 10.2、データベース設計書 3.6〜3.8）
 * 今日の日付は、テストごとに決めて渡す（本物の時計にたよらない）
 *
 * 2026 年のカレンダー（このテストで使う日）
 * - 8月26日（水）、9月26日（土）→ 9月28日（月）、9月23日（水・秋分の日）
 * - 10月1日（木）、10月15日（木）、10月20日（火）、10月25日（日）→ 前の平日 10月23日（金）、10月26日（月）、10月27日（火）
 * - 10月30日（金）、10月31日（土）→ 次の平日 11月2日（月）、11月1日（日）→ 前の平日 10月30日（金）
 * - 11月3日（火・文化の日）、11月26日（木）、11月30日（月）
 * - 12月10日（木）、12月31日（木・年末年始の休み）→ 次の平日 2027年1月4日（月）
 * - 2027年1月10日（日）→ 1月11日（月・成人の日）→ 1月12日（火）
 */
class RecurringMonthlySpecTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Category $food;

    private Category $salaryCategory;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        // 本物の時計を使っていないことがわかるように、今日はずっと先の日にしておく
        $this->travelTo(now()->setDate(2030, 1, 15)->setTime(12, 0));
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行']);
        $this->food = Category::factory()->create(['name' => '食費のテスト', 'kind' => CategoryKind::Expense]);
        $this->salaryCategory = Category::factory()->create(['name' => '給料のテスト', 'kind' => CategoryKind::Income]);
        $this->shop = Partner::factory()->create(['name' => '生協']);
    }

    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfDay();
    }

    /** 毎月 20 日・次の平日・決まった金額 5,800 円の支出。2026 年 8 月から */
    private function fixed(array $attributes = []): Recurring
    {
        return Recurring::factory()->create([
            'name' => '通信費', 'type' => RecordType::Expense, 'day' => 20, 'shift' => RecurringShift::Next,
            'is_fixed' => true, 'amount' => 5800, 'estimated_amount' => null,
            'account_id' => $this->bank->id, 'to_account_id' => null,
            'category_id' => $this->food->id, 'partner_id' => $this->shop->id,
            'start_month' => '2026-08-01', 'end_month' => null, 'is_active' => true, ...$attributes,
        ]);
    }

    /** 毎月 26 日・次の平日・金額が変わる支出（概算 9,200 円）。2026 年 8 月から */
    private function variable(array $attributes = []): Recurring
    {
        return $this->fixed([
            'name' => '電気代', 'day' => 26, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 9200, ...$attributes,
        ]);
    }

    /** 毎月 25 日・前の平日・金額が変わる収入（概算 240,000 円） */
    private function salary(array $attributes = []): Recurring
    {
        return $this->variable([
            'name' => '給料', 'type' => RecordType::Income, 'day' => 25, 'shift' => RecurringShift::Previous,
            'estimated_amount' => 240000, 'category_id' => $this->salaryCategory->id, ...$attributes,
        ]);
    }

    /** 自動で記録されたことにする */
    private function recordedBefore(Recurring $recurring, string $month, string $date, int $amount): Record
    {
        $record = Record::factory()->create([
            'type' => $recurring->type, 'amount' => $amount, 'date' => $date,
            'account_id' => $recurring->account_id, 'category_id' => $recurring->category_id, 'partner_id' => $recurring->partner_id,
        ]);
        $record->forceFill(['recurring_id' => $recurring->id, 'recurring_month' => $month])->save();

        return $record;
    }

    private function enterAmount(Recurring $recurring, string $month, int $amount): void
    {
        RecurringAmount::query()->create(['recurring_id' => $recurring->id, 'month' => $month, 'amount' => $amount]);
    }

    private function skipMonth(Recurring $recurring, string $month): void
    {
        RecurringSkip::query()->create(['recurring_id' => $recurring->id, 'month' => $month]);
    }

    private static function ymd(mixed $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->toDateString();
    }

    /**
     * rows() の結果を、比べやすい形にする
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $month): array
    {
        return array_map(fn (array $row) => [
            'name' => $row['recurring']->name,
            'month' => self::ymd($row['month']),
            'date' => self::ymd($row['date']),
            'reason' => $row['reason'],
            'status' => $row['status'],
            'amount' => $row['amount'],
            'estimate' => $row['estimate'],
        ], array_values(RecurringMonthly::rows(self::day($month))));
    }

    /** rows() の中から、その予定の行を 1 つ出す（なければ null） */
    private function rowOf(Recurring $recurring, string $month): ?array
    {
        foreach (RecurringMonthly::rows(self::day($month)) as $row) {
            if ($row['recurring']->id === $recurring->id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * pending() の結果を、比べやすい形にする
     *
     * @return list<array<string, mixed>>
     */
    private function pending(string $today): array
    {
        return array_map(fn (array $item) => [
            'name' => $item['recurring']->name,
            'month' => self::ymd($item['month']),
            'date' => self::ymd($item['date']),
            'estimate' => $item['estimate'],
            'label' => $item['label'],
        ], array_values(RecurringMonthly::pending(self::day($today))));
    }

    /**
     * その予定の記録を「何月の分 => [日付, 金額]」で出す
     *
     * @return array<string, array{0: string, 1: int}>
     */
    private function recorded(Recurring $recurring): array
    {
        return Record::query()->where('recurring_id', $recurring->id)->orderBy('recurring_month')->get()
            ->mapWithKeys(fn (Record $r) => [
                CarbonImmutable::parse($r->getAttribute('recurring_month'))->format('Y-m') => [$r->date->toDateString(), $r->amount],
            ])->all();
    }

    private function amountRow(Recurring $recurring, string $month): ?int
    {
        $row = RecurringAmount::query()->where('recurring_id', $recurring->id)->whereDate('month', $month)->first();

        return $row?->amount;
    }

    private function isSkipped(Recurring $recurring, string $month): bool
    {
        return RecurringSkip::query()->where('recurring_id', $recurring->id)->whereDate('month', $month)->exists();
    }

    // ---- 月ごとの金額の行（画面設計書 5.10） ----

    public function test_画面の例のとおり_記録する日の順に_状態と金額が出る(): void
    {
        // わざと登録する順をばらばらにする
        $cardB = $this->variable(['name' => 'カードB の引き落とし', 'day' => 27, 'estimated_amount' => 30000]);
        $electricity = $this->variable();
        $salary = $this->salary();
        $phone = $this->fixed();
        $cardA = $this->variable(['name' => 'カードA の引き落とし', 'day' => 15, 'estimated_amount' => 50000]);

        $this->recordedBefore($cardA, '2026-10-01', '2026-10-15', 52300);
        $this->enterAmount($salary, '2026-10-01', 250000);
        $this->skipMonth($cardB, '2026-10-01');

        $this->assertSame([
            ['name' => 'カードA の引き落とし', 'month' => '2026-10-01', 'date' => '2026-10-15', 'reason' => null,
                'status' => 'recorded', 'amount' => 52300, 'estimate' => 52300],
            ['name' => '通信費', 'month' => '2026-10-01', 'date' => '2026-10-20', 'reason' => null,
                'status' => 'fixed', 'amount' => 5800, 'estimate' => null],
            ['name' => '給料', 'month' => '2026-10-01', 'date' => '2026-10-23', 'reason' => '25日が日曜のため前の平日',
                'status' => 'variable', 'amount' => 250000, 'estimate' => 240000],
            ['name' => '電気代', 'month' => '2026-10-01', 'date' => '2026-10-26', 'reason' => null,
                'status' => 'variable', 'amount' => null, 'estimate' => 9200],
            ['name' => 'カードB の引き落とし', 'month' => '2026-10-01', 'date' => '2026-10-27', 'reason' => null,
                'status' => 'skipped', 'amount' => null, 'estimate' => 30000],
        ], $this->rows('2026-10-01'));
    }

    public function test_記録済みの行は_記録の金額と記録のidを出し_記録を直すとここも変わる(): void
    {
        $phone = $this->fixed();
        $record = $this->recordedBefore($phone, '2026-10-01', '2026-10-20', 5800);

        $row = $this->rowOf($phone, '2026-10-01');
        $this->assertSame('recorded', $row['status']);
        $this->assertSame($record->id, $row['recordId']);

        // 記録の編集で、その月だけ金額を直した
        $record->update(['amount' => 6100]);
        $this->assertSame(6100, $this->rowOf($phone, '2026-10-01')['amount']);
        $this->assertSame('recorded', $this->rowOf($phone, '2026-10-01')['status']);
    }

    public function test_記録していない行の記録のidは空(): void
    {
        $phone = $this->fixed();
        $electricity = $this->variable();

        $this->assertNull($this->rowOf($phone, '2026-10-01')['recordId']);
        $this->assertNull($this->rowOf($electricity, '2026-10-01')['recordId']);
    }

    public function test_金額が変わる行の概算は_前回の記録の金額_なければ登録したときの概算(): void
    {
        $electricity = $this->variable();
        $this->assertSame(9200, $this->rowOf($electricity, '2026-10-01')['estimate']);

        $this->recordedBefore($electricity, '2026-08-01', '2026-08-26', 8800);
        $this->recordedBefore($electricity, '2026-09-01', '2026-09-28', 9500);

        $this->assertSame(9500, $this->rowOf($electricity, '2026-10-01')['estimate']);
        $this->assertNull($this->rowOf($electricity, '2026-10-01')['amount']);
    }

    public function test_先月が今回はなしでも_概算は前回の記録の金額(): void
    {
        $electricity = $this->variable();
        $this->recordedBefore($electricity, '2026-08-01', '2026-08-26', 8800);
        $this->skipMonth($electricity, '2026-09-01');

        $this->assertSame(8800, $this->rowOf($electricity, '2026-10-01')['estimate']);
    }

    public function test_ずらした日の理由が出る_土曜_日曜_祝日_年末年始(): void
    {
        // 31日（月末）・次の平日：10月31日（土）→ 11月2日（月）
        $rent = $this->fixed(['name' => '家賃', 'day' => 31]);
        // 1日・前の平日：11月1日（日）→ 10月30日（金）
        $loan = $this->fixed(['name' => 'ローン', 'day' => 1, 'shift' => RecurringShift::Previous]);
        // 3日・次の平日：11月3日（火・文化の日）→ 11月4日（水）
        $water = $this->fixed(['name' => '水道代', 'day' => 3]);

        $this->assertSame(['2026-11-02', '31日が土曜のため次の平日'], [self::ymd($this->rowOf($rent, '2026-10-01')['date']), $this->rowOf($rent, '2026-10-01')['reason']]);
        $this->assertSame(['2026-10-30', '1日が日曜のため前の平日'], [self::ymd($this->rowOf($loan, '2026-11-01')['date']), $this->rowOf($loan, '2026-11-01')['reason']]);
        $this->assertSame(['2026-11-04', '3日が祝日のため次の平日'], [self::ymd($this->rowOf($water, '2026-11-01')['date']), $this->rowOf($water, '2026-11-01')['reason']]);
        // 12月31日（木）は祝日ではないが、年末年始の休み → 2027年1月4日（月）
        $this->assertSame(['2027-01-04', '31日が年末年始の休みのため次の平日'], [self::ymd($this->rowOf($rent, '2026-12-01')['date']), $this->rowOf($rent, '2026-12-01')['reason']]);
        // ずらさないときは、理由が空
        $this->assertSame(['2026-11-30', null], [self::ymd($this->rowOf($rent, '2026-11-01')['date']), $this->rowOf($rent, '2026-11-01')['reason']]);
    }

    public function test_ずらした日が祝日のときの理由と_祝日が続くときは平日までずれる(): void
    {
        // 23日・次の平日：9月23日（水・秋分の日）→ 9月24日（木）
        $next = $this->fixed(['name' => '次の平日の予定', 'day' => 23]);
        // 23日・前の平日：9月22日（国民の休日）・21日（敬老の日）・20日（日）・19日（土）をこえて 9月18日（金）
        $previous = $this->fixed(['name' => '前の平日の予定', 'day' => 23, 'shift' => RecurringShift::Previous]);

        $this->assertSame('2026-09-24', self::ymd($this->rowOf($next, '2026-09-01')['date']));
        $this->assertSame('23日が祝日のため次の平日', $this->rowOf($next, '2026-09-01')['reason']);
        $this->assertSame('2026-09-18', self::ymd($this->rowOf($previous, '2026-09-01')['date']));
        $this->assertSame('23日が祝日のため前の平日', $this->rowOf($previous, '2026-09-01')['reason']);
    }

    public function test_記録する日がとなりの月になっても_もとの予定の月に1回だけ出る(): void
    {
        $rent = $this->fixed(['name' => '家賃', 'day' => 31]);

        $october = array_values(array_filter($this->rows('2026-10-01'), fn ($r) => $r['name'] === '家賃'));
        $november = array_values(array_filter($this->rows('2026-11-01'), fn ($r) => $r['name'] === '家賃'));

        $this->assertCount(1, $october);
        $this->assertSame(['2026-10-01', '2026-11-02'], [$october[0]['month'], $october[0]['date']]);
        $this->assertCount(1, $november);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$november[0]['month'], $november[0]['date']]);
    }

    public function test_記録の日付を次の月に直しても_もとの予定の月で記録済みと出る(): void
    {
        $phone = $this->fixed();
        $record = $this->recordedBefore($phone, '2026-10-01', '2026-10-20', 5800);
        $record->update(['date' => '2026-11-05']);

        $this->assertSame('recorded', $this->rowOf($phone, '2026-10-01')['status']);
        // 11 月の行は 11 月の分で、まだ記録されていない
        $this->assertSame('fixed', $this->rowOf($phone, '2026-11-01')['status']);
        $this->assertNull($this->rowOf($phone, '2026-11-01')['recordId']);
    }

    public function test_行は記録する日の順で_月をまたいだものは最後に並ぶ(): void
    {
        $this->fixed(['name' => '家賃', 'day' => 31]);
        $this->fixed(['name' => '月の始め', 'day' => 1]);
        $this->fixed(['name' => '通信費']);

        $this->assertSame(['月の始め', '通信費', '家賃'], array_column($this->rows('2026-10-01'), 'name'));
    }

    public function test_最初の月より前と最後の月より後には出ない(): void
    {
        $this->fixed(['name' => '11月から', 'start_month' => '2026-11-01']);
        $this->fixed(['name' => '9月まで', 'end_month' => '2026-09-01']);
        $this->fixed(['name' => '10月まで', 'end_month' => '2026-10-01']);

        $this->assertSame(['10月まで'], array_column($this->rows('2026-10-01'), 'name'));
        $this->assertSame(['11月から'], array_column($this->rows('2026-11-01'), 'name'));
        $this->assertSame(['9月まで', '10月まで'], array_column($this->rows('2026-09-01'), 'name'));
    }

    public function test_選んだ月だけの予定は_選んだ月にだけ出る(): void
    {
        $this->fixed(['name' => '賞与', 'months' => [6, 12]]);

        $this->assertSame([], $this->rows('2026-10-01'));
        $this->assertSame(['賞与'], array_column($this->rows('2026-12-01'), 'name'));
    }

    public function test_予定のない月は空(): void
    {
        $this->fixed(['start_month' => '2026-11-01']);

        $this->assertSame([], $this->rows('2026-10-01'));
    }

    public function test_使わないの予定は_記録済みか今回はなしの月だけ出る(): void
    {
        $recurring = $this->fixed(['name' => '使わなくなった予定', 'is_active' => false]);
        $this->recordedBefore($recurring, '2026-08-01', '2026-08-20', 5800);
        $this->skipMonth($recurring, '2026-09-01');

        $this->assertSame('recorded', $this->rowOf($recurring, '2026-08-01')['status']);
        $this->assertSame('skipped', $this->rowOf($recurring, '2026-09-01')['status']);
        $this->assertNull($this->rowOf($recurring, '2026-10-01'));
        $this->assertSame([], $this->rows('2026-10-01'));
    }

    public function test_今回はなしの金額が変わる行は_入れてあった金額を残して出す(): void
    {
        $electricity = $this->variable();
        $this->enterAmount($electricity, '2026-10-01', 9300);
        $this->skipMonth($electricity, '2026-10-01');

        $row = $this->rowOf($electricity, '2026-10-01');
        $this->assertSame('skipped', $row['status']);
        $this->assertSame(9300, $row['amount']);
    }

    public function test_今日の日付が変わっても_状態と概算は変わらない(): void
    {
        $electricity = $this->variable();
        $phone = $this->fixed();

        $this->travelTo(self::day('2026-10-01')->setTime(12, 0));
        $before = $this->rows('2026-10-01');
        $this->travelTo(self::day('2026-10-31')->setTime(12, 0));
        $after = $this->rows('2026-10-01');

        $this->assertSame($before, $after);
        $this->assertSame(['fixed', 'variable'], array_column($after, 'status'));
    }

    // ---- 金額がまだのお知らせ（画面設計書 5.2） ----

    public function test_金額がまだのものは_過ぎた月の分をいちばん上に_月の印をつけて出す(): void
    {
        // 26日・次の平日：8月26日（水）、9月26日（土）→ 9月28日（月）、10月26日（月）、11月26日（木）
        $this->variable();

        $this->assertSame([
            ['name' => '電気代', 'month' => '2026-08-01', 'date' => '2026-08-26', 'estimate' => 9200, 'label' => '8月の分'],
            ['name' => '電気代', 'month' => '2026-09-01', 'date' => '2026-09-28', 'estimate' => 9200, 'label' => '9月の分'],
            ['name' => '電気代', 'month' => '2026-10-01', 'date' => '2026-10-26', 'estimate' => 9200, 'label' => null],
        ], $this->pending('2026-10-15'));
    }

    public function test_今月の分は日付が過ぎたものもふくみ_今月の分どうしは日付の順(): void
    {
        $this->variable(['name' => '電気代', 'start_month' => '2026-10-01']);
        $this->variable(['name' => 'ガス代', 'day' => 5, 'start_month' => '2026-10-01']);
        $this->variable(['name' => '水道代', 'day' => 20, 'start_month' => '2026-10-01']);

        $this->assertSame(
            [['ガス代', '2026-10-05', null], ['水道代', '2026-10-20', null], ['電気代', '2026-10-26', null]],
            array_map(fn ($i) => [$i['name'], $i['date'], $i['label']], $this->pending('2026-10-15')),
        );
    }

    public function test_過ぎた月の分が今月の分より上で_それぞれ日付の順(): void
    {
        // 9月 28 日の分（過ぎた月）と、10月 1 日の分（今月）
        $this->variable(['name' => '電気代', 'start_month' => '2026-09-01', 'end_month' => '2026-09-01']);
        $this->variable(['name' => '月の始め', 'day' => 1, 'start_month' => '2026-10-01']);
        // 8 月の分（いちばん古い過ぎた月）
        $this->variable(['name' => 'ガス代', 'day' => 5, 'start_month' => '2026-08-01', 'end_month' => '2026-08-01']);

        $this->assertSame(['ガス代', '電気代', '月の始め'], array_column($this->pending('2026-10-15'), 'name'));
    }

    public function test_前の平日にずれて今月に動く来月分は_今月のお知らせに来月の印で出る(): void
    {
        // 1日・前の平日：10月1日（木）、11月1日（日）→ 10月30日（金）
        $this->variable(['name' => 'ローン', 'day' => 1, 'shift' => RecurringShift::Previous, 'start_month' => '2026-10-01']);

        $this->assertSame([
            ['name' => 'ローン', 'month' => '2026-10-01', 'date' => '2026-10-01', 'estimate' => 9200, 'label' => null],
            ['name' => 'ローン', 'month' => '2026-11-01', 'date' => '2026-10-30', 'estimate' => 9200, 'label' => '11月の分'],
        ], $this->pending('2026-10-15'));
    }

    public function test_次の平日にずれて今月に動く先月分は_先月の印で今月の分として出る(): void
    {
        // 月末・次の平日：10月31日（土）→ 11月2日（月）、11月30日（月）
        $this->variable(['name' => '家賃', 'day' => 31, 'start_month' => '2026-10-01']);

        // 10 月のうちは、10 月分は来月（11月2日）に動くので出ない
        $this->assertSame([], $this->pending('2026-10-31'));

        $this->assertSame([
            ['name' => '家賃', 'month' => '2026-10-01', 'date' => '2026-11-02', 'estimate' => 9200, 'label' => '10月の分'],
            ['name' => '家賃', 'month' => '2026-11-01', 'date' => '2026-11-30', 'estimate' => 9200, 'label' => null],
        ], $this->pending('2026-11-10'));
    }

    public function test_お金が動く日が来月以降のものは出ない(): void
    {
        $this->variable(['start_month' => '2026-11-01']);

        $this->assertSame([], $this->pending('2026-10-15'));
        $this->assertSame([], $this->pending('2026-10-31'));
        $this->assertCount(1, $this->pending('2026-11-01'));
    }

    public function test_年がちがう月の分には年をつける(): void
    {
        // 10日・次の平日：2026年12月10日（木）、2027年1月10日（日）→ 1月11日（月・成人の日）→ 1月12日（火）
        $this->variable(['name' => '保険', 'day' => 10, 'start_month' => '2026-12-01']);

        $this->assertSame([
            ['name' => '保険', 'month' => '2026-12-01', 'date' => '2026-12-10', 'estimate' => 9200, 'label' => '2026年12月の分'],
            ['name' => '保険', 'month' => '2027-01-01', 'date' => '2027-01-12', 'estimate' => 9200, 'label' => null],
        ], $this->pending('2027-01-15'));
    }

    public function test_年末年始でずれて年をまたいで今月に動く先月分にも年をつける(): void
    {
        // 月末・次の平日：2026年12月31日（年末年始の休み）→ 2027年1月4日（月）
        $this->variable(['name' => '家賃', 'day' => 31, 'start_month' => '2026-12-01', 'end_month' => '2026-12-01']);

        $this->assertSame([], $this->pending('2026-12-31'));
        $this->assertSame([
            ['name' => '家賃', 'month' => '2026-12-01', 'date' => '2027-01-04', 'estimate' => 9200, 'label' => '2026年12月の分'],
        ], $this->pending('2027-01-10'));
    }

    public function test_概算は前回の記録の金額(): void
    {
        $electricity = $this->variable(['start_month' => '2026-09-01']);
        $this->recordedBefore($electricity, '2026-09-01', '2026-09-28', 9700);

        $this->assertSame([['2026-10-01', 9700]], array_map(fn ($i) => [$i['month'], $i['estimate']], $this->pending('2026-10-15')));
    }

    public function test_金額を入れた月_記録済みの月_今回はなしの月は出ない(): void
    {
        $electricity = $this->variable(['start_month' => '2026-07-01']);
        $this->recordedBefore($electricity, '2026-07-01', '2026-07-27', 9000);
        $this->enterAmount($electricity, '2026-08-01', 9100);
        $this->skipMonth($electricity, '2026-09-01');

        $this->assertSame(['2026-10-01'], array_column($this->pending('2026-10-15'), 'month'));
    }

    public function test_決まった金額の予定と使わないの予定は出ない(): void
    {
        $this->fixed();
        $this->variable(['is_active' => false]);

        $this->assertSame([], $this->pending('2026-10-15'));
    }

    public function test_最初の月より前_最後の月より後_選んだ月でない月は出ない(): void
    {
        $this->variable(['name' => '9月から9月まで', 'start_month' => '2026-09-01', 'end_month' => '2026-09-01']);
        $this->variable(['name' => '賞与', 'months' => [8, 12], 'start_month' => '2026-06-01']);

        $this->assertSame(
            [['賞与', '2026-08-01'], ['9月から9月まで', '2026-09-01']],
            array_map(fn ($i) => [$i['name'], $i['month']], $this->pending('2026-10-15')),
        );
    }

    // ---- 金額を入れる（saveAmount） ----

    public function test_先の日付の金額は保存だけして_記録しない_入れ直すと書き換わる(): void
    {
        $electricity = $this->variable();
        $today = self::day('2026-10-15');

        $this->assertFalse(RecurringMonthly::saveAmount($electricity, self::day('2026-10-01'), 9300, $today));
        $this->assertSame(9300, $this->amountRow($electricity, '2026-10-01'));
        $this->assertSame([], $this->recorded($electricity));

        RecurringMonthly::saveAmount($electricity, self::day('2026-10-01'), 9400, $today);
        $this->assertSame(9400, $this->amountRow($electricity, '2026-10-01'));
        $this->assertSame(1, RecurringAmount::query()->where('recurring_id', $electricity->id)->count());

        $this->assertSame(9400, $this->rowOf($electricity, '2026-10-01')['amount']);
        $this->assertNotContains('2026-10-01', array_column($this->pending('2026-10-15'), 'month'));
    }

    public function test_空にして保存すると金額がまだに戻る(): void
    {
        $electricity = $this->variable(['start_month' => '2026-10-01']);
        $today = self::day('2026-10-15');
        RecurringMonthly::saveAmount($electricity, self::day('2026-10-01'), 9300, $today);

        $this->assertFalse(RecurringMonthly::saveAmount($electricity, self::day('2026-10-01'), null, $today));

        $this->assertNull($this->amountRow($electricity, '2026-10-01'));
        $this->assertNull($this->rowOf($electricity, '2026-10-01')['amount']);
        $this->assertSame(['2026-10-01'], array_column($this->pending('2026-10-15'), 'month'));
        $this->assertSame([], $this->recorded($electricity));
    }

    public function test_日付が今日なら_その場で今日の日付で記録する(): void
    {
        $electricity = $this->variable(['start_month' => '2026-10-01']);

        $this->assertTrue(RecurringMonthly::saveAmount($electricity, self::day('2026-10-01'), 9300, self::day('2026-10-26')));

        $this->assertSame(['2026-10' => ['2026-10-26', 9300]], $this->recorded($electricity));
        $this->assertSame('recorded', $this->rowOf($electricity, '2026-10-01')['status']);
        $this->assertSame([], $this->pending('2026-10-26'));
    }

    public function test_過ぎた月の金額を入れると_予定の日付で記録する(): void
    {
        $electricity = $this->variable(['start_month' => '2026-09-01']);

        // 9月26日（土）→ 9月28日（月）の分を、10月15日に入れた
        $this->assertTrue(RecurringMonthly::saveAmount($electricity, self::day('2026-09-01'), 9100, self::day('2026-10-15')));

        $this->assertSame(['2026-09' => ['2026-09-28', 9100]], $this->recorded($electricity));
        $record = Record::query()->where('recurring_id', $electricity->id)->firstOrFail();
        $this->assertSame(RecordType::Expense, $record->type);
        $this->assertSame($this->bank->id, $record->account_id);
        $this->assertSame(['2026-10-01'], array_column($this->pending('2026-10-15'), 'month'));
    }

    public function test_ずれて先に動く来月分は_動く日が過ぎていればその日で記録する(): void
    {
        // 11 月分は 11月1日（日）→ 10月30日（金）に動く
        $loan = $this->variable(['name' => 'ローン', 'day' => 1, 'shift' => RecurringShift::Previous, 'start_month' => '2026-11-01']);

        $this->assertTrue(RecurringMonthly::saveAmount($loan, self::day('2026-11-01'), 30000, self::day('2026-10-30')));

        $this->assertSame(['2026-11' => ['2026-10-30', 30000]], $this->recorded($loan));
    }

    public function test_決まった金額の予定には_金額を入れられない(): void
    {
        $phone = $this->fixed();

        $this->assertFalse(RecurringMonthly::saveAmount($phone, self::day('2026-10-01'), 6000, self::day('2026-10-25')));

        $this->assertNull($this->amountRow($phone, '2026-10-01'));
        $this->assertSame([], $this->recorded($phone));
    }

    public function test_記録済みの月の金額は_ここでは直せない(): void
    {
        $electricity = $this->variable();
        $this->recordedBefore($electricity, '2026-09-01', '2026-09-28', 9100);

        $this->assertFalse(RecurringMonthly::saveAmount($electricity, self::day('2026-09-01'), 9999, self::day('2026-10-15')));

        $this->assertNull($this->amountRow($electricity, '2026-09-01'));
        $this->assertSame(['2026-09' => ['2026-09-28', 9100]], $this->recorded($electricity));
        $this->assertSame(1, Record::query()->where('recurring_id', $electricity->id)->count());
    }

    // ---- 今回はなし（skip） ----

    public function test_今回はなしにすると_その月は記録されず_お知らせからも消える(): void
    {
        $electricity = $this->variable(['start_month' => '2026-09-01']);

        RecurringMonthly::skip($electricity, self::day('2026-09-01'));

        $this->assertTrue($this->isSkipped($electricity, '2026-09-01'));
        $this->assertSame('skipped', $this->rowOf($electricity, '2026-09-01')['status']);
        $this->assertSame(['2026-10-01'], array_column($this->pending('2026-10-15'), 'month'));
        $this->assertSame([], $this->recorded($electricity));
    }

    public function test_決まった金額のものも今回はなしにできる(): void
    {
        $phone = $this->fixed();

        RecurringMonthly::skip($phone, self::day('2026-10-01'));

        $this->assertTrue($this->isSkipped($phone, '2026-10-01'));
        $this->assertSame('skipped', $this->rowOf($phone, '2026-10-01')['status']);
    }

    public function test_今回はなしにしても_入れてあった金額は残る(): void
    {
        $electricity = $this->variable();
        $this->enterAmount($electricity, '2026-11-01', 9300);

        RecurringMonthly::skip($electricity, self::day('2026-11-01'));

        $this->assertSame(9300, $this->amountRow($electricity, '2026-11-01'));
    }

    public function test_記録済みの月は今回はなしにできない(): void
    {
        $phone = $this->fixed();
        $this->recordedBefore($phone, '2026-09-01', '2026-09-24', 5800);

        RecurringMonthly::skip($phone, self::day('2026-09-01'));

        $this->assertFalse($this->isSkipped($phone, '2026-09-01'));
        $this->assertSame('recorded', $this->rowOf($phone, '2026-09-01')['status']);
    }

    // ---- もどす（restore） ----

    public function test_先の日付の月をもどすと_元の行に戻るだけで記録しない(): void
    {
        $phone = $this->fixed();
        $this->skipMonth($phone, '2026-11-01');

        $this->assertFalse(RecurringMonthly::restore($phone, self::day('2026-11-01'), self::day('2026-10-15')));

        $this->assertFalse($this->isSkipped($phone, '2026-11-01'));
        $this->assertSame('fixed', $this->rowOf($phone, '2026-11-01')['status']);
        $this->assertSame([], $this->recorded($phone));
    }

    public function test_日付が過ぎた決まった金額の月をもどすと_予定の日付で決まった金額を記録する(): void
    {
        // 9月20日（日）→ 9月21日（敬老の日）・22日（国民の休日）・23日（秋分の日）をこえて 9月24日（木）
        $phone = $this->fixed();
        $this->skipMonth($phone, '2026-09-01');

        $this->assertTrue(RecurringMonthly::restore($phone, self::day('2026-09-01'), self::day('2026-10-15')));

        $this->assertFalse($this->isSkipped($phone, '2026-09-01'));
        $this->assertSame(['2026-09' => ['2026-09-24', 5800]], $this->recorded($phone));
    }

    public function test_日付が今日の月をもどしても_その場で記録する(): void
    {
        $phone = $this->fixed();
        $this->skipMonth($phone, '2026-10-01');

        $this->assertTrue(RecurringMonthly::restore($phone, self::day('2026-10-01'), self::day('2026-10-20')));

        $this->assertSame(['2026-10' => ['2026-10-20', 5800]], $this->recorded($phone));
    }

    public function test_金額が変わる予定は_入れてあった金額で記録し直す(): void
    {
        $electricity = $this->variable();
        $this->enterAmount($electricity, '2026-09-01', 9500);
        $this->skipMonth($electricity, '2026-09-01');

        $this->assertTrue(RecurringMonthly::restore($electricity, self::day('2026-09-01'), self::day('2026-10-15')));

        $this->assertSame(['2026-09' => ['2026-09-28', 9500]], $this->recorded($electricity));
    }

    public function test_金額がまだの予定をもどすと_記録せず_金額がまだのお知らせに戻る(): void
    {
        $electricity = $this->variable(['start_month' => '2026-09-01']);
        $this->skipMonth($electricity, '2026-09-01');

        $this->assertFalse(RecurringMonthly::restore($electricity, self::day('2026-09-01'), self::day('2026-10-15')));

        $this->assertFalse($this->isSkipped($electricity, '2026-09-01'));
        $this->assertSame([], $this->recorded($electricity));
        $this->assertSame(['2026-09-01', '2026-10-01'], array_column($this->pending('2026-10-15'), 'month'));
    }

    public function test_使わないの予定でも_過ぎた月をもどすと記録する(): void
    {
        $phone = $this->fixed(['is_active' => false]);
        $this->skipMonth($phone, '2026-09-01');

        $this->assertTrue(RecurringMonthly::restore($phone, self::day('2026-09-01'), self::day('2026-10-15')));

        $this->assertSame(['2026-09' => ['2026-09-24', 5800]], $this->recorded($phone));
        $this->assertSame('recorded', $this->rowOf($phone, '2026-09-01')['status']);
    }

    public function test_使わないの金額が変わる予定でも_金額があれば記録する(): void
    {
        $electricity = $this->variable(['is_active' => false]);
        $this->enterAmount($electricity, '2026-08-01', 8800);
        $this->skipMonth($electricity, '2026-08-01');

        $this->assertTrue(RecurringMonthly::restore($electricity, self::day('2026-08-01'), self::day('2026-10-15')));

        $this->assertSame(['2026-08' => ['2026-08-26', 8800]], $this->recorded($electricity));
    }
}
