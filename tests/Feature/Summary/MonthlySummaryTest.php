<?php

namespace Tests\Feature\Summary;

use App\Enums\AccountType;
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
 * 要件定義書 10.1 の「ホーム・月のまとめ」のうち、月のまとめ（No.9・10・16・17、画面設計書 5.5）の項目を確かめる
 */
class MonthlySummaryTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $ic;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash]);
        $this->ic = Account::factory()->create(['name' => 'Suica', 'type' => AccountType::TransitIc]);
        $this->shop = Partner::factory()->create(['name' => 'コンビニ']);
    }

    private function parent(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    private function child(Category $parent, string $name): Category
    {
        return Category::factory()->create(['name' => $name, 'kind' => $parent->kind, 'parent_id' => $parent->id]);
    }

    private function record(RecordType $type, string $date, int $amount, ?Category $category = null, ?Partner $partner = null): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => $type,
            'amount' => $amount,
            'account_id' => $this->cash->id,
            'category_id' => $category?->id,
            'partner_id' => ($partner ?? $this->shop)->id,
        ]);
    }

    public function test_ログインしないと月のまとめは開けない(): void
    {
        auth()->logout();

        $this->get('/summary')->assertRedirect('/login');
    }

    public function test_前の月と次の月に切りかえられ_全部がその月に変わる(): void
    {
        $food = $this->parent('食費');
        $this->record(RecordType::Expense, '2026-10-31', 1200, $food, Partner::factory()->create(['name' => '十月の店']));
        $this->record(RecordType::Expense, '2026-09-01', 3400, $this->parent('日用品'), Partner::factory()->create(['name' => '九月の店']));

        // 何も書かないときは今月
        $this->get('/summary')->assertOk()
            ->assertSee('2026年10月')
            ->assertSee('食費')->assertSee('十月の店')->assertSee('1,200')
            ->assertDontSee('日用品')->assertDontSee('九月の店')
            ->assertSee('/summary?month=2026-09', false)
            ->assertSee('/summary?month=2026-11', false);

        $this->get('/summary?month=2026-09')->assertOk()
            ->assertSee('2026年9月')
            ->assertSee('日用品')->assertSee('九月の店')->assertSee('3,400')
            ->assertDontSee('十月の店');

        // 年をまたぐ切りかえ・形がちがうときは今月
        $this->get('/summary?month=2026-01')
            ->assertSee('/summary?month=2025-12', false)
            ->assertSee('/summary?month=2026-02', false);
        $this->get('/summary?month=2026-13')->assertOk()->assertSee('2026年10月');
    }

    public function test_収支には移動と調整を入れず_記録の一覧の月の合計と同じ金額になる(): void
    {
        $this->record(RecordType::Income, '2026-10-01', 250000, $this->parent('給与', CategoryKind::Income));
        $this->record(RecordType::Expense, '2026-10-02', 182400, $this->parent('住まい'));
        Record::factory()->create(['date' => '2026-10-03', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->ic->id]);
        Record::factory()->create(['date' => '2026-10-04', 'type' => RecordType::Adjustment, 'amount' => -500,
            'account_id' => $this->cash->id]);

        $this->get('/summary')->assertSeeInOrder(['収支', '収入', '250,000', '支出', '182,400', '差', '67,600']);
        $this->get('/records')->assertSeeInOrder(['収入', '250,000', '支出', '182,400', '差', '67,600']);
    }

    public function test_大分類の金額は小分類の合計と小分類なしの合計で_押すと小分類と小分類なしが開く(): void
    {
        $food = $this->parent('食費');
        $groceries = $this->child($food, '食料品');
        $eatingOut = $this->child($food, '外食');
        $this->record(RecordType::Expense, '2026-10-01', 29500, $groceries);
        $this->record(RecordType::Expense, '2026-10-02', 12800, $eatingOut);
        $this->record(RecordType::Expense, '2026-10-03', 1000, $food);

        $response = $this->get('/summary');

        // 食費 ＝ 29,500 ＋ 12,800 ＋ 1,000
        $response->assertSeeInOrder(['支出の分類', '食費', '43,300', '食料品', '29,500', '外食', '12,800', '小分類なし', '1,000']);
        // 小分類は最初は閉じていて、大分類の行で開く
        $response->assertSee('data-bs-target="#expense-'.$food->id.'"', false);
        $response->assertSee('class="collapse" id="expense-'.$food->id.'"', false);
    }

    public function test_小分類を使っていない大分類は開く印を出さない(): void
    {
        $home = $this->parent('住まい');
        $this->record(RecordType::Expense, '2026-10-01', 70000, $home);

        $this->get('/summary')->assertSee('住まい')->assertDontSee('data-bs-target="#expense-'.$home->id.'"', false);
    }

    public function test_分類は金額の多い順で_同じ金額なら分類の並び順_0円の分類は出ない(): void
    {
        $this->record(RecordType::Expense, '2026-10-01', 500, $this->parent('交通費'));
        $this->record(RecordType::Expense, '2026-10-01', 30000, $this->parent('ローン'));
        $this->record(RecordType::Expense, '2026-10-01', 500, $this->parent('食費'));

        $response = $this->get('/summary');

        // 食費と交通費は同じ 500 円 → 分類の管理の並び順（食費が先）
        $response->assertSeeInOrder(['ローン', '30,000', '食費', '500', '交通費', '500']);
        $response->assertDontSee('日用品');
        $response->assertDontSee('自動車');
    }

    public function test_収入の分類も大分類ごとに出る(): void
    {
        $this->record(RecordType::Income, '2026-10-25', 250000, $this->parent('給与', CategoryKind::Income));
        $this->record(RecordType::Income, '2026-10-10', 3000, $this->parent('臨時収入', CategoryKind::Income));

        $this->get('/summary')->assertSeeInOrder(['収入の分類', '給与', '250,000', '臨時収入', '3,000', '相手ごとの支出']);
    }

    public function test_使わないにした分類と相手も_その月に記録があれば元の名前で出る(): void
    {
        $category = $this->parent('趣味・娯楽');
        $partner = Partner::factory()->create(['name' => '閉店した店']);
        $this->record(RecordType::Expense, '2026-10-01', 800, $category, $partner);
        $category->update(['is_active' => false]);
        $partner->update(['is_active' => false]);

        $this->get('/summary')->assertSee('趣味・娯楽')->assertSee('閉店した店');
    }

    public function test_相手ごとの支出は多い順に5件出て_ほかの相手も見るで全部出る(): void
    {
        $food = $this->parent('食費');
        foreach ([700, 100, 600, 300, 500, 200, 400] as $i => $amount) {
            $this->record(RecordType::Expense, '2026-10-01', $amount, $food, Partner::factory()->create(['name' => '店'.($i + 1)]));
        }
        // 収入の相手は、支出の合計に入れない
        $this->record(RecordType::Income, '2026-10-01', 99999, $this->parent('給与', CategoryKind::Income), Partner::factory()->create(['name' => '会社']));

        $response = $this->get('/summary');
        $html = $response->getContent() ?: '';
        $partners = substr($html, (int) strpos($html, '相手ごとの支出'));
        $more = substr($partners, (int) strpos($partners, 'id="more-partners"'));

        $response->assertSeeInOrder(['相手ごとの支出', '店1', '店3', '店5', '店7', '店4', 'more-partners', '店6', '店2', 'ほかの相手も見る（2 件）']);
        $this->assertStringNotContainsString('会社', $partners);
        $this->assertStringNotContainsString('店4', $more, '5 件目までは「ほかの相手も見る」の中に入れない');
    }

    public function test_相手が5件以下ならほかの相手も見るは出ない(): void
    {
        $this->record(RecordType::Expense, '2026-10-01', 100, $this->parent('食費'));

        $this->get('/summary')->assertSee('コンビニ')->assertDontSee('ほかの相手も見る');
    }

    public function test_その月に記録がないときはこの月の記録はまだありませんと出る(): void
    {
        $this->get('/summary?month=2026-08')->assertOk()
            ->assertSee('この月の記録はまだありません')
            ->assertSee('/records/create?date=2026-08-01', false)
            ->assertDontSee('支出の分類');
    }

    public function test_移動だけの月は記録ありとして出し_分類と相手はありませんと出る(): void
    {
        Record::factory()->create(['date' => '2026-10-03', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->ic->id]);

        $this->get('/summary')
            ->assertDontSee('この月の記録はまだありません')
            ->assertSee('この月の支出はありません')
            ->assertSee('この月の収入はありません');
    }
}
