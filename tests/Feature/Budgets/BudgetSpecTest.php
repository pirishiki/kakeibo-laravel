<?php

namespace Tests\Feature\Budgets;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Record;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 予算の決まり（要件定義書 10.3、画面設計書 5.2・5.5・5.11・5.12、データベース設計書 3.9）を、設計書だけを読んで確かめる。
 * 実装（app/Support など）は見ずに、画面に出る文と保存された行で確かめる
 */
class BudgetSpecTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 2026年10月24日（土）
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'created_at' => '2026-01-01 00:00:00']);
    }

    private function parent(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    private function child(Category $parent, string $name): Category
    {
        return Category::factory()->create(['name' => $name, 'kind' => $parent->kind, 'parent_id' => $parent->id]);
    }

    private function spend(Category $category, int $amount, string $date = '2026-10-10'): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => RecordType::Expense,
            'amount' => $amount,
            'account_id' => $this->cash->id,
            'category_id' => $category->id,
        ]);
    }

    private function usual(Category $category, int $amount, string $start = '2026-04-01', ?string $end = null): Budget
    {
        return Budget::create(['category_id' => $category->id, 'month' => null, 'start_month' => $start, 'end_month' => $end, 'amount' => $amount]);
    }

    private function special(Category $category, string $month, int $amount): Budget
    {
        return Budget::create(['category_id' => $category->id, 'month' => $month, 'start_month' => null, 'end_month' => null, 'amount' => $amount]);
    }

    /**
     * @param  array<int, string|int>  $amounts
     * @param  array<int, string|int>  $originals
     */
    private function save(array $amounts, array $originals, ?string $month = null): TestResponse
    {
        $data = ['amounts' => $amounts, 'originals' => $originals];
        if ($month !== null) {
            $data['month'] = $month;
        }

        return $this->put('/budgets', $data);
    }

    /**
     * @return Collection<int, Budget>
     */
    private function usualRows(Category $category)
    {
        return Budget::query()->where('category_id', $category->id)->whereNull('month')->orderBy('start_month')->get();
    }

    private function usualAmountIn(Category $category, string $month): ?int
    {
        return Budget::query()->where('category_id', $category->id)->usualFor(CarbonImmutable::parse($month))->value('amount');
    }

    // ---------------------------------------------------------------- 予算を決める画面

    public function test_支出の分類だけが分類の管理と同じ順で並び_収入と使わないは出ない(): void
    {
        $food = $this->parent('食費');
        $this->child($food, '外食らしき小分類');
        Category::factory()->create(['name' => 'ふしぎな収入', 'kind' => CategoryKind::Income]);
        Category::factory()->create(['name' => '使わない大分類', 'kind' => CategoryKind::Expense, 'is_active' => false]);
        $this->child($this->parent('日用品'), '使わない小分類')->update(['is_active' => false]);

        $this->get('/budgets')->assertOk()
            ->assertSeeInOrder(['食費', '外食らしき小分類', '日用品', '交通費', '住まい'])
            ->assertDontSee('ふしぎな収入')
            ->assertDontSee('使わない大分類')
            ->assertDontSee('使わない小分類');
    }

    public function test_まとめて保存で全部の欄が保存され_カンマつきも使え_空は予算なしで_保存しましたと出る(): void
    {
        $food = $this->parent('食費');
        $daily = $this->parent('日用品');
        $car = $this->parent('自動車');

        $response = $this->save(
            [$food->id => '30,000', $daily->id => '8000', $car->id => ''],
            [$food->id => '', $daily->id => '', $car->id => ''],
        );

        $response->assertRedirect(route('budgets.index'));
        $response->assertSessionHas('status', '保存しました');
        $this->assertSame(30000, $this->usualAmountIn($food, '2026-10-01'));
        $this->assertSame(8000, $this->usualAmountIn($daily, '2026-10-01'));
        $this->assertSame(0, Budget::query()->where('category_id', $car->id)->count());

        $row = $this->usualRows($food)->first();
        $this->assertNull($row->month);
        $this->assertSame('2026-10-01', $row->start_month->toDateString(), 'ふだんの予算は保存した月から');
        $this->assertNull($row->end_month);
    }

    public function test_1円以上の整数でない金額は保存されない(): void
    {
        $food = $this->parent('食費');

        foreach (['0', '-5', 'abc', '1.5', '100000000'] as $bad) {
            $this->save([$food->id => $bad], [$food->id => ''])->assertRedirect();
            $this->assertSame(0, Budget::query()->count(), "「{$bad}」は保存してはいけない");
        }
    }

    public function test_ふだんの予算を変えると_今月から新しい金額になり_過ぎた月は変わらない(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000, '2026-04-01');

        $this->save([$food->id => '35000'], [$food->id => '30000'])->assertSessionHas('status', '保存しました');

        $rows = $this->usualRows($food);
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-01', $rows[0]->end_month->toDateString());
        $this->assertSame(30000, $rows[0]->amount);
        $this->assertSame('2026-10-01', $rows[1]->start_month->toDateString());
        $this->assertNull($rows[1]->end_month);
        $this->assertSame(35000, $rows[1]->amount);
        $this->assertSame(30000, $this->usualAmountIn($food, '2026-09-01'));
        $this->assertSame(35000, $this->usualAmountIn($food, '2026-10-01'));
        $this->assertSame(35000, $this->usualAmountIn($food, '2027-03-01'));
    }

    public function test_今月から始まったふだんの予算は_金額を書きかえるだけで行はふえない(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000, '2026-10-01');

        $this->save([$food->id => '35000'], [$food->id => '30000']);

        $rows = $this->usualRows($food);
        $this->assertCount(1, $rows);
        $this->assertSame(35000, $rows[0]->amount);
        $this->assertSame('2026-10-01', $rows[0]->start_month->toDateString());
        $this->assertNull($rows[0]->end_month);
    }

    public function test_欄を空にして保存すると予算をやめ_行のおわりに先月が入る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000, '2026-04-01');

        $this->save([$food->id => ''], [$food->id => '30000'])->assertSessionHas('status', '保存しました');

        $rows = $this->usualRows($food);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-09-01', $rows[0]->end_month->toDateString());
        $this->assertNull($this->usualAmountIn($food, '2026-10-01'));
        $this->assertSame(30000, $this->usualAmountIn($food, '2026-09-01'));
    }

    public function test_今月から始まったふだんの予算をやめると行そのものが消える(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000, '2026-10-01');

        $this->save([$food->id => ''], [$food->id => '30000']);

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_月末に開いて月初めに保存したら_新しい月から新しい金額になる(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000, '2026-04-01');
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 5));

        $this->save([$food->id => '35000'], [$food->id => '30000']);

        $rows = $this->usualRows($food);
        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-01', $rows[0]->end_month->toDateString());
        $this->assertSame('2026-11-01', $rows[1]->start_month->toDateString());
    }

    public function test_特別な月は来月から切りかえられ_分類の横にふだんの予算が出て_空の欄はふだんのまま(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000);

        $this->get('/budgets')->assertSee('month=2026-11', false);

        $this->get('/budgets?month=2026-12')->assertOk()
            ->assertSee('2026年12月')
            ->assertSeeInOrder(['食費', 'ふだん', '30,000'])
            ->assertSee('ふだんのまま');
    }

    public function test_特別な月の保存は_その月の1日の行になり_空の欄は行を作らず_空にすると行が消える(): void
    {
        $food = $this->parent('食費');
        $daily = $this->parent('日用品');
        $this->usual($food, 30000);

        $this->save([$food->id => '40,000', $daily->id => ''], [$food->id => '', $daily->id => ''], '2026-12')
            ->assertSessionHas('status', '保存しました');

        $special = Budget::query()->where('category_id', $food->id)->whereNotNull('month')->get();
        $this->assertCount(1, $special);
        $this->assertSame('2026-12-01', $special[0]->month->toDateString());
        $this->assertSame(40000, $special[0]->amount);
        $this->assertSame(0, Budget::query()->where('category_id', $daily->id)->count(), '空は「ふだんのまま」で行を作らない');
        // ふだんの予算は動かない
        $this->assertSame(30000, $this->usualAmountIn($food, '2026-12-01'));

        $this->save([$food->id => ''], [$food->id => '40000'], '2026-12');
        $this->assertSame(0, Budget::query()->whereNotNull('month')->count());
    }

    public function test_特別な月は今月から12か月先まで_過ぎた月と13か月先は保存されない(): void
    {
        $food = $this->parent('食費');

        $this->save([$food->id => '1000'], [$food->id => ''], '2026-10')->assertRedirect();
        $this->save([$food->id => '2000'], [$food->id => ''], '2027-10')->assertRedirect();
        $this->assertSame(2, Budget::query()->whereNotNull('month')->count());

        Budget::query()->delete();
        $this->save([$food->id => '3000'], [$food->id => ''], '2026-09')->assertRedirect();
        $this->save([$food->id => '4000'], [$food->id => ''], '2027-11')->assertRedirect();
        $this->assertSame(0, Budget::query()->count(), '過ぎた月と 13 か月先は直せない');
    }

    public function test_特別な月を開いたまま月が変わって直せなくなったら_何も保存せず今月を出す(): void
    {
        $food = $this->parent('食費');
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 5));

        $this->followingRedirects()->put('/budgets', [
            'amounts' => [$food->id => '40000'], 'originals' => [$food->id => ''], 'month' => '2026-10',
        ])
            ->assertSee('選んでいた月は、もう直せない月になったので、保存していません。今月の分を出したので、もう一度入れてください。')
            ->assertSee('2026年11月');

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_小分類の予算の合計が大分類をこえると注意が出るが_保存はできる(): void
    {
        $food = $this->parent('食費');
        $groceries = $this->child($food, '食料品');
        $eatingOut = $this->child($food, '外食');

        $this->save(
            [$food->id => '30000', $groceries->id => '25000', $eatingOut->id => '10000'],
            [$food->id => '', $groceries->id => '', $eatingOut->id => ''],
        )->assertSessionHas('status', '保存しました');

        $this->assertSame(3, Budget::query()->count(), '注意が出ても保存はできる');
        $this->get('/budgets')->assertSee('小分類の予算の合計（35,000）が、食費の予算（30,000）をこえています');
    }

    public function test_小分類の予算の合計が大分類と同じか少ないなら注意は出ない(): void
    {
        $food = $this->parent('食費');
        $groceries = $this->child($food, '食料品');
        $this->usual($food, 30000);
        $this->usual($groceries, 30000);

        $this->get('/budgets')->assertOk()->assertDontSee('をこえています');
    }

    // ---------------------------------------------------------------- 古い画面から保存したとき

    public function test_開いたあとにほかの画面で予算が変わっていたら_その分類は保存せず_ほかは保存する(): void
    {
        $food = $this->parent('食費');
        $daily = $this->parent('日用品');
        // 開いたとき食費は 30,000 だった → ほかのタブで 32,000 に変えられた
        $this->usual($food, 32000);

        $this->followingRedirects()->put('/budgets', [
            'amounts' => [$food->id => '40000', $daily->id => '8000'],
            'originals' => [$food->id => '30000', $daily->id => ''],
        ])
            ->assertSee('ほかの分類は保存しました。')
            ->assertSee('「食費」は、開いたあとにほかの画面で予算が変わっていたので、保存していません。今の予算を出したので、確かめてからもう一度保存してください。');

        $this->assertSame(32000, $this->usualAmountIn($food, '2026-10-01'), '食費は上書きしない');
        $this->assertCount(1, $this->usualRows($food));
        $this->assertSame(8000, $this->usualAmountIn($daily, '2026-10-01'));
    }

    public function test_開いたあとにほかの画面で予算が作られていたとき_開いたときが空だった欄も保存しない(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000);

        $this->followingRedirects()->put('/budgets', [
            'amounts' => [$food->id => '20000'], 'originals' => [$food->id => ''],
        ])->assertSee('「食費」は、開いたあとにほかの画面で予算が変わっていたので、保存していません');

        $this->assertSame(30000, $this->usualAmountIn($food, '2026-10-01'));
        $this->assertCount(1, $this->usualRows($food));
    }

    public function test_開いたあとにほかの画面で予算がやめられていたとき_古い金額のまま保存しても復活させない(): void
    {
        $food = $this->parent('食費');
        // 開いたときは 30,000 → ほかのタブでやめられた（予算なし）。打った金額 35,000 は保存しない
        $this->followingRedirects()->put('/budgets', [
            'amounts' => [$food->id => '35000'], 'originals' => [$food->id => '30000'],
        ])->assertSee('保存していません');

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_変えていない欄は_ほかのタブで直されていても上書きしない_メッセージも出ない(): void
    {
        $food = $this->parent('食費');
        $daily = $this->parent('日用品');
        $this->usual($food, 32000);

        $this->followingRedirects()->put('/budgets', [
            'amounts' => [$food->id => '30,000', $daily->id => '8000'],
            'originals' => [$food->id => '30000', $daily->id => ''],
        ])->assertSee('保存しました')->assertDontSee('保存していません');

        $this->assertSame(32000, $this->usualAmountIn($food, '2026-10-01'));
        $this->assertCount(1, $this->usualRows($food));
        $this->assertSame(8000, $this->usualAmountIn($daily, '2026-10-01'));
    }

    public function test_開いたあとに使わないになった分類と削除された分類は_保存せずエラー画面にもならない(): void
    {
        $hobby = $this->parent('趣味・娯楽');
        $gone = Category::factory()->create(['name' => '消える分類']);
        $daily = $this->parent('日用品');
        $hobby->update(['is_active' => false]);
        $goneId = $gone->id;
        $gone->delete();

        $this->followingRedirects()->put('/budgets', [
            'amounts' => [$hobby->id => '5000', $goneId => '5000', $daily->id => '8000'],
            'originals' => [$hobby->id => '', $goneId => '', $daily->id => ''],
        ])->assertOk()->assertSee('趣味・娯楽');

        $this->assertSame(0, Budget::query()->where('category_id', $hobby->id)->count());
        $this->assertSame(0, Budget::query()->where('category_id', $goneId)->count());
        $this->assertSame(8000, $this->usualAmountIn($daily, '2026-10-01'));
    }

    // ---------------------------------------------------------------- 使わない・削除

    public function test_使わないにした分類の予算は消えずに残り_画面と今月のまとめとお知らせには出ず_使うに戻すとまた使われる(): void
    {
        $hobby = $this->parent('趣味・娯楽');
        $daily = $this->parent('日用品');
        $this->usual($hobby, 10000);
        $this->spend($hobby, 9000);
        $hobby->update(['is_active' => false]);

        $this->get('/budgets')->assertDontSee('趣味・娯楽');
        $this->get('/summary')->assertSee('趣味・娯楽')->assertDontSee('予算 10,000');
        $this->get('/')->assertDontSee('趣味・娯楽 が予算');

        // ほかの分類を保存しても、使わない分類の予算は消えない
        $this->save([$daily->id => '8000'], [$daily->id => '']);
        $this->assertSame(10000, $this->usualAmountIn($hobby, '2026-10-01'));

        $hobby->update(['is_active' => true]);
        $this->get('/summary')->assertSeeInOrder(['趣味・娯楽', '予算 10,000', 'あと 1,000（90%）']);
        $this->get('/')->assertSee('趣味・娯楽 が予算の 90% です（あと 1,000 円）');
    }

    public function test_使わないにした分類の予算は_過ぎた月のまとめにはそのときの予算の状況が出る(): void
    {
        $hobby = $this->parent('趣味・娯楽');
        $this->usual($hobby, 10000, '2026-04-01');
        $this->spend($hobby, 12800, '2026-09-10');
        $this->spend($hobby, 500, '2026-10-10');
        $hobby->update(['is_active' => false]);

        $this->get('/summary?month=2026-09')->assertSeeInOrder(['趣味・娯楽', '予算 10,000', '2,800 こえた']);
        $this->get('/summary?month=2026-10')->assertSee('趣味・娯楽')->assertDontSee('予算 10,000');
    }

    public function test_大分類を使わないにすると_下の小分類の予算も使わない分類と同じ扱いになる(): void
    {
        $car = $this->parent('自動車');
        $gas = $this->child($car, 'ガソリン');
        $this->usual($gas, 6000, '2026-04-01');
        $this->spend($gas, 5400, '2026-09-10');
        $this->spend($gas, 5400, '2026-10-10');
        $car->update(['is_active' => false]);

        $this->get('/budgets')->assertDontSee('ガソリン')->assertDontSee('自動車');
        $this->get('/summary')->assertSee('ガソリン')->assertDontSee('予算 6,000');
        $this->get('/')->assertDontSee('ガソリン が予算');
        $this->get('/summary?month=2026-09')->assertSee('予算 6,000');
    }

    public function test_記録のない分類を削除すると_その分類の予算もいっしょに消える(): void
    {
        $category = Category::factory()->create(['name' => '消す分類']);
        $this->usual($category, 5000);
        $this->special($category, '2026-12-01', 7000);

        $this->delete(route('categories.destroy', $category))->assertRedirect();

        $this->assertNull(Category::query()->find($category->id));
        $this->assertSame(0, Budget::query()->where('category_id', $category->id)->count());
    }

    public function test_設定の予算の件数は_今月に使われる予算がある使う分類の数(): void
    {
        $food = $this->parent('食費');
        $daily = $this->parent('日用品');
        $hobby = $this->parent('趣味・娯楽');
        $traffic = $this->parent('交通費');
        $home = $this->parent('住まい');
        $this->usual($food, 1000);                                  // 数える
        $this->usual($daily, 1000);                                 // 数える（特別な月もあるが 1 分類は 1 件）
        $this->special($daily, '2026-10-01', 2000);
        $this->usual($hobby, 1000);                                 // 使わないので数えない
        $hobby->update(['is_active' => false]);
        $this->usual($traffic, 1000, '2026-04-01', '2026-09-01');   // 先月でおわり：数えない
        $this->special($home, '2026-11-01', 5000);                  // 来月だけ：今月には使われない

        $this->get('/settings')->assertOk()->assertSee('分類ごとの毎月の予算（今月 2 件）');
    }

    // ---------------------------------------------------------------- 月のまとめの予算の状況・ホームのお知らせ

    public function test_予算の行は_予算とあとの額と割合の形で出る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000);
        $this->spend($food, 27000);

        $this->get('/summary')->assertSeeInOrder(['食費', '予算 30,000', 'あと 3,000（90%）']);
    }

    public function test_予算より多く使ったときは_こえた額が出る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 12800);

        $this->get('/summary')->assertSeeInOrder(['食費', '予算 10,000', '2,800 こえた']);
        $this->get('/')->assertSee('食費 が予算をこえました（+2,800 円）');
    }

    public function test_80パーセントちょうどからお知らせが出る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 8000);

        $this->get('/')->assertSee('食費 が予算の 80% です（あと 2,000 円）');
        $this->get('/summary')->assertSeeInOrder(['予算 10,000', 'あと 2,000（80%）']);
    }

    public function test_79_95パーセントは80パーセント未満なのでお知らせは出ず_まとめには79パーセントと出る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 7995);

        $this->get('/')->assertDontSee('食費 が予算');
        $this->get('/summary')->assertSeeInOrder(['予算 10,000', 'あと 2,005（79%）']);
    }

    public function test_80パーセントを少しこえた8009円は80パーセントと出る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 8009);

        $this->get('/')->assertSee('食費 が予算の 80% です（あと 1,991 円）');
    }

    public function test_99_99パーセントは切り捨てて99パーセントと出る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 9999);

        $this->get('/')->assertSee('食費 が予算の 99% です（あと 1 円）')->assertDontSee('予算をこえました');
    }

    public function test_予算とちょうど同じ額は_100パーセントです_で_こえましたとは出ない(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 10000);

        $this->get('/')->assertSee('食費 が予算の 100% です（あと 0 円）')
            ->assertDontSee('予算をこえました');
        $this->get('/summary')->assertSeeInOrder(['予算 10,000', 'あと 0（100%）'])->assertDontSee('こえた');
    }

    public function test_予算より1円多いと_こえましたと出る(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 10001);

        $this->get('/')->assertSee('食費 が予算をこえました（+1 円）')->assertDontSee('食費 が予算の');
        $this->get('/summary')->assertSee('1 こえた');
    }

    public function test_特別な月の予算がある月は_その金額で比べる(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000);
        $this->special($food, '2026-10-01', 20000);
        $this->spend($food, 18000);

        $this->get('/summary')->assertSee('予算 20,000')->assertDontSee('予算 30,000');
        $this->get('/')->assertSee('食費 が予算の 90% です（あと 2,000 円）');
    }

    public function test_特別な月でない月は_ふだんの予算で比べる_ほかの月の特別な予算は使わない(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000);
        $this->special($food, '2026-12-01', 40000);
        $this->spend($food, 1000, '2026-11-10');
        $this->spend($food, 1000, '2026-12-10');

        $this->get('/summary?month=2026-11')->assertSee('予算 30,000')->assertDontSee('予算 40,000');
        $this->get('/summary?month=2026-12')->assertSee('予算 40,000')->assertDontSee('予算 30,000');
    }

    public function test_ふだんの予算を変えた月の前後で_それぞれその月の予算で比べる(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000, '2026-04-01', '2026-09-01');
        $this->usual($food, 35000, '2026-10-01');
        foreach (['2026-08-31', '2026-09-30', '2026-10-01', '2026-11-30'] as $date) {
            $this->spend($food, 1000, $date);
        }

        $this->get('/summary?month=2026-08')->assertSee('予算 30,000');
        $this->get('/summary?month=2026-09')->assertSee('予算 30,000')->assertDontSee('予算 35,000');
        $this->get('/summary?month=2026-10')->assertSee('予算 35,000')->assertDontSee('予算 30,000');
        $this->get('/summary?month=2026-11')->assertSee('予算 35,000');
    }

    public function test_画面から金額を変えたあとも_過ぎた月のまとめは変わらない(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 30000);
        $this->spend($food, 12800, '2026-09-10');
        $this->spend($food, 12800, '2026-10-10');

        $this->save([$food->id => '35000'], [$food->id => '30000']);

        $this->get('/summary?month=2026-09')->assertSee('予算 30,000')->assertDontSee('予算 35,000');
        $this->get('/summary?month=2026-10')->assertSee('予算 35,000')->assertDontSee('予算 30,000');
    }

    public function test_大分類の使った額には小分類の分も入り_大分類に予算がなく小分類だけにあってもよい(): void
    {
        $food = $this->parent('食費');
        $eatingOut = $this->child($food, '外食');
        $this->usual($food, 20000);
        $this->spend($eatingOut, 5000);
        $this->spend($food, 3000);

        // 大分類：小分類 5,000 ＋ 小分類なし 3,000 ＝ 8,000 → あと 12,000（40%）
        $this->get('/summary')->assertSeeInOrder(['食費', '予算 20,000', 'あと 12,000（40%）']);

        // 大分類の予算をやめて小分類だけに予算
        Budget::query()->delete();
        $this->usual($eatingOut, 10000);
        $this->get('/summary')->assertSeeInOrder(['食費', '外食', '予算 10,000', 'あと 5,000（50%）'])
            ->assertDontSee('予算 20,000');
    }

    public function test_大分類と小分類の両方が当てはまるときはお知らせが両方出て_80パーセントより少ない分類は出ない(): void
    {
        $food = $this->parent('食費');
        $eatingOut = $this->child($food, '外食');
        $daily = $this->parent('日用品');
        $this->usual($food, 20000);
        $this->usual($eatingOut, 10000);
        $this->usual($daily, 10000);
        $this->spend($eatingOut, 9500);
        $this->spend($food, 8500);
        $this->spend($daily, 7000);

        $this->get('/')->assertOk()
            ->assertSee('食費 が予算の 90% です（あと 2,000 円）')
            ->assertSee('食費 › 外食 が予算の 95% です（あと 500 円）')
            ->assertDontSee('日用品 が予算');
    }

    public function test_お知らせは今月の分だけで_先月にこえていても出ない(): void
    {
        $food = $this->parent('食費');
        $this->usual($food, 10000);
        $this->spend($food, 15000, '2026-09-30');
        $this->spend($food, 100, '2026-10-02');

        $this->get('/')->assertDontSee('食費 が予算');
    }

    public function test_予算を決めていない分類には予算の行は出ない(): void
    {
        $this->spend($this->parent('食費'), 5000);

        $this->get('/summary')->assertSee('食費')->assertDontSee('あと ')->assertDontSee('こえた');
    }
}
