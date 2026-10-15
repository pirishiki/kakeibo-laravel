<?php

namespace Tests\Feature\Records;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Livewire\RecordForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 検索・絞り込み（要件定義書 No.18、画面設計書 5.3・5.5）を、設計書だけを見て確かめる。
 *
 * 見分けるときは、記録ごとにちがう「日付の見出し」（10月D日）を使う
 * （口座や相手の名前は、探す欄の選択肢にも出るため、見分けに使えない）。
 */
class RecordSearchSpecTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $bank;

    private Account $card;

    private Category $food;

    private Category $daily;

    private Category $salary;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash]);
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行', 'type' => AccountType::Bank]);
        $this->card = Account::factory()->create(['name' => 'ほかのカード', 'type' => AccountType::Bank]);
        $this->food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        $this->daily = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '日用品')->firstOrFail();
        $this->salary = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Income)->firstOrFail();
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function income(string $date, int $amount, array $attributes = []): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => RecordType::Income,
            'amount' => $amount,
            'account_id' => $this->cash->id,
            'category_id' => $this->salary->id,
            'partner_id' => $this->shop->id,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function search(array $params): TestResponse
    {
        return $this->get('/records?'.http_build_query($params));
    }

    public function test_空の欄はどれでもよく_入れた欄は全部かつでつながる(): void
    {
        $other = Partner::factory()->create(['name' => '別の店']);
        $this->expense('2026-10-02', 100, ['item' => 'りんご']);                              // 全部合う
        $this->expense('2026-10-03', 200, ['item' => 'りんご', 'partner_id' => $other->id]);   // 相手がちがう
        $this->expense('2026-10-04', 300, ['item' => 'みかん']);                              // 品物がちがう
        $this->expense('2026-10-05', 400, ['item' => 'りんご', 'category_id' => $this->daily->id]); // 分類がちがう
        $this->expense('2026-10-06', 500, ['item' => 'りんご', 'account_id' => $this->bank->id]);   // 口座がちがう

        // 空の欄（空文字）は「どれでもよい」
        $this->search(['from' => '', 'to' => '', 'type' => '', 'account' => '', 'category' => '', 'sub' => '', 'partner' => '', 'item' => 'りんご'])
            ->assertOk()->assertSee('10月2日')->assertSee('10月3日')->assertSee('10月5日')->assertSee('10月6日')->assertDontSee('10月4日');

        // 入れた欄は全部「かつ」
        $this->search([
            'from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'expense', 'account' => $this->cash->id,
            'category' => $this->food->id, 'partner' => $this->shop->id, 'item' => 'りんご',
        ])->assertOk()
            ->assertSee('10月2日')
            ->assertDontSee('10月3日')->assertDontSee('10月4日')->assertDontSee('10月5日')->assertDontSee('10月6日');
    }

    public function test_期間は始めの日だけでも_終わりの日だけでも_両方でも探せる(): void
    {
        $this->expense('2026-09-30', 100);
        $this->expense('2026-10-05', 200);
        $this->expense('2026-10-20', 300);
        $this->expense('2026-11-10', 400);

        // 始めの日だけ：その日以降。月はまたいでよい
        $this->search(['from' => '2026-10-05'])
            ->assertDontSee('9月30日')->assertSee('10月5日')->assertSee('10月20日')->assertSee('11月10日');

        // 終わりの日だけ：その日まで（その日をふくむ）
        $this->search(['to' => '2026-10-05'])
            ->assertSee('9月30日')->assertSee('10月5日')->assertDontSee('10月20日')->assertDontSee('11月10日');

        // 両方：両端の日をふくむ
        $this->search(['from' => '2026-10-05', 'to' => '2026-10-20'])
            ->assertDontSee('9月30日')->assertSee('10月5日')->assertSee('10月20日')->assertDontSee('11月10日');

        // 同じ日でもよい
        $this->search(['from' => '2026-10-20', 'to' => '2026-10-20'])
            ->assertDontSee('10月5日')->assertSee('10月20日');
    }

    public function test_終わりの日が始めの日より前なら_知らせが出て記録は出ない(): void
    {
        $this->expense('2026-10-15', 100);

        $this->search(['from' => '2026-10-20', 'to' => '2026-10-10'])
            ->assertOk()
            ->assertDontSee('10月15日')
            ->assertDontSee('1 件')
            ->assertSee('終わりの日');
    }

    public function test_ない日付なら_知らせが出て記録は出ない(): void
    {
        $this->expense('2026-10-15', 100);

        $this->search(['from' => '2026-02-30'])->assertOk()->assertDontSee('10月15日');
        $this->search(['to' => 'あした'])->assertOk()->assertDontSee('10月15日');
    }

    public function test_大分類で探すと小分類の記録もふくめて見つかる(): void
    {
        $out = Category::factory()->childOf($this->food)->create(['name' => '外食']);
        $this->expense('2026-10-02', 100);                                  // 大分類そのもの
        $this->expense('2026-10-03', 200, ['category_id' => $out->id]);     // 小分類
        $this->expense('2026-10-04', 300, ['category_id' => $this->daily->id]);

        $this->search(['category' => $this->food->id])
            ->assertSee('10月2日')->assertSee('10月3日')->assertDontSee('10月4日')
            ->assertSee('2 件')
            ->assertSeeInOrder(['支出', '300', '差'])
            ->assertSee('食費（小分類もふくむ）');
    }

    public function test_小分類なしは_大分類そのものの記録だけが見つかる(): void
    {
        $out = Category::factory()->childOf($this->food)->create(['name' => '外食']);
        $this->expense('2026-10-02', 100);
        $this->expense('2026-10-03', 200, ['category_id' => $out->id]);

        $this->search(['category' => $this->food->id, 'sub' => 'none'])
            ->assertSee('10月2日')->assertDontSee('10月3日')
            ->assertSee('1 件')
            ->assertSee('小分類なし');
    }

    public function test_小分類を選ぶと_その小分類の記録だけが見つかる(): void
    {
        $out = Category::factory()->childOf($this->food)->create(['name' => '外食']);
        $groceries = Category::factory()->childOf($this->food)->create(['name' => '食料品']);
        $this->expense('2026-10-02', 100);
        $this->expense('2026-10-03', 200, ['category_id' => $out->id]);
        $this->expense('2026-10-04', 300, ['category_id' => $groceries->id]);

        $this->search(['category' => $this->food->id, 'sub' => $out->id])
            ->assertDontSee('10月2日')->assertSee('10月3日')->assertDontSee('10月4日')
            ->assertSee('食費 > 外食');
    }

    public function test_ほかの大分類の小分類を指定すると_知らせが出て記録は出ない(): void
    {
        $dailySub = Category::factory()->childOf($this->daily)->create(['name' => '洗剤']);
        $this->expense('2026-10-02', 100);
        $this->expense('2026-10-03', 200, ['category_id' => $dailySub->id]);

        $this->search(['category' => $this->food->id, 'sub' => $dailySub->id])
            ->assertOk()->assertDontSee('10月2日')->assertDontSee('10月3日');
    }

    public function test_ない分類を指定すると_記録は出ない(): void
    {
        $this->expense('2026-10-02', 100);

        $this->search(['category' => 99999])->assertOk()->assertDontSee('10月2日');
    }

    public function test_口座で探すと_出た記録と移動で入った記録と調整が見つかる(): void
    {
        $this->expense('2026-10-02', 100);                                                         // 財布から出た
        $this->income('2026-10-03', 5000);                                                         // 財布に入った（収入）
        Record::factory()->create(['date' => '2026-10-04', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->bank->id, 'to_account_id' => $this->cash->id]);                  // 財布へ移動
        Record::factory()->create(['date' => '2026-10-05', 'type' => RecordType::Transfer, 'amount' => 2000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->bank->id]);                  // 財布から移動
        Record::factory()->create(['date' => '2026-10-06', 'type' => RecordType::Adjustment, 'amount' => -400,
            'account_id' => $this->cash->id]);                                                      // 財布の調整
        $this->expense('2026-10-07', 9000, ['account_id' => $this->bank->id]);                      // 銀行の支出
        Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Transfer, 'amount' => 700,
            'account_id' => $this->bank->id, 'to_account_id' => $this->card->id]);                  // 財布と関係ない移動
        Record::factory()->create(['date' => '2026-10-09', 'type' => RecordType::Adjustment, 'amount' => 800,
            'account_id' => $this->bank->id]);                                                      // 銀行の調整

        $this->search(['account' => $this->cash->id])->assertOk()
            ->assertSee('10月2日')->assertSee('10月3日')->assertSee('10月4日')->assertSee('10月5日')->assertSee('10月6日')
            ->assertDontSee('10月7日')->assertDontSee('10月8日')->assertDontSee('10月9日')
            ->assertSee('5 件');

        // 銀行：出た・入った・調整
        $this->search(['account' => $this->bank->id])->assertOk()
            ->assertSee('10月4日')->assertSee('10月5日')->assertSee('10月7日')->assertSee('10月8日')->assertSee('10月9日')
            ->assertDontSee('10月2日')->assertDontSee('10月3日')->assertDontSee('10月6日')
            ->assertSee('5 件');
    }

    public function test_相手で探すと_支出も収入も見つかり_種類で絞れる(): void
    {
        $other = Partner::factory()->create(['name' => '別の店']);
        $this->expense('2026-10-02', 100);
        $this->income('2026-10-03', 5000);
        $this->expense('2026-10-04', 300, ['partner_id' => $other->id]);

        $this->search(['partner' => $this->shop->id])
            ->assertSee('10月2日')->assertSee('10月3日')->assertDontSee('10月4日')
            ->assertSee('2 件');

        $this->search(['partner' => $this->shop->id, 'type' => 'expense'])
            ->assertSee('10月2日')->assertDontSee('10月3日')->assertDontSee('10月4日')
            ->assertSee('1 件');

        $this->search(['partner' => $this->shop->id, 'type' => 'income'])
            ->assertDontSee('10月2日')->assertSee('10月3日')
            ->assertSee('1 件');

        // 相手を決めず種類だけ
        $this->search(['type' => 'expense'])
            ->assertSee('10月2日')->assertDontSee('10月3日')->assertSee('10月4日');
    }

    public function test_種類で絞ると_移動と調整は出ない(): void
    {
        $this->expense('2026-10-02', 100);
        Record::factory()->create(['date' => '2026-10-03', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->bank->id]);
        Record::factory()->create(['date' => '2026-10-04', 'type' => RecordType::Adjustment, 'amount' => -400,
            'account_id' => $this->cash->id]);

        $this->search(['type' => 'expense'])
            ->assertSee('10月2日')->assertDontSee('10月3日')->assertDontSee('10月4日');
    }

    public function test_品物は言葉の一部で見つかる(): void
    {
        $this->expense('2026-10-02', 100, ['item' => 'おにぎり弁当']);
        $this->expense('2026-10-03', 200, ['item' => 'パン']);

        $this->search(['item' => 'にぎり'])
            ->assertSee('10月2日')->assertDontSee('10月3日')
            ->assertSee('1 件');
        $this->search(['item' => 'おにぎり弁当'])->assertSee('10月2日');
    }

    public function test_メモは探さない(): void
    {
        $this->expense('2026-10-02', 100, ['item' => 'ふつうの品', 'memo' => 'ひみつの言葉']);

        $this->search(['item' => 'ひみつ'])
            ->assertOk()
            ->assertDontSee('10月2日')
            ->assertSee('条件に合う記録はありません');
    }

    public function test_品物のパーセントはふつうの文字として探す(): void
    {
        $this->expense('2026-10-02', 100, ['item' => '100%オフ']);
        $this->expense('2026-10-03', 200, ['item' => '1000円']);

        // LIKE の % として読むと「1000円」も見つかってしまう
        $this->search(['item' => '100%'])
            ->assertSee('10月2日')->assertDontSee('10月3日')
            ->assertSee('1 件');
        // % だけで探すと、% をふくむものだけ
        $this->search(['item' => '%'])
            ->assertSee('10月2日')->assertDontSee('10月3日');
    }

    public function test_品物のアンダースコアはふつうの文字として探す(): void
    {
        $this->expense('2026-10-02', 100, ['item' => 'a_b']);
        $this->expense('2026-10-03', 200, ['item' => 'axb']);

        $this->search(['item' => 'a_b'])
            ->assertSee('10月2日')->assertDontSee('10月3日')
            ->assertSee('1 件');
        $this->search(['item' => '_'])
            ->assertSee('10月2日')->assertDontSee('10月3日');
    }

    public function test_品物の円記号もふつうの文字として探す(): void
    {
        $this->expense('2026-10-02', 100, ['item' => 'a\\b']);
        $this->expense('2026-10-03', 200, ['item' => 'ab']);

        $this->search(['item' => 'a\\b'])
            ->assertSee('10月2日')->assertDontSee('10月3日');
    }

    public function test_品物は255文字まで(): void
    {
        $this->expense('2026-10-02', 100, ['item' => 'あ']);

        $this->search(['item' => str_repeat('あ', 255)])->assertOk()->assertDontSee('エラー');
        $this->search(['item' => str_repeat('あ', 256)])->assertOk()->assertDontSee('10月2日');
    }

    public function test_ない相手や口座を指定すると_知らせが出て記録は出ない(): void
    {
        $this->expense('2026-10-02', 100);

        $this->search(['partner' => 99999])->assertOk()->assertDontSee('10月2日');
        $this->search(['account' => 99999])->assertOk()->assertDontSee('10月2日');
        // ほかの欄が合っていても、まちがった条件は黙って外さない
        $this->search(['partner' => 99999, 'item' => ''])->assertDontSee('10月2日');
        $this->search(['partner' => $this->shop->id, 'account' => 99999])->assertDontSee('10月2日');
    }

    public function test_合計と件数は見つかった記録だけで計算し_移動と調整は収入にも支出にも入れない(): void
    {
        $this->expense('2026-10-02', 100);
        $this->expense('2026-10-03', 200);
        $this->income('2026-10-04', 5000);
        Record::factory()->create(['date' => '2026-10-05', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $this->bank->id]);
        Record::factory()->create(['date' => '2026-10-06', 'type' => RecordType::Adjustment, 'amount' => -400,
            'account_id' => $this->cash->id]);
        $this->expense('2026-10-07', 9000, ['account_id' => $this->bank->id]);
        $this->income('2026-10-08', 77000, ['account_id' => $this->bank->id]);

        $this->search(['account' => $this->cash->id])->assertOk()
            ->assertSeeInOrder(['収入', '5,000', '支出', '300', '差', '4,700'])
            ->assertSee('5 件')
            ->assertDontSee('9,000')->assertDontSee('77,000')->assertDontSee('82,000');
    }

    public function test_全部空のまま探すと_条件を入れる知らせが出る(): void
    {
        $this->expense('2026-10-02', 100);

        $this->get('/records?search=1')->assertOk()->assertSee('探す条件を 1 つ以上入れてください');
        $this->get('/records?search=1&from=&to=&type=&account=&category=&sub=&partner=&item=')
            ->assertOk()->assertSee('探す条件を 1 つ以上入れてください');
    }

    public function test_条件に合う記録がないときは_そう出る(): void
    {
        $this->expense('2026-10-02', 100, ['item' => 'りんご']);

        $this->search(['item' => 'ぞうさん'])->assertOk()->assertSee('条件に合う記録はありません')->assertSee('0 件');
    }

    public function test_探しているあいだは_条件と件数と条件を消すが出て_月の切りかえは出ない(): void
    {
        Category::factory()->childOf($this->food)->create(['name' => '外食']);
        $this->expense('2026-10-02', 100);

        $this->search(['from' => '2026-10-01', 'to' => '2026-10-31', 'category' => $this->food->id, 'sub' => 'none'])
            ->assertOk()
            ->assertSee('探した結果')
            ->assertSeeInOrder(['期間', '2026年10月1日〜2026年10月31日', '分類', '食費 > 小分類なし'])
            ->assertSee('1 件')
            ->assertSee('条件を変える')
            ->assertSee('条件を消す')
            ->assertDontSee('/records?month=2026-09', false)
            ->assertDontSee('/records?month=2026-11', false);
    }

    public function test_条件を消すは_今月の一覧へのリンク(): void
    {
        $this->expense('2026-10-02', 100);

        $this->search(['item' => 'a'])
            ->assertSee('条件を消す')
            ->assertSee('href="'.route('records.index').'"', false);

        // そこへ行くと今月の一覧に戻る
        $this->get(route('records.index'))->assertOk()->assertSee('2026年10月')->assertDontSee('探した結果');
    }

    public function test_同じ条件の_ur_lは読みこみ直しても同じ結果になる(): void
    {
        $this->expense('2026-10-02', 100, ['item' => 'りんご']);
        $this->expense('2026-10-03', 200, ['item' => 'みかん']);
        $params = ['item' => 'りんご', 'category' => $this->food->id];

        $first = $this->search($params)->assertSee('10月2日')->assertDontSee('10月3日');
        $second = $this->search($params)->assertSee('10月2日')->assertDontSee('10月3日');
        $this->assertSame(substr_count($first->getContent(), '10月2日'), substr_count($second->getContent(), '10月2日'));
    }

    /**
     * 月のまとめの「›」で開いた検索結果の支出の合計が、月のまとめの金額と同じになる
     */
    public function test_月のまとめの矢印から開いた検索は_合計が月のまとめの金額と同じになる(): void
    {
        $out = Category::factory()->childOf($this->food)->create(['name' => '外食']);
        $groceries = Category::factory()->childOf($this->food)->create(['name' => '食料品']);
        $transport = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '交通費')->firstOrFail();
        $pShop = Partner::factory()->create(['name' => 'ピー店']);

        $this->expense('2026-09-03', 1200, ['category_id' => $out->id]);
        $this->expense('2026-09-04', 300, ['category_id' => $groceries->id]);
        $this->expense('2026-09-05', 500);                                                   // 食費（小分類なし）
        $this->expense('2026-09-06', 700, ['category_id' => $this->daily->id]);              // 小分類のない大分類
        $this->expense('2026-09-07', 400, ['category_id' => $transport->id, 'partner_id' => $pShop->id]);
        $this->expense('2026-09-08', 250, ['category_id' => $transport->id, 'partner_id' => $pShop->id]);
        $this->income('2026-09-09', 99999, ['partner_id' => $pShop->id]);                    // 同じ相手の収入（相手の支出には入らない）
        // ほかの月（入ってはいけない）
        $this->expense('2026-10-03', 11111, ['category_id' => $out->id]);
        $this->expense('2026-08-31', 22222, ['category_id' => $this->daily->id, 'partner_id' => $pShop->id]);

        $summary = $this->get('/summary?month=2026-09')->assertOk();
        foreach (['1,200', '300', '500', '700', '650'] as $amount) {
            $summary->assertSee($amount);
        }

        preg_match_all('/href="([^"]*\/records\?[^"]*)"/', $summary->getContent(), $m);
        $links = [];
        foreach (array_unique($m[1]) as $href) {
            parse_str((string) parse_url(html_entity_decode($href), PHP_URL_QUERY), $q);
            $links[] = ['href' => html_entity_decode($href), 'q' => $q];
        }
        $this->assertNotEmpty($links, '月のまとめに、記録の一覧への「›」のリンクがない');

        $find = function (callable $match) use ($links): string {
            foreach ($links as $link) {
                if ($match($link['q'])) {
                    return $link['href'];
                }
            }
            $this->fail('条件に合う「›」のリンクが月のまとめにない：'.json_encode(array_column($links, 'href'), JSON_UNESCAPED_UNICODE));
        };

        $check = function (string $href, int $expense, string $day, array $absentDays) {
            $res = $this->get($href)->assertOk()
                ->assertSee($day)
                ->assertSeeInOrder(['支出', number_format($expense), '差'])
                ->assertDontSee('11,111')->assertDontSee('22,222')->assertDontSee('99,999');
            foreach ($absentDays as $d) {
                $res->assertDontSee($d);
            }
        };

        // 小分類（外食）
        $subHref = $find(fn ($q) => ($q['sub'] ?? null) == $out->id && ($q['category'] ?? null) == $this->food->id);
        $check($subHref, 1200, '9月3日', ['9月4日', '9月5日', '9月6日']);

        // 小分類（食料品）
        $sub2Href = $find(fn ($q) => ($q['sub'] ?? null) == $groceries->id);
        $check($sub2Href, 300, '9月4日', ['9月3日', '9月5日', '9月6日']);

        // 小分類なし
        $noneHref = $find(fn ($q) => ($q['sub'] ?? null) === 'none' && ($q['category'] ?? null) == $this->food->id);
        $check($noneHref, 500, '9月5日', ['9月3日', '9月4日', '9月6日']);

        // 小分類のない大分類
        $parentHref = $find(fn ($q) => ($q['category'] ?? null) == $this->daily->id && ! isset($q['partner']) && ($q['sub'] ?? '') === '');
        $check($parentHref, 700, '9月6日', ['9月3日', '9月5日', '9月7日']);

        // 相手の行：支出だけ（同じ相手の収入 99,999 は入らない）
        $partnerHref = $find(fn ($q) => ($q['partner'] ?? null) == $pShop->id);
        $this->assertSame('expense', (string) (function () use ($partnerHref) {
            parse_str((string) parse_url($partnerHref, PHP_URL_QUERY), $q);

            return $q['type'] ?? '';
        })(), '相手の行から開くときは「支出だけ」の条件がつく');
        $check($partnerHref, 650, '9月7日', ['9月3日', '9月9日']);
        $this->get($partnerHref)->assertSee('9月8日')->assertSee('支出');

        // どのリンクも、その月（9 月）の範囲
        foreach ($links as $link) {
            if (isset($link['q']['from'])) {
                $this->assertSame('2026-09-01', $link['q']['from']);
                $this->assertSame('2026-09-30', $link['q']['to']);
            }
        }
    }

    public function test_探した一覧から開いた記録を保存すると_同じ条件の一覧に戻る(): void
    {
        $record = $this->expense('2026-10-09', 680, ['item' => 'おにぎり']);
        $url = url('/records?from=2026-10-01&to=2026-10-31&category='.$this->food->id.'&sub=none');
        session()->setPreviousUrl($url);

        Livewire::test(RecordForm::class, ['record' => $record])
            ->set('amount', '700')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect($url);

        $this->assertSame(700, $record->refresh()->amount);
    }

    public function test_探した一覧から開いた記録を削除すると_同じ条件の一覧に戻る(): void
    {
        $record = $this->expense('2026-10-09', 680, ['item' => 'おにぎり']);
        $url = url('/records?item='.urlencode('おにぎり').'&partner='.$this->shop->id);
        session()->setPreviousUrl($url);

        Livewire::test(RecordForm::class, ['record' => $record])
            ->call('delete')
            ->assertRedirect($url);

        $this->assertNull(Record::query()->find($record->id));
    }

    public function test_探した一覧から開いた記録のやめるは_同じ条件の一覧へのリンク(): void
    {
        $record = $this->expense('2026-10-09', 680);
        $url = url('/records?from=2026-10-01&to=2026-10-31&type=expense');
        session()->setPreviousUrl($url);

        Livewire::test(RecordForm::class, ['record' => $record])
            ->assertSee('やめる')
            ->assertSee(e($url), false);
    }

    public function test_探した一覧の行には_編集の画面へのリンクがある(): void
    {
        $record = $this->expense('2026-10-09', 680, ['item' => 'おにぎり']);

        $this->search(['item' => 'おにぎり'])->assertSee(route('records.edit', $record));
    }
}
