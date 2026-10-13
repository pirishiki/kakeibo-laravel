<?php

namespace Tests\Feature\Records;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 要件定義書 10.1 の記録の一覧（No.5、画面設計書 5.3）の項目を確かめる
 */
class RecordListTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $ic;

    private Category $food;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash]);
        $this->ic = Account::factory()->create(['name' => 'Suica', 'type' => AccountType::TransitIc]);
        $this->food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        $this->shop = Partner::factory()->create(['name' => 'コンビニ']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function expense(string $date, int $amount, array $attributes = []): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => RecordType::Expense,
            'amount' => $amount,
            'account_id' => $this->cash->id,
            'category_id' => $this->food->id,
            'partner_id' => $this->shop->id,
            ...$attributes,
        ]);
    }

    public function test_ログインしないと一覧は開けない(): void
    {
        auth()->logout();

        $this->get('/records')->assertRedirect('/login');
    }

    public function test_1か月ずつ出て_前の月と次の月に切りかわる(): void
    {
        $this->expense('2026-10-01', 100, ['item' => '十月の品物']);
        $this->expense('2026-09-30', 200, ['item' => '九月の品物']);

        // 何も書かないときは今月
        $this->get('/records')->assertOk()
            ->assertSee('2026年10月')
            ->assertSee('十月の品物')->assertDontSee('九月の品物')
            ->assertSee('/records?month=2026-09', false)
            ->assertSee('/records?month=2026-11', false);

        $this->get('/records?month=2026-09')->assertOk()
            ->assertSee('2026年9月')
            ->assertSee('九月の品物')->assertDontSee('十月の品物');

        // 年をまたぐ切りかえ
        $this->get('/records?month=2026-01')
            ->assertSee('/records?month=2025-12', false)
            ->assertSee('/records?month=2026-02', false);
    }

    public function test_月の形がちがうときは今月を出す(): void
    {
        foreach (['2026-13', 'abc', '2026-9', ''] as $value) {
            $this->get('/records?month='.$value)->assertOk()->assertSee('2026年10月');
        }
    }

    public function test_新しい日が上で_同じ日の中はあとで入力したものが上(): void
    {
        $this->expense('2026-10-01', 100, ['item' => '一日の品物']);
        $this->expense('2026-10-09', 100, ['item' => '九日の先']);
        $this->expense('2026-10-09', 100, ['item' => '九日のあと']);

        $this->get('/records')->assertSeeInOrder(['10月9日（金）', '九日のあと', '九日の先', '10月1日（木）', '一日の品物']);
    }

    public function test_日付の見出しにはその日の支出の合計だけが出て_記録のない日は出ない(): void
    {
        $this->expense('2026-10-08', 2350);
        $this->expense('2026-10-08', 1000);
        Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->ic->id]);
        Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Adjustment, 'amount' => -200,
            'account_id' => $this->cash->id]);

        $response = $this->get('/records');

        $response->assertSeeInOrder(['10月8日（木）', '支出', '3,350']);
        $response->assertDontSee('10月7日');
        $this->assertSame(1, substr_count($response->getContent() ?: '', 'card-header'), '日付の見出しは記録のある 1 日だけ');
    }

    public function test_種類ごとの出し方(): void
    {
        $sub = Category::factory()->childOf($this->food)->create(['name' => '外食']);
        $this->expense('2026-10-09', 680, ['category_id' => $sub->id, 'item' => 'おにぎり']);
        Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Income, 'amount' => 250000,
            'account_id' => $this->cash->id,
            'category_id' => Category::query()->where('kind', CategoryKind::Income)->where('name', '給与')->firstOrFail()->id,
            'partner_id' => Partner::factory()->create(['name' => '会社'])->id]);
        Record::factory()->create(['date' => '2026-10-07', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->ic->id]);
        Record::factory()->create(['date' => '2026-10-06', 'type' => RecordType::Adjustment, 'amount' => 300,
            'account_id' => $this->cash->id]);
        Record::factory()->create(['date' => '2026-10-05', 'type' => RecordType::Adjustment, 'amount' => -200,
            'account_id' => $this->ic->id]);

        $this->get('/records')
            // 支出：相手、分類（大分類 > 小分類）· 口座 · 品物
            ->assertSeeInOrder(['コンビニ', '680', '食費 &gt; 外食 · 財布 · おにぎり'], false)
            // 収入：緑の太い字で「+」
            ->assertSeeInOrder(['会社', 'fw-bold text-success">+', '250,000', '給与 · 財布'], false)
            // 移動：灰色で「どこから → どこへ」
            ->assertSeeInOrder(['list-group-item text-secondary', '財布 → Suica', '3,000'], false)
            // 調整：「調整」と口座。増えたら「+」、減ったら赤い字の「-」
            ->assertSeeInOrder(['調整', '+<span', '300', '財布'], false)
            ->assertSeeInOrder(['調整', 'text-danger">-200', 'Suica'], false);
    }

    public function test_今年でない日付の見出しには年がつく(): void
    {
        $this->expense('2025-12-31', 100);

        $this->get('/records?month=2025-12')->assertSee('2025年12月31日（水）');
    }

    public function test_日本時間の0時をすぎたら次の月になる(): void
    {
        // 日本時間 11月1日 0時30分（世界の時刻ではまだ 10月31日）
        $this->travelTo(now('Asia/Tokyo')->setDate(2026, 11, 1)->setTime(0, 30));

        $this->get('/records')->assertSee('2026年11月');
    }

    public function test_記録が増えてもデータベースへ行く回数は変わらない(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/records')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $sub = Category::factory()->childOf($this->food)->create();
        $this->expense('2026-10-01', 100, ['category_id' => $sub->id]);
        $one = $count();

        // 口座・分類・相手がそれぞれちがう記録を足す
        foreach (range(1, 5) as $i) {
            $this->expense('2026-10-0'.($i + 1), 100, [
                'account_id' => Account::factory()->create()->id,
                'category_id' => Category::factory()->childOf($this->food)->create()->id,
                'partner_id' => Partner::factory()->create()->id,
            ]);
        }

        $this->assertSame($one, $count(), '口座・分類・相手は with() でまとめて読むので、1 件ずつ読みに行かない');
    }

    public function test_月の合計に移動と調整は入らない(): void
    {
        $this->expense('2026-10-09', 48600);
        Record::factory()->create(['date' => '2026-10-01', 'type' => RecordType::Income, 'amount' => 50000,
            'account_id' => $this->cash->id]);
        Record::factory()->create(['date' => '2026-10-02', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->ic->id]);
        Record::factory()->create(['date' => '2026-10-03', 'type' => RecordType::Adjustment, 'amount' => -999,
            'account_id' => $this->cash->id]);
        // ほかの月の記録も入らない
        $this->expense('2026-09-30', 7777);

        $this->get('/records')->assertSeeInOrder(['収入', '50,000', '支出', '48,600', '差', '1,400']);
    }

    public function test_使わないにした口座_分類_相手も元の名前のまま出る(): void
    {
        $this->expense('2026-10-09', 680);
        $this->cash->update(['is_active' => false]);
        $this->food->update(['is_active' => false]);
        $this->shop->update(['is_active' => false]);

        $this->get('/records')->assertSeeInOrder(['コンビニ', '680', '食費 · 財布']);
    }

    public function test_記録がない月は_まだありませんと記録するボタンが出る(): void
    {
        $this->get('/records?month=2026-08')
            ->assertSee('この月の記録はまだありません')
            ->assertSee(route('records.create'));
    }

    public function test_ホームから一覧へ行ける(): void
    {
        $this->get('/')->assertSee(route('records.index'));
    }
}
