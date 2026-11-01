<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use App\Support\AvailableAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 設計書の決まりだけを根拠にした、6 つの場面のテスト（実装は見ずに書いた）。
 * 落ちるものがあれば、それは実装が設計書とちがうしるし。
 */
class DesignGapSpecTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 秒の端数（マイクロ秒）まで 0 にそろえて、時刻が確実に「同じ秒」になるようにする
        $this->travelTo(CarbonImmutable::create(2026, 10, 24, 12, 0, 0));
    }

    private ?Category $foodCategory = null;

    /** 同じ名前の分類は 2 つ作れないので、1 つ作ったら使い回す */
    private function food(): Category
    {
        return $this->foodCategory ??= Category::query()->create(['name' => '食費のテスト', 'kind' => CategoryKind::Expense]);
    }

    // ------------------------------------------------------------------
    // 場面 1：同じ秒の上書き
    // 根拠：画面設計書.md 5.4・5.10 の「開いたあとにほかの画面で直された記録は、上書きしない」、5.13 の「古い画面・2 回押し」
    //   （開いたあとにほかの画面で直されたものは、上書きせずに知らせる。
    //    「まれでも、ほかの画面の直しを上書きしないように」）。
    //   秒が同じかどうかで扱いを変えてよいとは、どこにも書かれていない。
    //   記録・定期的な記録の文言と欄の名前は設計書にないので、エラーが出ること（どの欄でも）で確かめる
    // ------------------------------------------------------------------

    public function test_同じ秒のうちにほかの画面で直された記録を古い画面で保存しても_上書きしない(): void
    {
        $record = Record::factory()->create(['type' => RecordType::Transfer, 'amount' => 1000, 'to_account_id' => Account::factory()->create()->id]);

        // 画面を開いた（12:00:00）。同じ 12:00:00 のうちに、別の画面で 1,500 円に直して保存した
        $old = Livewire::test(RecordForm::class, ['record' => $record]);
        Livewire::test(RecordForm::class, ['record' => $record->fresh()])->set('amount', '1,500')->call('save')->assertHasNoErrors();
        $this->assertSame(1500, $record->fresh()?->amount);

        // 時刻は進めない（同じ秒のまま）。古い画面から保存しても、別の画面の直しを上書きしない
        $old->set('amount', '900')->call('save')->assertHasErrors();
        $this->assertSame(1500, $record->fresh()?->amount);
    }

    public function test_同じ秒のうちにほかの画面で直された定期的な記録を古い画面で保存しても_上書きしない(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-04-01']);

        $old = Livewire::test(RecurringForm::class, ['recurring' => $rent]);
        Livewire::test(RecurringForm::class, ['recurring' => $rent->fresh()])->set('name', '家賃（別の画面）')->call('save')->assertHasNoErrors();
        $this->assertSame('家賃（別の画面）', $rent->fresh()?->name);

        $old->set('name', '家賃（古い画面）')->call('save')->assertHasErrors();
        $this->assertSame('家賃（別の画面）', $rent->fresh()?->name);
    }

    // ------------------------------------------------------------------
    // 場面 2：品物「0」の検索
    // 根拠：画面設計書.md 5.3 の「品物：打った言葉をふくむもの（一部が合えばよい）」
    //   「探しているときは…合計も見つかった記録だけで計算する」
    // ------------------------------------------------------------------

    public function test_品物に0だけを入れて探すと_0をふくむ記録だけに絞られ_合計もその分だけになる(): void
    {
        $cash = Account::factory()->create(['type' => AccountType::Cash]);
        $food = $this->food();
        $make = fn (string $date, int $amount, string $item) => Record::factory()->create([
            'date' => $date, 'type' => RecordType::Expense, 'amount' => $amount,
            'account_id' => $cash->id, 'category_id' => $food->id, 'item' => $item,
        ]);
        $make('2026-10-02', 111, '牛乳10本');   // 0 をふくむ
        $make('2026-10-03', 222, 'みかん');      // ふくまない
        $make('2026-10-04', 444, '100%オフ');    // 0 をふくむ
        $make('2026-10-05', 888, 'パン');        // ふくまない

        // 見分けは日付の見出し（10月D日）で行う
        $this->get('/records?'.http_build_query(['item' => '0']))->assertOk()
            ->assertSee('10月2日')->assertSee('10月4日')
            ->assertDontSee('10月3日')->assertDontSee('10月5日')
            ->assertSee('2 件')
            // 支出の合計は 111 + 444 = 555。全件の合計（1,665）や、ふくまない記録の金額は出ない
            ->assertSee('555')->assertDontSee('1,665')->assertDontSee('222')->assertDontSee('888');
    }

    // ------------------------------------------------------------------
    // 場面 3：選んだ相手が保存までに使えなくなったとき
    // 根拠：画面設計書.md 1 章 No.13 の最後（選んだ相手が保存の途中で削除されていたとき）、5.4 の相手の決まり
    //   「選んだ口座・分類・相手（まとめ先もふくむ）が保存の途中で削除されていたときは、同じ画面で選び直してもらう」
    //   「何も保存していないのに『保存しました』と出さないため」。
    //   「まとめられた」場合の決まりは設計書にはっきり書かれていない（削除と同じ「もう選べない」と読んだ）。
    //   エラーの欄の名前と文言も書かれていないので、どの欄でもエラーが出ること、保存されないことで確かめる
    // ------------------------------------------------------------------

    /**
     * @return array{Partner, Partner, Partner, Record} A（もとの相手）・X（選んだ相手）・Y（まとめ先）・A の記録
     */
    private function recordWithPartners(): array
    {
        $a = Partner::factory()->create(['name' => '相手A']);
        $x = Partner::factory()->create(['name' => '相手X']);
        $y = Partner::factory()->create(['name' => '相手Y']);
        $record = Record::factory()->create(['type' => RecordType::Expense, 'amount' => 500, 'category_id' => $this->food()->id, 'partner_id' => $a->id]);

        return [$a, $x, $y, $record];
    }

    public function test_記録の編集で選んだ相手が_保存までにほかの相手にまとめられたら_保存しない(): void
    {
        [$a, $x, $y, $record] = $this->recordWithPartners();

        $form = Livewire::test(RecordForm::class, ['record' => $record])
            ->set('partnerName', '相手X')->call('selectPartner', $x->id);
        $this->post(route('partners.merge', $x), ['merge_target' => '相手Y'])->assertRedirect(route('partners.index'));

        // 選んだ X は消えた。黙って捨てて元の A のまま保存してはいけない
        $form->set('amount', '700')->call('save')->assertHasErrors()->assertNoRedirect();
        $this->assertSame(500, $record->fresh()?->amount);
        $this->assertSame($a->id, $record->fresh()?->partner_id);
        $this->assertFalse(Partner::query()->whereKey($x->id)->exists());
        $this->assertTrue(Partner::query()->whereKey($y->id)->exists());
    }

    public function test_記録の編集で選んだ相手が_保存までに削除されたら_保存しない(): void
    {
        [$a, $x, , $record] = $this->recordWithPartners();

        $form = Livewire::test(RecordForm::class, ['record' => $record])
            ->set('partnerName', '相手X')->call('selectPartner', $x->id);
        Partner::query()->whereKey($x->id)->delete();

        $form->set('amount', '700')->call('save')->assertHasErrors()->assertNoRedirect();
        $this->assertSame(500, $record->fresh()?->amount);
        $this->assertSame($a->id, $record->fresh()?->partner_id);
    }

    public function test_記録の編集で_もとの相手_aがまとめられて消えても_まとめ先の相手で保存できる(): void
    {
        [$a, , $y, $record] = $this->recordWithPartners();

        // 相手の欄は触らず（A のまま）開く。そのあと A が Y にまとめられた
        $form = Livewire::test(RecordForm::class, ['record' => $record]);
        $this->post(route('partners.merge', $a), ['merge_target' => '相手Y'])->assertRedirect(route('partners.index'));

        $form->set('amount', '700')->call('save')->assertHasNoErrors();
        $this->assertSame(700, $record->fresh()?->amount);
        $this->assertSame($y->id, $record->fresh()?->partner_id);
    }

    /**
     * @return array{Partner, Partner, Partner, Recurring}
     */
    private function recurringWithPartners(): array
    {
        $a = Partner::factory()->create(['name' => '相手A']);
        $x = Partner::factory()->create(['name' => '相手X']);
        $y = Partner::factory()->create(['name' => '相手Y']);
        $rent = Recurring::factory()->create([
            'type' => RecordType::Expense, 'to_account_id' => null, 'start_month' => '2026-04-01',
            'category_id' => $this->food()->id, 'partner_id' => $a->id,
        ]);

        return [$a, $x, $y, $rent];
    }

    public function test_定期的な記録の変更で選んだ相手が_保存までにほかの相手にまとめられたら_保存しない(): void
    {
        [$a, $x, , $rent] = $this->recurringWithPartners();

        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('partnerName', '相手X')->call('selectPartner', $x->id);
        $this->post(route('partners.merge', $x), ['merge_target' => '相手Y'])->assertRedirect(route('partners.index'));

        $form->set('name', '直した')->call('save')->assertHasErrors()->assertNoRedirect();
        $this->assertSame('家賃', $rent->fresh()?->name);
        $this->assertSame($a->id, $rent->fresh()?->partner_id);
    }

    public function test_定期的な記録の変更で選んだ相手が_保存までに削除されたら_保存しない(): void
    {
        [$a, $x, , $rent] = $this->recurringWithPartners();

        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('partnerName', '相手X')->call('selectPartner', $x->id);
        Partner::query()->whereKey($x->id)->delete();

        $form->set('name', '直した')->call('save')->assertHasErrors()->assertNoRedirect();
        $this->assertSame('家賃', $rent->fresh()?->name);
        $this->assertSame($a->id, $rent->fresh()?->partner_id);
    }

    public function test_定期的な記録の変更で_もとの相手_aがまとめられて消えても_まとめ先の相手で保存できる(): void
    {
        [$a, , $y, $rent] = $this->recurringWithPartners();

        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent]);
        $this->post(route('partners.merge', $a), ['merge_target' => '相手Y'])->assertRedirect(route('partners.index'));

        $form->set('name', '直した')->call('save')->assertHasNoErrors();
        $this->assertSame('直した', $rent->fresh()?->name);
        $this->assertSame($y->id, $rent->fresh()?->partner_id);
    }

    // ------------------------------------------------------------------
    // 場面 4：口座一覧の残高の読み方
    // 根拠：依頼の決まり（ホーム・貯金の目標・月のまとめと同じく、残高を 1 つのトランザクションの中で読む）。
    //   設計書の中に、この決まりを直接書いた箇所は見つけられなかった（全体構成設計書.md も未確認の範囲あり）。
    //   テストは RefreshDatabase のトランザクションの中で動くので、もともとのレベル（基準）より深いことを見る。
    //   さらに、records を読む問い合わせが全部「同じ 1 つ」のトランザクションの中であることを確かめる
    // ------------------------------------------------------------------

    /**
     * 画面を開いて、records を読む問い合わせごとに「基準より深いか」と「何番目のトランザクションか」を集める
     *
     * @return array{base: int, levels: list<int>, transactions: list<int>}
     */
    private function recordQueryContext(string $url): array
    {
        $base = DB::transactionLevel();
        $began = 0;
        $levels = [];
        $transactions = [];
        Event::listen(TransactionBeginning::class, function () use (&$began) {
            $began++;
        });
        DB::listen(function (QueryExecuted $query) use (&$levels, &$transactions, &$began) {
            // 残高は記録（records）から数える。records を読む問い合わせだけを見る
            if (str_contains($query->sql, '`records`')) {
                $levels[] = DB::transactionLevel();
                $transactions[] = $began;
            }
        });

        $this->get($url)->assertOk();

        return ['base' => $base, 'levels' => $levels, 'transactions' => $transactions];
    }

    private function seedBalances(): void
    {
        $cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 1000]);
        $bank = Account::factory()->create(['name' => '銀行', 'type' => AccountType::Bank, 'initial_balance' => 5000]);
        Record::factory()->create(['type' => RecordType::Transfer, 'amount' => 300, 'account_id' => $cash->id, 'to_account_id' => $bank->id]);
        Record::factory()->create(['type' => RecordType::Expense, 'amount' => 100, 'account_id' => $cash->id, 'category_id' => $this->food()->id]);
    }

    public function test_ホームの残高は_1つのトランザクションの中で読む(): void
    {
        // 比べるための基準。ホームがこの条件を満たしていること（満たさなければ、テストの作り方がおかしい）
        $this->seedBalances();
        $ctx = $this->recordQueryContext(route('home'));

        $this->assertNotEmpty($ctx['levels'], 'ホームで records を読む問い合わせが見つからない');
        foreach ($ctx['levels'] as $level) {
            $this->assertGreaterThan($ctx['base'], $level, 'ホームの残高がトランザクションの外で読まれている');
        }
        $this->assertCount(1, array_unique($ctx['transactions']), 'ホームの残高が複数のトランザクションに分かれている');
    }

    public function test_口座一覧の残高は_1つのトランザクションの中で読む(): void
    {
        $this->seedBalances();
        $ctx = $this->recordQueryContext(route('accounts.index'));

        $this->assertNotEmpty($ctx['levels'], '口座一覧で records を読む問い合わせが見つからない');
        foreach ($ctx['levels'] as $level) {
            $this->assertGreaterThan($ctx['base'], $level, '口座一覧の残高がトランザクションの外で読まれている');
        }
        $this->assertCount(1, array_unique($ctx['transactions']), '口座一覧の残高が複数のトランザクションに分かれている');
    }

    // ------------------------------------------------------------------
    // 場面 5：残り日数
    // 根拠：画面設計書.md 5.2 の「1 日あたり ＝ 使える額 ÷ 今月の残りの日数（今日をふくむ）」
    //   10/25 なら末日 10/31 までで 7 日（AvailableAmountSpecTest の同じ値）
    // ------------------------------------------------------------------

    public function test_今日に時刻がついていても_今月の残り日数は時刻なしと同じになる(): void
    {
        Account::factory()->create(['type' => AccountType::Bank, 'initial_balance' => 80000]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 13:00:00'));

        $plain = AvailableAmount::calculate(CarbonImmutable::parse('2026-10-25'));
        $withTime = AvailableAmount::calculate(CarbonImmutable::parse('2026-10-25 13:00:00'));

        $this->assertSame(7, $plain['daysLeft']);
        $this->assertSame(7, $withTime['daysLeft']);
        $this->assertSame($plain['perDay'], $withTime['perDay']);
        $this->assertSame($plain['perWeek'], $withTime['perWeek']);
    }

    // ------------------------------------------------------------------
    // 場面 6：日付の上限（うるう日）
    // 根拠：画面設計書.md 5.4 の「2000年1月1日から、今日の 1 年先まで」。
    //   2月29日の「1 年先」は設計書に書かれていない（うるう日の扱いに触れていない）。
    //   依頼の決めたとおり、2029-02-28 までを許し、3月1日は許さない
    // ------------------------------------------------------------------

    private function saveExpenseOn(string $date): Testable
    {
        return Livewire::test(RecordForm::class)
            ->set('type', 'expense')
            ->set('date', $date)
            ->set('amount', '500')
            ->set('partnerName', 'うるう屋')
            ->set('categoryId', $this->food()->id)
            ->set('accountId', Account::factory()->create()->id)
            ->call('save');
    }

    public function test_今日が2028年2月29日のとき_日付は2029年2月28日まで入れられ_3月1日は入れられない(): void
    {
        $this->travelTo(CarbonImmutable::create(2028, 2, 29, 12, 0, 0));

        $this->saveExpenseOn('2029-02-28')->assertHasNoErrors();
        $this->assertSame(1, Record::query()->whereDate('date', '2029-02-28')->count());

        $this->saveExpenseOn('2029-03-01')->assertHasErrors(['date' => 'before_or_equal']);
        $this->assertSame(0, Record::query()->whereDate('date', '2029-03-01')->count());
    }
}
