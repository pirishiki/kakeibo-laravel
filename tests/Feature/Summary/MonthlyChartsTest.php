<?php

namespace Tests\Feature\Summary;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 要件定義書 10.3 の「グラフ」（No.20、画面設計書 5.5）を確かめる。今日は 2026年10月24日（土）。
 * グラフの数字は、canvas の data-chart-data に入っている（app/Support/MonthlyCharts.php が計算する）
 */
class MonthlyChartsTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        // 口座は 2026年6月に作った（使い始めた月）
        $this->travelTo(now()->setDate(2026, 6, 3));
        $this->bank = Account::factory()->create(['initial_balance' => 100000]);
        $this->travelTo(now()->setDate(2026, 10, 24));
    }

    private function record(RecordType $type, string $date, int $amount, ?Category $category = null, array $extra = []): Record
    {
        return Record::factory()->create([
            'date' => $date, 'type' => $type, 'amount' => $amount, 'account_id' => $this->bank->id,
            'category_id' => $category?->id, 'partner_id' => $category === null ? null : Partner::factory()->create()->id,
            ...$extra,
        ]);
    }

    private function parent(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    /**
     * 月のまとめを開いて、そのグラフの数字を読む
     *
     * @return array<string, mixed>|null
     */
    private function chart(string $month, string $type): ?array
    {
        $html = $this->get('/summary?month='.$month)->assertOk()->getContent();
        if (preg_match('/data-chart="'.$type.'" data-chart-data="([^"]*)"/', (string) $html, $m) !== 1) {
            return null;
        }

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }

    public function test_円グラフは多い順に5つと残りのほかで_合計が支出の合計と同じ(): void
    {
        $amounts = ['住まい' => 70000, '食費' => 42000, '自動車' => 30000, '日用品' => 8000, '通信費' => 6000, '趣味・娯楽' => 5000, '交際費' => 4000];
        foreach ($amounts as $name => $amount) {
            $this->record(RecordType::Expense, '2026-10-05', $amount, $this->parent($name));
        }

        $pie = $this->chart('2026-10', 'pie');

        $this->assertSame(['住まい', '食費', '自動車', '日用品', '通信費', 'ほか'], $pie['labels']);
        $this->assertSame([70000, 42000, 30000, 8000, 6000, 9000], $pie['values']);
        $this->assertSame(array_sum($amounts), array_sum($pie['values']));
        $this->assertCount(6, array_unique($pie['colors']));
    }

    public function test_棒グラフは選んだ月までの6か月で_使い始めた月より前は出ない(): void
    {
        $salary = $this->parent('給与', CategoryKind::Income);
        $this->record(RecordType::Income, '2026-07-25', 250000, $salary);
        $this->record(RecordType::Expense, '2026-07-10', 40000, $this->parent('食費'));
        // 移動と調整は入らない
        $this->record(RecordType::Transfer, '2026-07-12', 5000, null, ['to_account_id' => Account::factory()->create()->id]);
        $this->record(RecordType::Adjustment, '2026-07-13', -300);

        $bar = $this->chart('2026-10', 'bar');

        // 6 か月なら 5 月〜10 月だが、口座を作った 6 月より前は出さない
        $this->assertSame(['6月', '7月', '8月', '9月', '10月'], $bar['labels']);
        $this->assertSame([0, 250000, 0, 0, 0], $bar['income']);
        $this->assertSame([0, 40000, 0, 0, 0], $bar['expense']);
    }

    public function test_前の月の記録があれば_使い始めた月はその月になる(): void
    {
        $this->record(RecordType::Expense, '2026-03-10', 1000, $this->parent('食費'));

        $this->assertSame(['5月', '6月', '7月', '8月', '9月', '10月'], $this->chart('2026-10', 'bar')['labels']);
        $this->assertSame(['3月', '4月'], $this->chart('2026-04', 'line')['labels']);
        // 使い始めた月より前の月は、グラフを出さない
        $this->assertNull($this->chart('2026-02', 'bar'));
    }

    public function test_折れ線は各月末の合計残高で_最初の残高と使わない口座も入り_移動では変わらない(): void
    {
        $food = $this->parent('食費');
        $other = Account::factory()->create(['initial_balance' => 20000]);
        $this->record(RecordType::Expense, '2026-07-10', 30000, $food);
        $this->record(RecordType::Transfer, '2026-08-01', 10000, null, ['to_account_id' => $other->id]);
        $this->record(RecordType::Income, '2026-09-25', 50000, $this->parent('給与', CategoryKind::Income));
        $this->record(RecordType::Adjustment, '2026-09-30', -2000);
        // 今月の先の日付の記録は、今月の残高（今日まで）には入らない
        $this->record(RecordType::Expense, '2026-10-30', 9999, $food);
        // あとで「使わない」にした口座の残高も、過去の月には入る（10 月に 0 にしてから使わないにした）
        $this->record(RecordType::Expense, '2026-10-02', 30000, $food, ['account_id' => $other->id]);
        $other->update(['is_active' => false]);

        $line = $this->chart('2026-10', 'line');

        $this->assertSame(['6月', '7月', '8月', '9月', '10月'], $line['labels']);
        $this->assertSame([120000, 90000, 90000, 138000, 108000], $line['balances']);
        // 今月は、ホームの今の残高の合計と同じ（使わない口座は 0 円なので、合計は変わらない）
        $this->get('/')->assertSee('108,000');
    }

    public function test_来月から先の月は折れ線を引かない(): void
    {
        $line = $this->chart('2026-12', 'line');

        $this->assertSame(['7月', '8月', '9月', '10月', '11月', '12月'], $line['labels']);
        $this->assertSame([100000, 100000, 100000, 100000, null, null], $line['balances']);
    }

    public function test_記録のない月でも前の月の分があればグラフが出る(): void
    {
        $this->record(RecordType::Expense, '2026-08-10', 1000, $this->parent('食費'));

        $this->get('/summary?month=2026-10')->assertSee('この月の記録はまだありません')->assertSee('data-chart="bar"', false)->assertDontSee('data-chart="pie"', false);
    }
}
