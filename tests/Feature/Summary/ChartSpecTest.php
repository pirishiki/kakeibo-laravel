<?php

namespace Tests\Feature\Summary;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 月のまとめのグラフ（要件定義書 10.3「グラフ」、画面設計書 5.5）を、設計書だけを読んで確かめる。
 * グラフの数字は canvas の data-chart-data に入っているので、それを読んで確かめる
 */
class ChartSpecTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 2026年10月24日（土）
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    }

    private function account(int $initial = 0, string $created = '2026-01-01', bool $active = true): Account
    {
        return Account::factory()->create(['initial_balance' => $initial, 'created_at' => $created.' 00:00:00', 'is_active' => $active]);
    }

    private function parent(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    private function record(Account $account, RecordType $type, string $date, int $amount, ?Category $category = null, ?Account $to = null): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => $type,
            'amount' => $amount,
            'account_id' => $account->id,
            'to_account_id' => $to?->id,
            'category_id' => $category?->id,
        ]);
    }

    /**
     * 画面の canvas（data-chart="pie" など）に入っているグラフの数字を取り出す
     *
     * @return array<string, mixed>|null その種類のグラフがなければ null
     */
    private function chart(string $kind, string $url = '/summary'): ?array
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        preg_match_all('/<canvas\b[^>]*>/s', $html, $tags);

        foreach ($tags[0] as $tag) {
            if (! preg_match('/data-chart="'.$kind.'"/', $tag)) {
                continue;
            }
            $this->assertSame(1, preg_match('/data-chart-data="([^"]*)"/s', $tag, $m), 'data-chart-data がある');
            $data = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
            $this->assertIsArray($data, 'data-chart-data は JSON');

            return $data;
        }

        return null;
    }

    // ---------------------------------------------------------------- 円グラフ

    public function test_円グラフは大分類ごとの合計で_小分類の分も入り_多い順に並ぶ(): void
    {
        $a = $this->account();
        $food = $this->parent('食費');
        $eatingOut = Category::factory()->create(['name' => '外食', 'kind' => CategoryKind::Expense, 'parent_id' => $food->id]);
        $this->record($a, RecordType::Expense, '2026-10-01', 3000, $eatingOut);
        $this->record($a, RecordType::Expense, '2026-10-02', 1000, $food);
        $this->record($a, RecordType::Expense, '2026-10-03', 9000, $this->parent('住まい'));
        $this->record($a, RecordType::Expense, '2026-10-04', 2000, $this->parent('日用品'));

        $pie = $this->chart('pie');

        $this->assertSame(['住まい', '食費', '日用品'], $pie['labels']);
        $this->assertEquals([9000, 4000, 2000], $pie['values']);
        $this->assertCount(3, $pie['colors']);
    }

    public function test_円グラフは多い順に5つとほかの6色までで_合計が表の支出の合計と同じになる(): void
    {
        $a = $this->account();
        $names = ['住まい', '食費', '日用品', '交通費', '自動車', 'ローン', '保険'];
        $amounts = [7000, 6000, 5000, 4000, 3000, 2000, 1000];
        foreach ($names as $i => $name) {
            $this->record($a, RecordType::Expense, '2026-10-05', $amounts[$i], $this->parent($name));
        }
        // 収入・移動・調整は支出の合計に入らない
        $this->record($a, RecordType::Income, '2026-10-06', 99999, $this->parent('給与', CategoryKind::Income));
        $this->record($a, RecordType::Transfer, '2026-10-07', 8888, null, $this->account());
        $this->record($a, RecordType::Adjustment, '2026-10-08', -777);

        $pie = $this->chart('pie');

        $this->assertSame(['住まい', '食費', '日用品', '交通費', '自動車', 'ほか'], $pie['labels']);
        $this->assertEquals([7000, 6000, 5000, 4000, 3000, 3000], $pie['values'], 'ほか ＝ ローン 2,000 ＋ 保険 1,000');
        $this->assertCount(6, $pie['colors']);
        $this->assertEquals(28000, array_sum($pie['values']));
        $this->get('/summary')->assertSeeInOrder(['収支', '収入', '99,999', '支出', '28,000']);
    }

    public function test_大分類が5つ以下ならほかは出ない(): void
    {
        $a = $this->account();
        foreach (['住まい' => 5000, '食費' => 4000, '日用品' => 3000, '交通費' => 2000, '自動車' => 1000] as $name => $amount) {
            $this->record($a, RecordType::Expense, '2026-10-05', $amount, $this->parent($name));
        }

        $pie = $this->chart('pie');

        $this->assertCount(5, $pie['labels']);
        $this->assertNotContains('ほか', $pie['labels']);
        $this->assertEquals(15000, array_sum($pie['values']));
    }

    public function test_円グラフの横に色と名前と割合が字で並び_1パーセント未満は切り捨てる(): void
    {
        $a = $this->account();
        $this->record($a, RecordType::Expense, '2026-10-05', 1000, $this->parent('食費'));
        $this->record($a, RecordType::Expense, '2026-10-05', 2000, $this->parent('住まい'));

        // 1,000 ÷ 3,000 ＝ 33.3% → 33%、2,000 ÷ 3,000 ＝ 66.6% → 66%
        $this->get('/summary')->assertSeeInOrder(['住まい', '66%', '食費', '33%']);
    }

    public function test_円グラフは月を切りかえるとその月の分に変わる(): void
    {
        $a = $this->account();
        $this->record($a, RecordType::Expense, '2026-10-05', 1000, $this->parent('食費'));
        $this->record($a, RecordType::Expense, '2026-09-05', 7000, $this->parent('住まい'));

        $this->assertSame(['食費'], $this->chart('pie')['labels']);
        $this->assertSame(['住まい'], $this->chart('pie', '/summary?month=2026-09')['labels']);
    }

    // ---------------------------------------------------------------- 棒グラフ

    public function test_棒グラフは選んだ月までの6か月分で_月の名前は何月の形(): void
    {
        $a = $this->account();
        $this->record($a, RecordType::Expense, '2026-04-01', 100, $this->parent('食費'));

        $bar = $this->chart('bar');

        $this->assertSame(['5月', '6月', '7月', '8月', '9月', '10月'], $bar['labels']);
        $this->assertCount(6, $bar['income']);
        $this->assertCount(6, $bar['expense']);
    }

    public function test_月を切りかえるとグラフも選んだ月までの6か月分に変わり_年をまたぐ(): void
    {
        $a = $this->account(0, '2025-01-01');
        $this->record($a, RecordType::Expense, '2025-06-01', 100, $this->parent('食費'));

        $this->assertSame(['9月', '10月', '11月', '12月', '1月', '2月'], $this->chart('bar', '/summary?month=2027-02')['labels']);
        $this->assertSame(['4月', '5月', '6月', '7月', '8月', '9月'], $this->chart('line', '/summary?month=2026-09')['labels']);
    }

    public function test_棒グラフの金額は_どの月もその月の月のまとめの収支と同じで_移動と調整は入らない(): void
    {
        $a = $this->account(100000);
        $b = $this->account();
        $salary = $this->parent('給与', CategoryKind::Income);
        $food = $this->parent('食費');
        $this->record($a, RecordType::Income, '2026-08-25', 250000, $salary);
        $this->record($a, RecordType::Expense, '2026-08-26', 12345, $food);
        $this->record($a, RecordType::Income, '2026-09-25', 260000, $salary);
        $this->record($a, RecordType::Expense, '2026-09-30', 20000, $food);
        $this->record($a, RecordType::Transfer, '2026-09-10', 50000, null, $b);
        $this->record($a, RecordType::Adjustment, '2026-09-11', -3000);
        $this->record($a, RecordType::Expense, '2026-10-01', 777, $food);

        $bar = $this->chart('bar');

        // labels：5月 6月 7月 8月 9月 10月
        $this->assertEquals([0, 0, 0, 250000, 260000, 0], $bar['income']);
        $this->assertEquals([0, 0, 0, 12345, 20000, 777], $bar['expense']);
        $this->get('/summary?month=2026-08')->assertSeeInOrder(['収支', '収入', '250,000', '支出', '12,345']);
        $this->get('/summary?month=2026-09')->assertSeeInOrder(['収支', '収入', '260,000', '支出', '20,000']);
    }

    public function test_使い始めた月より前の月は_棒グラフにも折れ線グラフにも出ない(): void
    {
        $a = $this->account(0, '2026-08-20');
        $this->record($a, RecordType::Expense, '2026-08-25', 100, $this->parent('食費'));

        $this->assertSame(['8月', '9月', '10月'], $this->chart('bar')['labels']);
        $this->assertSame(['8月', '9月', '10月'], $this->chart('line')['labels']);
    }

    public function test_使い始めた月は_いちばん古い記録の月と最初に口座を作った月の早いほう(): void
    {
        // 口座を作った月（7月）のほうが、記録（8月）より早い
        $a = $this->account(0, '2026-07-15');
        $this->record($a, RecordType::Expense, '2026-08-25', 100, $this->parent('食費'));
        $this->assertSame(['7月', '8月', '9月', '10月'], $this->chart('bar')['labels']);

        // 記録の月（6月）のほうが、口座を作った月より早い
        $this->record($a, RecordType::Expense, '2026-06-02', 100, $this->parent('食費'));
        $this->assertSame(['6月', '7月', '8月', '9月', '10月'], $this->chart('line')['labels']);
    }

    public function test_先の日付の記録があれば_来月を選んだときの棒グラフにその月のまとめと同じ金額が出る(): void
    {
        $a = $this->account();
        $this->record($a, RecordType::Expense, '2026-11-15', 4500, $this->parent('食費'));

        $bar = $this->chart('bar', '/summary?month=2026-11');

        $this->assertSame('11月', end($bar['labels']));
        $this->assertEquals(4500, end($bar['expense']));
        $this->get('/summary?month=2026-11')->assertSeeInOrder(['収支', '支出', '4,500']);
    }

    public function test_選んだ月に記録がなくても_前の月の分があればグラフは出る(): void
    {
        $a = $this->account();
        $this->record($a, RecordType::Expense, '2026-10-05', 3000, $this->parent('食費'));

        $this->get('/summary?month=2026-11')->assertSee('この月の記録はまだありません');
        $bar = $this->chart('bar', '/summary?month=2026-11');
        $this->assertNotNull($bar);
        $this->assertSame('11月', end($bar['labels']));
        $this->assertEquals(3000, $bar['expense'][array_search('10月', $bar['labels'])]);
        $this->assertNotNull($this->chart('line', '/summary?month=2026-11'));
    }

    // ---------------------------------------------------------------- 折れ線グラフ

    public function test_折れ線グラフは各月末の合計残高で_最初の残高はどの月にも入り_今月は今日までの残高(): void
    {
        $a = $this->account(100000, '2026-08-05');
        $food = $this->parent('食費');
        $this->record($a, RecordType::Income, '2026-08-25', 50000, $this->parent('給与', CategoryKind::Income));
        $this->record($a, RecordType::Expense, '2026-09-30', 20000, $food);
        $this->record($a, RecordType::Expense, '2026-10-01', 1000, $food);
        $this->record($a, RecordType::Expense, '2026-10-24', 4000, $food);  // 今日は入る
        $this->record($a, RecordType::Expense, '2026-10-25', 7000, $food);  // 明日は入らない
        $this->record($a, RecordType::Expense, '2026-10-31', 9000, $food);

        $line = $this->chart('line');

        $this->assertSame(['8月', '9月', '10月'], $line['labels']);
        // 8 月末 150,000／9 月末（30 日の記録は入る）130,000／今月は今日まで 125,000
        $this->assertEquals([150000, 130000, 125000], $line['balances']);
        // ホームの今の残高の合計と同じ
        $this->get('/')->assertSee('125,000');
    }

    public function test_月の最後の日の記録はその月の残高に入り_次の月の1日の記録は入らない(): void
    {
        $a = $this->account(10000, '2026-07-01');
        $this->record($a, RecordType::Expense, '2026-08-31', 100, $this->parent('食費'));
        $this->record($a, RecordType::Expense, '2026-09-01', 1000, $this->parent('食費'));

        $line = $this->chart('line', '/summary?month=2026-09');

        $this->assertSame(['7月', '8月', '9月'], $line['labels']);
        $this->assertEquals([10000, 9900, 8900], $line['balances']);
    }

    public function test_来月から先の月を選ぶと_折れ線グラフはその月まで線を引かない(): void
    {
        $a = $this->account(100000, '2026-08-05');
        $this->record($a, RecordType::Expense, '2026-09-10', 20000, $this->parent('食費'));
        $this->record($a, RecordType::Expense, '2026-12-10', 5000, $this->parent('食費'));

        $line = $this->chart('line', '/summary?month=2026-12');

        $this->assertSame(['8月', '9月', '10月', '11月', '12月'], $line['labels']);
        $this->assertEquals([100000, 80000, 80000], array_slice($line['balances'], 0, 3));
        $this->assertNull($line['balances'][3], '来月から先は null');
        $this->assertNull($line['balances'][4], '来月から先は null');
    }

    public function test_最初の残高は使い始めた月の初めからあったもので_あとから前の月の記録を入れてもその月の残高に入る(): void
    {
        // 口座は 10 月に作った（最初の残高 100,000）。あとから 7 月の記録を入れる
        $a = $this->account(100000, '2026-10-20');
        $this->record($a, RecordType::Expense, '2026-07-10', 3000, $this->parent('食費'));

        $line = $this->chart('line');

        $this->assertSame(['7月', '8月', '9月', '10月'], $line['labels']);
        $this->assertEquals([97000, 97000, 97000, 97000], $line['balances']);
    }

    public function test_使わない口座も過去の月の残高に入る(): void
    {
        $a = $this->account(10000, '2026-07-01');
        $b = $this->account(0, '2026-07-01', false);
        $this->record($b, RecordType::Income, '2026-08-05', 30000, $this->parent('給与', CategoryKind::Income));
        $this->record($b, RecordType::Expense, '2026-10-10', 30000, $this->parent('食費'));

        $line = $this->chart('line');

        $this->assertSame(['7月', '8月', '9月', '10月'], $line['labels']);
        // b は今 0 円（使わない）。8 月・9 月末には 30,000 円あった
        $this->assertEquals([10000, 40000, 40000, 10000], $line['balances']);
    }

    public function test_移動は合計残高を変えず_調整は残高を変える(): void
    {
        $a = $this->account(100000, '2026-08-01');
        $b = $this->account(0, '2026-08-01');
        $this->record($a, RecordType::Transfer, '2026-09-10', 40000, null, $b);
        $this->record($a, RecordType::Adjustment, '2026-10-02', -500);

        $line = $this->chart('line');

        $this->assertSame(['8月', '9月', '10月'], $line['labels']);
        $this->assertEquals([100000, 100000, 99500], $line['balances']);
    }

    public function test_グラフは同じ月の収支とずれない_収入だけの月と支出だけの月(): void
    {
        $a = $this->account(0, '2026-08-01');
        $this->record($a, RecordType::Income, '2026-08-10', 30000, $this->parent('給与', CategoryKind::Income));
        $this->record($a, RecordType::Expense, '2026-09-10', 12000, $this->parent('食費'));

        $bar = $this->chart('bar');
        $line = $this->chart('line');

        $this->assertEquals([30000, 0, 0], $bar['income']);
        $this->assertEquals([0, 12000, 0], $bar['expense']);
        $this->assertEquals([30000, 18000, 18000], $line['balances']);
    }
}
