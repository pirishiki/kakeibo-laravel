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
use App\Support\RecurringRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 自動で記録する仕組みを、設計書だけから確かめる（データベース設計書 3.5〜3.8・4 章、要件定義書 10.2「日付の計算」「自動で記録する」、全体構成設計書 5 章）
 * 今日の日付は、テストごとに決めて渡す（本物の時計にたよらない）
 *
 * 2026 年のカレンダー（このテストで使う日）
 * - 7月15日（水）、8月15日（土）→ 8月17日（月）、9月15日（火）、10月15日（木）、11月15日（日）→ 11月16日（月）
 * - 10月30日（金）、10月31日（土）、11月1日（日）、11月2日（月）
 * - 12月30日（水）、12月31日（木）、2027年1月1日（金・元日）〜1月3日（日）、1月4日（月）
 */
class RecurringRecorderSpecTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $card;

    private Category $food;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        // 本物の時計を使っていないことがわかるように、今日はずっと先の日にしておく
        $this->travelTo(now()->setDate(2030, 1, 15)->setTime(12, 0));
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行']);
        $this->card = Account::factory()->create(['name' => '楽天カード']);
        $this->food = Category::factory()->create(['name' => '食費のテスト', 'kind' => CategoryKind::Expense]);
        $this->shop = Partner::factory()->create(['name' => '生協']);
    }

    private static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfDay();
    }

    /** 毎月 15 日・次の平日・決まった金額 3,000 円の支出。7 月から */
    private function delivery(array $attributes = []): Recurring
    {
        return Recurring::factory()->create([
            'name' => '生協の宅配', 'type' => RecordType::Expense, 'day' => 15, 'shift' => RecurringShift::Next,
            'is_fixed' => true, 'amount' => 3000,
            'account_id' => $this->bank->id, 'to_account_id' => null,
            'category_id' => $this->food->id, 'partner_id' => $this->shop->id,
            'start_month' => '2026-07-01', 'end_month' => null, 'is_active' => true, ...$attributes,
        ]);
    }

    /** 金額が変わる予定（概算 9,000 円） */
    private function electricity(array $attributes = []): Recurring
    {
        return $this->delivery(['name' => '電気代', 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 9000, ...$attributes]);
    }

    /**
     * その予定の記録を、何月の分の古い順に「何月の分 => 日付」で出す
     *
     * @return array<string, string>
     */
    private function recorded(Recurring $recurring): array
    {
        return Record::query()->where('recurring_id', $recurring->id)->orderBy('recurring_month')->get()
            ->mapWithKeys(fn (Record $r) => [CarbonImmutable::parse($r->getAttribute('recurring_month'))->format('Y-m') => $r->date->toDateString()])
            ->all();
    }

    /**
     * @param  list<CarbonImmutable>  $months
     * @return list<string>
     */
    private static function ym(array $months): array
    {
        return array_map(fn (CarbonImmutable $m) => $m->format('Y-m-d'), $months);
    }

    /** 自動で記録されたことにする */
    private function recordedBefore(Recurring $recurring, string $month, string $date): Record
    {
        $record = Record::factory()->create([
            'type' => $recurring->type, 'amount' => 3000, 'date' => $date,
            'account_id' => $recurring->account_id, 'category_id' => $recurring->category_id, 'partner_id' => $recurring->partner_id,
        ]);
        $record->forceFill(['recurring_id' => $recurring->id, 'recurring_month' => $month])->save();

        return $record;
    }

    // ---- まとめて記録する・何度動いても 1 回 ----

    public function test_動かなかった日の分も_今日までの分がまとめて記録される(): void
    {
        $recurring = $this->delivery();

        $count = RecurringRecorder::recordAllDue(self::day('2026-10-15'));

        $this->assertSame(4, $count);
        $this->assertSame([
            '2026-07' => '2026-07-15',
            '2026-08' => '2026-08-17',
            '2026-09' => '2026-09-15',
            '2026-10' => '2026-10-15',
        ], $this->recorded($recurring));
    }

    public function test_今日が記録する日なら今日の分も記録され_明日なら記録されない(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-10-01']);

        $this->assertSame([], RecurringRecorder::dueMonths($recurring, self::day('2026-10-14')));
        $this->assertSame(0, RecurringRecorder::recordDue($recurring, self::day('2026-10-14')));

        $this->assertSame(['2026-10-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-10-15'))));
        $this->assertSame(1, RecurringRecorder::recordDue($recurring, self::day('2026-10-15')));
        $this->assertSame(['2026-10' => '2026-10-15'], $this->recorded($recurring));
    }

    public function test_日付が来る月は古い順に出る(): void
    {
        $recurring = $this->delivery();

        $this->assertSame(
            ['2026-07-01', '2026-08-01', '2026-09-01'],
            self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-10-14'))),
        );
    }

    public function test_何度動いても同じ月の分は1回しか記録されない(): void
    {
        $recurring = $this->delivery();

        $this->assertSame(4, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(0, RecurringRecorder::recordDue($recurring, self::day('2026-10-15')));
        $this->assertSame(4, Record::query()->where('recurring_id', $recurring->id)->count());
        $this->assertSame([], RecurringRecorder::dueMonths($recurring, self::day('2026-10-15')));
    }

    public function test_次の日に動くと_新しく来た分だけ記録される(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-09-01']);

        $this->assertSame(2, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-11-15')));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-11-16')));
        $this->assertSame(['2026-09', '2026-10', '2026-11'], array_keys($this->recorded($recurring)));
    }

    public function test_もう記録された月は_記録し直さずエラーにもならない(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-09-01']);
        // 9 月分は、日付を直してある（日付ではなく何月の分かで見る）
        $this->recordedBefore($recurring, '2026-09-01', '2026-09-20');

        $this->assertSame(['2026-10-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-10-15'))));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(['2026-09' => '2026-09-20', '2026-10' => '2026-10-15'], $this->recorded($recurring));

        // その場で記録しようとしても、記録済みなら false（例外にしない。全体構成設計書 5 章）
        $this->assertFalse(RecurringRecorder::record($recurring, self::day('2026-09-01')));
        $this->assertFalse(RecurringRecorder::record($recurring, self::day('2026-10-01')));
        $this->assertSame(2, Record::query()->where('recurring_id', $recurring->id)->count());
    }

    public function test_同じ予定の同じ月の記録はデータベースでも止まる(): void
    {
        $recurring = $this->delivery();
        $this->recordedBefore($recurring, '2026-09-01', '2026-09-15');

        $this->expectException(QueryException::class);
        $this->recordedBefore($recurring, '2026-09-01', '2026-09-16');
    }

    public function test_手で入れた記録は_同じ日でも予定の記録とみなさない(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-10-01']);
        Record::factory()->create([
            'type' => RecordType::Expense, 'amount' => 3000, 'date' => '2026-10-15',
            'account_id' => $this->bank->id, 'category_id' => $this->food->id, 'partner_id' => $this->shop->id,
        ]);

        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(2, Record::query()->count());
    }

    // ---- 記録しない月 ----

    public function test_最初の月より前と最後の月より後は記録されない(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-08-01', 'end_month' => '2026-09-01']);

        $this->assertSame(['2026-08-01', '2026-09-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-12-31'))));
        $this->assertSame(2, RecurringRecorder::recordAllDue(self::day('2026-12-31')));
        $this->assertSame(['2026-08' => '2026-08-17', '2026-09' => '2026-09-15'], $this->recorded($recurring));
    }

    public function test_最初の月が先の予定は_その月が来るまで記録されない(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-12-01']);

        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-11-30')));
        $this->assertSame([], $this->recorded($recurring));
    }

    public function test_使わないの予定は自動では記録されない(): void
    {
        $recurring = $this->delivery(['is_active' => false]);

        $this->assertSame([], RecurringRecorder::dueMonths($recurring, self::day('2026-10-15')));
        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(0, RecurringRecorder::recordDue($recurring, self::day('2026-10-15')));
        $this->assertSame([], $this->recorded($recurring));
    }

    public function test_選んだ月だけの予定は_選んだ月だけ記録される(): void
    {
        $recurring = $this->delivery(['months' => [8, 10]]);

        $this->assertSame(['2026-08-01', '2026-10-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-10-15'))));
        $this->assertSame(2, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(['2026-08' => '2026-08-17', '2026-10' => '2026-10-15'], $this->recorded($recurring));
    }

    public function test_今回はなしの月は記録されない(): void
    {
        $recurring = $this->delivery();
        RecurringSkip::query()->create(['recurring_id' => $recurring->id, 'month' => '2026-09-01']);

        $this->assertSame(['2026-07-01', '2026-08-01', '2026-10-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-10-15'))));
        $this->assertSame(3, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertArrayNotHasKey('2026-09', $this->recorded($recurring));
    }

    public function test_ほかの予定の今回はなしや記録には左右されない(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-10-01']);
        $other = $this->delivery(['name' => 'ほかの予定', 'start_month' => '2026-10-01']);
        RecurringSkip::query()->create(['recurring_id' => $other->id, 'month' => '2026-10-01']);

        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(['2026-10' => '2026-10-15'], $this->recorded($recurring));
        $this->assertSame([], $this->recorded($other));
    }

    public function test_record_dueは_その予定だけを記録する(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-10-01']);
        $other = $this->delivery(['name' => 'ほかの予定', 'start_month' => '2026-10-01']);

        $this->assertSame(1, RecurringRecorder::recordDue($recurring, self::day('2026-10-15')));
        $this->assertSame([], $this->recorded($other));
    }

    public function test_いくつもの予定を_まとめて記録して件数を返す(): void
    {
        $this->delivery(['start_month' => '2026-09-01']);
        $this->delivery(['name' => '別の宅配', 'start_month' => '2026-10-01']);
        $this->delivery(['name' => '使わない', 'is_active' => false]);

        $this->assertSame(3, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
    }

    // ---- 金額が変わる予定 ----

    public function test_金額の決まった予定は_決まった金額で記録される(): void
    {
        $recurring = $this->delivery();

        $this->assertSame(3000, RecurringRecorder::amountFor($recurring, self::day('2026-10-01')));
    }

    public function test_金額が変わる予定は_その月の金額を使い_まだなら空(): void
    {
        $recurring = $this->electricity();
        RecurringAmount::query()->create(['recurring_id' => $recurring->id, 'month' => '2026-08-01', 'amount' => 9200]);

        $this->assertSame(9200, RecurringRecorder::amountFor($recurring, self::day('2026-08-01')));
        // 概算（estimated_amount）は記録の金額に使わない
        $this->assertNull(RecurringRecorder::amountFor($recurring, self::day('2026-09-01')));
    }

    public function test_金額が変わる予定は_金額を入れるまで記録されない(): void
    {
        $recurring = $this->electricity();
        RecurringAmount::query()->create(['recurring_id' => $recurring->id, 'month' => '2026-08-01', 'amount' => 9200]);

        $this->assertSame(['2026-08-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-10-15'))));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(['2026-08' => '2026-08-17'], $this->recorded($recurring));
        $this->assertSame(9200, Record::query()->where('recurring_id', $recurring->id)->value('amount'));
    }

    public function test_金額がまだの月は_その場で記録しようとしてもfalse(): void
    {
        $recurring = $this->electricity();

        $this->assertFalse(RecurringRecorder::record($recurring, self::day('2026-09-01')));
        $this->assertSame([], $this->recorded($recurring));
    }

    public function test_日付が過ぎてから金額を入れると_予定の日付で記録される(): void
    {
        $recurring = $this->electricity();
        RecurringRecorder::recordAllDue(self::day('2026-10-15'));
        $this->assertSame([], $this->recorded($recurring));

        // 10 月 20 日に、9 月分の金額を入れて、その場で記録する
        RecurringAmount::query()->create(['recurring_id' => $recurring->id, 'month' => '2026-09-01', 'amount' => 8800]);
        $this->assertTrue(RecurringRecorder::record($recurring, self::day('2026-09-01')));

        $record = Record::query()->where('recurring_id', $recurring->id)->sole();
        $this->assertSame('2026-09-15', $record->date->toDateString());
        $this->assertSame(8800, $record->amount);
    }

    public function test_金額を入れた分は_あとで自動の仕組みが動いても二重にならない(): void
    {
        $recurring = $this->electricity(['start_month' => '2026-10-01']);
        RecurringAmount::query()->create(['recurring_id' => $recurring->id, 'month' => '2026-10-01', 'amount' => 8800]);
        $this->assertTrue(RecurringRecorder::record($recurring, self::day('2026-10-01')));

        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-10-16')));
        $this->assertSame(1, Record::query()->where('recurring_id', $recurring->id)->count());
    }

    public function test_来月の分の金額を先に入れておくと_その日が来たときに記録される(): void
    {
        $recurring = $this->electricity(['start_month' => '2026-11-01']);
        RecurringAmount::query()->create(['recurring_id' => $recurring->id, 'month' => '2026-11-01', 'amount' => 9100]);

        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-11-15')));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-11-16')));
        $this->assertSame(['2026-11' => '2026-11-16'], $this->recorded($recurring));
    }

    // ---- その場で記録する（record） ----

    public function test_その場で記録すると_使わないの予定でも記録される(): void
    {
        // 「もどす」は、使わないの予定でも記録する（データベース設計書 3.6）
        $recurring = $this->delivery(['is_active' => false]);

        $this->assertTrue(RecurringRecorder::record($recurring, self::day('2026-09-01')));
        $this->assertSame(['2026-09' => '2026-09-15'], $this->recorded($recurring));
    }

    public function test_その場で記録すると_日付がまだ来ていない月でも予定の日付で記録される(): void
    {
        $recurring = $this->delivery();

        $this->assertTrue(RecurringRecorder::record($recurring, self::day('2026-11-01')));
        $this->assertSame(['2026-11' => '2026-11-16'], $this->recorded($recurring));
    }

    public function test_同じ月をその場で2回記録しても1回だけで_何月の分は1日として持つ(): void
    {
        $recurring = $this->delivery();

        $this->assertTrue(RecurringRecorder::record($recurring, self::day('2026-09-01')));
        $this->assertFalse(RecurringRecorder::record($recurring, self::day('2026-09-01')));
        $record = Record::query()->where('recurring_id', $recurring->id)->sole();
        $this->assertSame('2026-09-01', CarbonImmutable::parse($record->getAttribute('recurring_month'))->toDateString());
    }

    // ---- 記録の中身 ----

    public function test_支出の記録は_予定の種類_金額_口座_分類_相手を写す(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-10-01']);

        RecurringRecorder::recordAllDue(self::day('2026-10-15'));

        $record = Record::query()->where('recurring_id', $recurring->id)->sole();
        $this->assertSame(RecordType::Expense, $record->type);
        $this->assertSame(3000, $record->amount);
        $this->assertSame($this->bank->id, $record->account_id);
        $this->assertNull($record->to_account_id);
        $this->assertSame($this->food->id, $record->category_id);
        $this->assertSame($this->shop->id, $record->partner_id);
        $this->assertSame('2026-10-15', $record->date->toDateString());
        $this->assertSame($recurring->id, (int) $record->getAttribute('recurring_id'));
        $this->assertSame('2026-10-01', CarbonImmutable::parse($record->getAttribute('recurring_month'))->toDateString());
    }

    public function test_収入の記録も写す(): void
    {
        $salary = Category::factory()->create(['name' => '給料のテスト', 'kind' => CategoryKind::Income]);
        $company = Partner::factory()->create(['name' => '会社']);
        $recurring = $this->delivery([
            'name' => '給料', 'type' => RecordType::Income, 'day' => 25, 'shift' => RecurringShift::Previous, 'amount' => 250000,
            'category_id' => $salary->id, 'partner_id' => $company->id, 'start_month' => '2026-10-01',
        ]);

        // 2026年10月25日（日）の給料 → 10月23日（金）（要件定義書 10.2 の例）
        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-10-22')));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-10-23')));

        $record = Record::query()->where('recurring_id', $recurring->id)->sole();
        $this->assertSame(RecordType::Income, $record->type);
        $this->assertSame(250000, $record->amount);
        $this->assertSame($salary->id, $record->category_id);
        $this->assertSame($company->id, $record->partner_id);
        $this->assertSame('2026-10-23', $record->date->toDateString());
    }

    public function test_移動の記録は_どこへの口座も写す(): void
    {
        $recurring = $this->delivery([
            'name' => 'カードの引き落とし', 'type' => RecordType::Transfer, 'amount' => 45000,
            'account_id' => $this->bank->id, 'to_account_id' => $this->card->id, 'category_id' => null, 'partner_id' => null,
            'start_month' => '2026-10-01',
        ]);

        RecurringRecorder::recordAllDue(self::day('2026-10-15'));

        $record = Record::query()->where('recurring_id', $recurring->id)->sole();
        $this->assertSame(RecordType::Transfer, $record->type);
        $this->assertSame(45000, $record->amount);
        $this->assertSame($this->bank->id, $record->account_id);
        $this->assertSame($this->card->id, $record->to_account_id);
        $this->assertNull($record->category_id);
        $this->assertNull($record->partner_id);
    }

    // ---- 月をまたぐずらし・年末年始 ----

    public function test_次の平日にずれて翌月になっても_もとの月の分として翌月の日に記録される(): void
    {
        // 月末（10月31日（土））・次の平日 → 11月2日（月）
        $recurring = $this->delivery(['day' => 31, 'shift' => RecurringShift::Next, 'start_month' => '2026-10-01']);

        $this->assertSame([], RecurringRecorder::dueMonths($recurring, self::day('2026-10-31')));
        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-11-01')));
        $this->assertSame(['2026-10-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-11-02'))));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-11-02')));
        $this->assertSame(['2026-10' => '2026-11-02'], $this->recorded($recurring));
    }

    public function test_前の平日にずれて前の月になると_来月の分が今月の日に記録される(): void
    {
        // 毎月 1 日・前の平日。11月1日（日）→ 10月30日（金）。最初の月は 11 月
        $recurring = $this->delivery(['day' => 1, 'shift' => RecurringShift::Previous, 'start_month' => '2026-11-01']);

        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-10-29')));
        $this->assertSame(['2026-11-01'], self::ym(RecurringRecorder::dueMonths($recurring, self::day('2026-10-30'))));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-10-30')));
        $this->assertSame(['2026-11' => '2026-10-30'], $this->recorded($recurring));

        // 11 月になっても、11 月分をもう一度記録しない
        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-11-02')));
    }

    public function test_前の平日にずれる月と_ずれない月が同じ日に来ると両方記録される(): void
    {
        // 10月1日（木）は 10 月分、10月30日（金）は 11 月分
        $recurring = $this->delivery(['day' => 1, 'shift' => RecurringShift::Previous, 'start_month' => '2026-10-01']);

        $this->assertSame(2, RecurringRecorder::recordAllDue(self::day('2026-10-30')));
        $this->assertSame(['2026-10' => '2026-10-01', '2026-11' => '2026-10-30'], $this->recorded($recurring));
    }

    public function test_最後の月の分が翌月にずれても記録される(): void
    {
        $recurring = $this->delivery(['day' => 31, 'shift' => RecurringShift::Next, 'start_month' => '2026-10-01', 'end_month' => '2026-10-01']);

        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-12-31')));
        $this->assertSame(['2026-10' => '2026-11-02'], $this->recorded($recurring));
    }

    public function test_年末年始は休みとして数え_1月4日に12月分が記録される(): void
    {
        // 12月31日（木）は祝日ではないが休み。1月1日〜3日も休み → 2027年1月4日（月）
        $recurring = $this->delivery(['day' => 31, 'shift' => RecurringShift::Next, 'start_month' => '2026-12-01']);

        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-12-31')));
        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2027-01-03')));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2027-01-04')));
        $this->assertSame(['2026-12' => '2027-01-04'], $this->recorded($recurring));
    }

    public function test_年末年始を前の平日にずらすと_1月分が12月30日に記録される(): void
    {
        // 1月1日 → 12月31日も休み → 2026年12月30日（水）
        $recurring = $this->delivery(['day' => 1, 'shift' => RecurringShift::Previous, 'start_month' => '2027-01-01']);

        $this->assertSame(0, RecurringRecorder::recordAllDue(self::day('2026-12-29')));
        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-12-30')));
        $this->assertSame(['2027-01' => '2026-12-30'], $this->recorded($recurring));
    }

    // ---- コマンドと予定表 ----

    public function test_コマンドを動かすと今日までの分が記録され_2回動かしても1回だけ(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-09-01']);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 00:05:00', 'Asia/Tokyo'));

        $this->artisan('recurrings:record')->assertSuccessful();
        $this->artisan('recurrings:record')->assertSuccessful();

        $this->assertSame(['2026-09' => '2026-09-15', '2026-10' => '2026-10-15'], $this->recorded($recurring));
    }

    public function test_引数なしなら_日本時間の今日で数える(): void
    {
        $recurring = $this->delivery(['start_month' => '2026-10-01']);
        // UTC では 10月14日 15:05 だが、日本時間では 10月15日 0:05
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:05:00', 'UTC'));

        $this->assertSame(1, RecurringRecorder::recordAllDue());
        $this->assertSame(['2026-10' => '2026-10-15'], $this->recorded($recurring));
    }

    public function test_予定表で毎日0時5分_日本時間に_重ねずに動く(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => str_contains((string) $e->command, 'recurrings:record'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('5 0 * * *', $event->expression);
        // 予定表に時間帯を書かないときは、Laravel の時間帯（config/app.php の timezone）で動く
        $timezone = $event->timezone ?? config('app.timezone');
        $this->assertSame('Asia/Tokyo', $timezone instanceof \DateTimeZone ? $timezone->getName() : $timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_片方の予定がもう記録済みでも_もう片方は記録される(): void
    {
        // 片方の予定の今月の分が、すでに入っている（画面で同時に記録したなど）
        $a = $this->delivery(['start_month' => '2026-10-01']);
        $b = $this->delivery(['name' => '別の宅配', 'start_month' => '2026-10-01']);
        $this->recordedBefore($a, '2026-10-01', '2026-10-15');

        $this->assertSame(1, RecurringRecorder::recordAllDue(self::day('2026-10-15')));
        $this->assertSame(['2026-10' => '2026-10-15'], $this->recorded($b));
        $this->assertSame(2, DB::table('records')->count());
    }
}
