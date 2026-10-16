<?php

namespace Tests\Feature\Budgets;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 要件定義書 10.3 の「予算を決める」「予算の状況とお知らせ」「ほかの画面とのつながり」（No.19、画面設計書 5.11・5.5・5.2）を確かめる。
 * 今日は 2026年10月24日（土）
 */
class BudgetScreenTest extends TestCase
{
    use RefreshDatabase;

    private Category $food;

    private Category $eatOut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        $this->food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        $this->eatOut = Category::factory()->childOf($this->food)->create(['name' => '外食']);
    }

    /** ふだんの予算を直接入れる */
    private function usual(Category $category, int $amount, string $start = '2026-04-01', ?string $end = null): Budget
    {
        return Budget::create(['category_id' => $category->id, 'start_month' => $start, 'end_month' => $end, 'amount' => $amount]);
    }

    private function spend(Category $category, int $amount, string $date = '2026-10-10'): Record
    {
        return Record::factory()->create([
            'date' => $date, 'type' => RecordType::Expense, 'amount' => $amount,
            'category_id' => $category->id, 'partner_id' => Partner::factory()->create()->id,
        ]);
    }

    /**
     * 予算を決める画面から保存する（開いたときの金額もいっしょに送る）
     *
     * @param  array<int, string>  $amounts
     * @param  array<int, string>  $originals
     */
    private function save(array $amounts, array $originals = [], ?string $month = null): TestResponse
    {
        return $this->put('/budgets', array_filter([
            'amounts' => $amounts,
            'originals' => $originals + array_fill_keys(array_keys($amounts), ''),
            'month' => $month,
        ], fn ($v) => $v !== null));
    }

    public function test_支出の分類だけが分類の管理の順に並び_収入と使わない分類は出ない(): void
    {
        $unused = Category::factory()->create(['name' => '使わない大分類', 'is_active' => false]);
        Category::factory()->childOf($unused)->create(['name' => '親が使わない小分類']);
        Category::factory()->childOf($this->food)->create(['name' => '使わない小分類', 'is_active' => false]);

        $this->get('/budgets')
            ->assertOk()
            ->assertSeeInOrder(['食費', '外食', '日用品'])
            ->assertDontSee('給与')
            ->assertDontSee('使わない大分類')
            ->assertDontSee('親が使わない小分類')
            ->assertDontSee('使わない小分類');
    }

    public function test_ふだんの予算を保存すると今月からの行ができ_同じ画面で保存しましたと出る(): void
    {
        $this->save([$this->food->id => '30,000', $this->eatOut->id => '１００００'])
            ->assertRedirect('/budgets')
            ->assertSessionHas('status', '保存しました');

        $this->assertDatabaseHas('budgets', ['category_id' => $this->food->id, 'start_month' => '2026-10-01', 'end_month' => null, 'amount' => 30000, 'month' => null]);
        $this->assertDatabaseHas('budgets', ['category_id' => $this->eatOut->id, 'amount' => 10000]);
        $this->get('/budgets')->assertSee('value="30,000"', false);
    }

    public function test_ふだんの予算を変えると_前の行は先月までになり_過ぎた月の予算は変わらない(): void
    {
        $old = $this->usual($this->food, 30000);

        $this->save([$this->food->id => '35000'], [$this->food->id => '30000'])->assertSessionHas('status');

        $this->assertSame('2026-09-01', $old->fresh()?->end_month?->toDateString());
        $this->assertDatabaseHas('budgets', ['category_id' => $this->food->id, 'start_month' => '2026-10-01', 'amount' => 35000]);
        $this->spend($this->food, 27000, '2026-09-10');
        // 9 月は 30,000 でくらべる（90%）
        $this->get('/summary?month=2026-09')->assertSee('予算 30,000')->assertSee('あと 3,000（90%）');
    }

    public function test_今月から始まった行は_金額を書きかえるだけで_やめると消える(): void
    {
        $row = $this->usual($this->food, 30000, '2026-10-01');

        $this->save([$this->food->id => '20000'], [$this->food->id => '30000']);
        $this->assertSame(1, Budget::query()->count());
        $this->assertSame(20000, $row->fresh()?->amount);

        $this->save([$this->food->id => ''], [$this->food->id => '20000']);
        $this->assertSame(0, Budget::query()->count());
    }

    public function test_前の月からの予算をやめると_先月までになって残る(): void
    {
        $row = $this->usual($this->food, 30000);

        $this->save([$this->food->id => ''], [$this->food->id => '30000']);

        $this->assertSame('2026-09-01', $row->fresh()?->end_month?->toDateString());
        $this->get('/summary')->assertDontSee('予算 30,000');
    }

    public function test_金額のまちがいは欄の下に出て_何も保存しない(): void
    {
        $this->from('/budgets')->save([$this->food->id => '0', $this->eatOut->id => 'abc'])
            ->assertRedirect('/budgets')
            ->assertSessionHasErrors([
                'amounts.'.$this->food->id => '1 円以上、99,999,999 円までの整数で入れてください',
                'amounts.'.$this->eatOut->id => '数字だけで入れてください',
            ]);
        $this->from('/budgets')->save([$this->food->id => '100,000,000'])->assertSessionHasErrors('amounts.'.$this->food->id);

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_小分類の予算の合計が大分類をこえると注意が出るが保存はできる(): void
    {
        $other = Category::factory()->childOf($this->food)->create(['name' => '食料品']);
        $this->save([$this->food->id => '30000', $this->eatOut->id => '10000', $other->id => '25000'])->assertSessionHas('status', '保存しました');

        $this->get('/budgets')->assertSee('小分類の予算の合計（35,000）が、食費の予算（30,000）をこえています');
    }

    public function test_特別な月は来月で開き_今月から12か月先まで切りかえられる(): void
    {
        $this->usual($this->food, 30000);

        $this->get('/budgets?month=')->assertSee('2026年11月');
        $this->get('/budgets?month=2026-10')->assertSee('2026年10月')->assertDontSee('month=2026-09')->assertSee('month=2026-11');
        $this->get('/budgets?month=2027-10')->assertSee('2027年10月')->assertSee('month=2027-09')->assertDontSee('month=2027-11');
        // 範囲の外は来月にする
        $this->get('/budgets?month=2026-09')->assertSee('2026年11月');
        $this->get('/budgets?month=2026-12')->assertSee('ふだん 30,000')->assertSee('placeholder="ふだんのまま"', false);
    }

    public function test_特別な月の予算はその月だけ使われ_空にするとふだんのままに戻る(): void
    {
        $this->usual($this->food, 30000);
        $this->save([$this->food->id => '40000'], [], '2026-12')->assertRedirect('/budgets?month=2026-12');

        $this->assertDatabaseHas('budgets', ['category_id' => $this->food->id, 'month' => '2026-12-01', 'amount' => 40000]);
        $this->spend($this->food, 36000, '2026-12-05');
        $this->get('/summary?month=2026-12')->assertSee('予算 40,000');

        $this->save([$this->food->id => ''], [$this->food->id => '40000'], '2026-12');
        $this->get('/summary?month=2026-12')->assertSee('予算 30,000');
    }

    public function test_月のまとめに予算の状況が出て_80パーセント以上は赤になる(): void
    {
        $this->usual($this->food, 30000);
        $this->usual($this->eatOut, 10000);
        $this->spend($this->eatOut, 12800);
        $this->spend($this->food, 10000);

        $this->get('/summary')
            // 大分類の使った額には小分類の分も入る（22,800 / 30,000 = 76%）
            ->assertSee('予算 30,000')->assertSee('あと 7,200（76%）')
            ->assertSee('予算 10,000')->assertSee('2,800 こえた')
            ->assertSee('予算を決める');
    }

    public function test_使わない分類の予算は今月の月のまとめに出ず_過ぎた月には出て_戻すとまた使われる(): void
    {
        $this->usual($this->food, 30000);
        $this->spend($this->food, 1000, '2026-09-10');
        $this->spend($this->food, 1000);
        $this->food->update(['is_active' => false]);

        $this->get('/summary')->assertDontSee('予算 30,000');
        $this->get('/summary?month=2026-09')->assertSee('予算 30,000');
        $this->get('/budgets')->assertDontSee('食費');

        $this->food->update(['is_active' => true]);
        $this->get('/summary')->assertSee('予算 30,000');
    }

    public function test_大分類を使わないにすると_小分類の予算も今月は使わない(): void
    {
        $this->usual($this->eatOut, 10000);
        $this->spend($this->eatOut, 9000);
        $this->food->update(['is_active' => false]);

        $this->get('/')->assertDontSee('外食 が予算');
        $this->get('/summary')->assertDontSee('予算 10,000');
    }

    public function test_ホームのお知らせは80パーセント以上の分類だけで_大分類と小分類の両方が出る(): void
    {
        $daily = Category::query()->whereNull('parent_id')->where('name', '日用品')->firstOrFail();
        $this->usual($this->food, 30000);
        $this->usual($this->eatOut, 10000);
        $this->usual($daily, 10000);
        $this->spend($this->eatOut, 12800);
        $this->spend($this->food, 12000);
        // 79.95% は出ない
        $this->spend($daily, 7995);

        $this->get('/')
            ->assertSeeInOrder(['食費 が予算の 82% です（あと 5,200 円）', '外食 が予算をこえました（+2,800 円）'])
            ->assertDontSee('日用品 が予算');
    }

    public function test_予算とちょうど同じ額は100パーセントで_こえましたではない(): void
    {
        $this->usual($this->food, 10000);
        $this->spend($this->food, 10000);

        $this->get('/')->assertSee('食費 が予算の 100% です（あと 0 円）')->assertDontSee('予算をこえました');
    }

    public function test_記録のない分類を削除すると_その予算も消える(): void
    {
        $this->usual($this->eatOut, 10000);
        Budget::create(['category_id' => $this->eatOut->id, 'month' => '2026-12-01', 'amount' => 20000]);

        $this->delete(route('categories.destroy', $this->eatOut))->assertRedirect(route('categories.index'));

        $this->assertSame(0, Budget::query()->count());
    }

    public function test_小分類の親を予算のある大分類へ選び直すと_予算の状況も変わると出る(): void
    {
        $daily = Category::query()->whereNull('parent_id')->where('name', '日用品')->firstOrFail();
        $this->usual($daily, 8000, '2026-04-01', '2026-06-01');
        $this->spend($this->eatOut, 500);

        $this->get(route('categories.edit', $this->eatOut))
            ->assertSee('data-show-when-value="'.$daily->id.'"', false)
            ->assertSee('日用品 の過去の月の予算の状況（こえた・こえていない）も変わります');
    }

    public function test_メニューと設定に予算が出る(): void
    {
        $this->usual($this->food, 30000);

        $this->get('/settings')->assertSee('分類ごとの毎月の予算（今月 1 件）');
        // パソコンの左のメニューに「予算」があり、開いているときは印がつく（スマホでは「設定」に印）
        $html = (string) $this->get('/budgets')->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('budgets.index'), '#').'" class="list-group-item list-group-item-action active"\s+aria-current="page"\s*>予算</a>#', $html);
        $this->assertMatchesRegularExpression('#class="bottom-menu-item active"\s+aria-current="page"\s*>設定</a>#', $html);
    }
}
