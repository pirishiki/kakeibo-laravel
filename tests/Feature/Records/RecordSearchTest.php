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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 記録の一覧の「探す」（No.18、画面設計書 5.3）のうち、URL の形がおかしいとき・欄に入れておく値など、画面のつくりを確かめる。
 * 見つかる範囲と合計の決まりは RecordSearchSpecTest（設計書だけから作ったテスト）で確かめる
 */
class RecordSearchTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Category $food;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash]);
        $this->food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        $this->shop = Partner::factory()->create(['name' => 'コンビニ']);
        Record::factory()->create([
            'date' => '2026-10-09', 'type' => RecordType::Expense, 'amount' => 680,
            'account_id' => $this->cash->id, 'category_id' => $this->food->id, 'partner_id' => $this->shop->id, 'item' => 'おにぎり',
        ]);
    }

    public function test_条件の欄が配列の形で来てもエラーの画面にならず知らせが出る(): void
    {
        $this->get('/records?item[]=a&account[]=1&from[]=x')
            ->assertOk()
            ->assertSee('条件の形がちがいます。選び直してください。')
            ->assertDontSee('おにぎり');
    }

    public function test_しるしだけで条件が空なら知らせを出す(): void
    {
        $this->get('/records?search=1&item=')
            ->assertOk()
            ->assertSee('探す条件を 1 つ以上入れてください。')
            ->assertDontSee('おにぎり');
    }

    public function test_小分類のidが数でなくてもエラーの画面にならない(): void
    {
        $this->get('/records?category='.$this->food->id.'&sub=abc')
            ->assertOk()
            ->assertSee('条件の形がちがいます。選び直してください。');
    }

    public function test_プラスのついたidは通さない(): void
    {
        // 通すと、探すときは 1 として探すのに、欄では「すべて」になり、「条件を変える」で条件が黙って外れるため
        foreach (['account' => $this->cash->id, 'partner' => $this->shop->id, 'category' => $this->food->id] as $key => $id) {
            $this->get("/records?{$key}=%2B{$id}")
                ->assertOk()
                ->assertSee('条件の形がちがいます。選び直してください。')
                ->assertDontSee('おにぎり');
        }
    }

    public function test_品物は濁点のちがう言葉を見つけず半角カナは見つける(): void
    {
        foreach (['ハンカチ' => 100, 'ﾊﾟﾝ粉' => 200, 'パンケーキ' => 300, 'cafe' => 400] as $item => $amount) {
            Record::factory()->create([
                'date' => '2026-10-10', 'type' => RecordType::Expense, 'amount' => $amount,
                'account_id' => $this->cash->id, 'category_id' => $this->food->id, 'item' => $item,
            ]);
        }

        $this->get('/records?item=パン')
            ->assertOk()
            ->assertSee('2 件')
            // 半角カナの品物は、全角カナにそろえて保存している
            ->assertSee('パン粉')
            ->assertSee('パンケーキ')
            ->assertDontSee('ハンカチ')
            ->assertSee('支出 <span class="">500</span>', false);

        $this->get('/records?item=café')->assertOk()->assertSee('0 件');
        $this->get('/records?item=CAFE')->assertOk()->assertSee('1 件');
    }

    public function test_品物は小さいカナと大きいカナを区別し全角と半角の英数字は同じにする(): void
    {
        foreach (['ショコラ' => 100, 'キャベツ' => 200, 'ＡＢＣ食品' => 300] as $item => $amount) {
            Record::factory()->create([
                'date' => '2026-10-10', 'type' => RecordType::Expense, 'amount' => $amount,
                'account_id' => $this->cash->id, 'category_id' => $this->food->id, 'item' => $item,
            ]);
        }

        $this->get('/records?item=ヨ')->assertOk()->assertSee('0 件');
        $this->get('/records?item=キヤ')->assertOk()->assertSee('0 件');
        $this->get('/records?item=きゃ')->assertOk()->assertSee('1 件')->assertSee('キャベツ');
        $this->get('/records?item=abc')->assertOk()->assertSee('1 件')->assertSee('ＡＢＣ食品');
    }

    public function test_半角カナの品物は全角にそろえて保存し混ざったものも見つかる(): void
    {
        $record = Record::factory()->create([
            'date' => '2026-10-10', 'type' => RecordType::Expense, 'amount' => 250,
            'account_id' => $this->cash->id, 'category_id' => $this->food->id, 'item' => 'ﾊﾟﾝケーキ',
        ]);

        $this->assertSame('パンケーキ', $record->refresh()->item);
        $this->get('/records?item=パンケ')->assertOk()->assertSee('1 件');
        // 半角カナで打って探しても見つかる
        $this->get('/records?item=ﾊﾟﾝｹ')->assertOk()->assertSee('1 件');
    }

    public function test_前に入れた半角カナの品物をマイグレーションでそろえる(): void
    {
        // モデルを通さずに入れて、前に入れた記録と同じ形にする
        $id = DB::table('records')->insertGetId([
            'date' => '2026-10-10', 'type' => 'expense', 'amount' => 100, 'account_id' => $this->cash->id,
            'category_id' => $this->food->id, 'item' => 'ｷｬﾍﾞﾂ半分', 'created_at' => '2026-10-10 21:00:00', 'updated_at' => '2026-10-10 21:00:00',
        ]);

        (require database_path('migrations/2026_10_16_000001_normalize_record_items.php'))->up();

        $row = DB::table('records')->find($id);
        $this->assertSame('キャベツ半分', $row->item);
        // 直したのは文字の形だけなので、日時は変えない
        $this->assertSame('2026-10-10 21:00:00', (string) $row->updated_at);
        $this->assertSame('おにぎり', Record::query()->where('item', 'おにぎり')->value('item'));
    }

    /** 2026-09-01 に 501 件足す（setUp の 10 月 9 日の 1 件とあわせて 502 件） */
    private function addManyRecords(): void
    {
        Record::factory()->count(501)->create([
            'date' => '2026-09-01', 'type' => RecordType::Expense, 'amount' => 10,
            'account_id' => $this->cash->id, 'category_id' => $this->food->id, 'partner_id' => $this->shop->id,
        ]);
    }

    /** 画面に出ている記録の行の数（行は記録の編集の画面へのリンク） */
    private function rowCount(TestResponse $response): int
    {
        return (int) preg_match_all('#/records/\d+/edit#', (string) $response->getContent());
    }

    /** 「続きを見る」の URL */
    private function nextUrl(TestResponse $response): string
    {
        preg_match('#<a href="([^"]+)" class="btn btn-outline-primary">\s*続きを見る#', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m, '「続きを見る」がない');

        return html_entity_decode($m[1]);
    }

    public function test_500件をこえると続きを見るで残りを出し合計は全部で数える(): void
    {
        $this->addManyRecords();

        $first = $this->get('/records?partner='.$this->shop->id)->assertOk();
        $first->assertSee('502 件')
            ->assertSee('502 件のうち、1〜500 件目を出しています（新しい順）。')
            // 合計はほかのページの記録もふくめる：680 + 10 × 501
            ->assertSee('支出 <span class="">5,690</span>', false)
            ->assertSee('おにぎり')
            ->assertSee('続きを見る（あと 2 件）')
            ->assertSee('（続きは次のページ）')
            ->assertDontSee('最初から見る');
        // 日が次のページにまたがっても、日付の見出しはその日の全部の支出
        $first->assertSee('支出 <span class="">5,010</span>', false);
        $this->assertSame(500, $this->rowCount($first));

        $second = $this->get($this->nextUrl($first))->assertOk();
        $second->assertSee('502 件のうち、501〜502 件目を出しています（新しい順）。')
            ->assertSee('支出 <span class="">5,690</span>', false)
            ->assertSee('（前のページから続く）')
            ->assertSee('最初から見る')
            ->assertDontSee('続きを見る')
            ->assertDontSee('おにぎり');
        $this->assertSame(2, $this->rowCount($second));
    }

    public function test_続きを開くまえに記録を消しても飛ばされる記録がない(): void
    {
        $this->addManyRecords();
        // 月ごとの一覧でも同じ（9 月は 501 件）
        $first = $this->get('/records?month=2026-09')->assertOk();
        $this->assertSame(500, $this->rowCount($first));
        $next = $this->nextUrl($first);

        // 1 ページ目に出ていた記録を、ほかのタブで 3 件消す
        Record::query()->where('date', '2026-09-01')->orderByDesc('id')->limit(3)->get()->each->delete();

        // 続きは「1 ページ目のいちばん下の記録より前」なので、残りの 1 件がそのまま出る（ページ番号で出すと、3 件飛ばされる）
        $second = $this->get($next)->assertOk();
        $this->assertSame(1, $this->rowCount($second));
        $second->assertSee('498 件のうち、498〜498 件目');
    }

    public function test_続きの印の形がちがうときは最初から出し続きがなくなったときは知らせる(): void
    {
        $this->get('/records?before=abc')->assertOk()->assertSee('おにぎり');
        $this->get('/records?before=2026-02-30.5')->assertOk()->assertSee('おにぎり');

        // 10 月 9 日の記録より前には記録がない
        $this->get('/records?before=2026-10-09.1')
            ->assertOk()
            ->assertSee('この続きの記録はありません')
            ->assertSee('最初から見る');
    }

    public function test_月ごとの一覧の続きと戻る先には月を入れる(): void
    {
        $this->addManyRecords();
        // 9 月の記録が 501 件あるときに、月を書かずに 9 月の一覧を開く（9 月に開いて、そのあいだに 10 月になったときと同じ）
        $this->travelTo(now()->setDate(2026, 9, 30)->setTime(23, 59));
        $first = $this->get('/records')->assertOk();
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(0, 1));

        $next = $this->nextUrl($first);
        $this->assertStringContainsString('month=2026-09', $next);
        $second = $this->get($next)->assertOk()->assertSee('501 件のうち、501〜501 件目');
        $this->assertSame(1, $this->rowCount($second));
        // 行の戻る先にも月が入る
        $second->assertSee(urlencode('month=2026-09'), false)->assertSee('最初から見る');
    }

    public function test_形のちがう続きの印では最初から見るを出さない(): void
    {
        $this->get('/records?before=abc')->assertOk()->assertDontSee('最初から見る');
        // 記録のない月は「この月の記録はまだありません」のまま
        $this->get('/records?month=2026-08&before=')->assertOk()->assertSee('この月の記録はまだありません')->assertDontSee('この続きの記録はありません');
    }

    public function test_品物の大文字小文字はデータベースと同じやり方でそろえる(): void
    {
        Record::factory()->create([
            'date' => '2026-10-10', 'type' => RecordType::Expense, 'amount' => 100,
            'account_id' => $this->cash->id, 'category_id' => $this->food->id, 'item' => 'ΟΔΟΣ',
        ]);

        $this->get('/records?item=ΟΔΟΣ')->assertOk()->assertSee('1 件');
        $this->get('/records?item=οδοσ')->assertOk()->assertSee('1 件');
    }

    public function test_続きのページがあるときはカレンダーの日から開き直すしるしをつける(): void
    {
        $this->addManyRecords();

        $this->get('/records?month=2026-09')->assertOk()->assertSee('data-records-more', false);
        $this->get('/records')->assertOk()->assertDontSee('data-records-more', false);
    }

    public function test_探した一覧の行は戻る先に同じ条件をつける(): void
    {
        $this->get('/records?item=おに')
            ->assertOk()
            ->assertSee('edit?back='.urlencode(url('/records').'?item='.rawurlencode('おに')), false);
    }

    public function test_関係ないクエリは行の戻る先にも続きの_ur_lにも入れない(): void
    {
        $long = str_repeat('x', 6000);

        $this->get('/records?item=おに&x='.$long)
            ->assertOk()
            ->assertSee('edit?back='.urlencode(url('/records').'?item='.rawurlencode('おに')).'"', false)
            ->assertDontSee($long);

        $this->addManyRecords();
        $this->get('/records?month=2026-09&x='.$long)
            ->assertOk()
            ->assertSee('edit?back='.urlencode(url('/records').'?month=2026-09').'"', false)
            ->assertSee('data-records-more', false)
            ->assertDontSee($long);
    }

    public function test_続きのページの行の戻る先には読めた続きの印だけを入れる(): void
    {
        $this->addManyRecords();
        $first = Record::query()->where('date', '2026-09-01')->orderByDesc('id')->firstOrFail();

        $this->get('/records?month=2026-09&before=2026-09-01.'.$first->id)
            ->assertOk()
            ->assertSee('edit?back='.urlencode(url('/records').'?month=2026-09&before=2026-09-01.'.$first->id).'"', false);

        // 形のちがう印は読まずに 1 ページ目を出すので、戻る先にも入れない
        $this->get('/records?month=2026-09&before='.str_repeat('x', 3000))
            ->assertOk()
            ->assertSee('edit?back='.urlencode(url('/records').'?month=2026-09').'"', false)
            ->assertDontSee(str_repeat('x', 3000));
    }

    public function test_小分類のidを大分類の欄に入れると知らせを出す(): void
    {
        $child = Category::factory()->create(['name' => '外食', 'kind' => CategoryKind::Expense, 'parent_id' => $this->food->id]);

        $this->get('/records?category='.$child->id)
            ->assertOk()
            ->assertSee('その大分類は見つかりません');
    }

    public function test_探した条件が欄に入っていて条件を変えられる(): void
    {
        $this->get('/records?partner='.$this->shop->id.'&item=おに')
            ->assertOk()
            ->assertSee('1 件')
            ->assertSee('value="'.$this->shop->id.'" selected', false)
            ->assertSee('value="おに"', false)
            ->assertSee('条件を変える')
            ->assertDontSee('aria-label="前の月"', false);
    }

    public function test_使わない口座と相手も選べる(): void
    {
        $this->cash->update(['is_active' => false]);
        $this->shop->update(['is_active' => false]);

        $this->get('/records?account='.$this->cash->id)
            ->assertOk()
            ->assertSee('財布（使わない）')
            ->assertSee('使わない相手')
            ->assertSee('おにぎり');
    }

    public function test_探していないときは月の切りかえと探すボタンが出る(): void
    {
        $this->get('/records')
            ->assertOk()
            ->assertSee('aria-label="前の月"', false)
            ->assertSee('data-bs-target="#search-panel"', false)
            ->assertDontSee('探した結果');
    }
}
