<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\User;
use App\Support\HomeCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ホーム（画面設計書 5.2 の第 1 段階の分）とメニュー（画面設計書 3.1）
 */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 2026年10月14日（水）。10月1日は木曜
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 10000]);
        $this->bank = Account::factory()->create(['name' => 'みどり銀行', 'type' => AccountType::Bank, 'initial_balance' => 200000]);
    }

    private function parent(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    private function record(RecordType $type, string $date, int $amount, ?Category $category = null, ?Account $account = null, ?string $partner = null): Record
    {
        return Record::factory()->create([
            'date' => $date,
            'type' => $type,
            'amount' => $amount,
            'account_id' => ($account ?? $this->cash)->id,
            'category_id' => $category?->id,
            'partner_id' => $partner === null ? null : Partner::factory()->create(['name' => $partner])->id,
        ]);
    }

    /** カレンダーのカードの中だけの HTML（最近の記録など、ほかのカードに出る金額とまぜないため） */
    private function calendarHtml(): string
    {
        $html = (string) $this->get('/')->assertOk()->getContent();
        $start = (int) strpos($html, 'class="home-cal"');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    /**
     * メニューで、いま開いている印（aria-current="page"）がついたリンクの行き先（パソコン・スマホの順）
     *
     * @return list<string>
     */
    private function currentMenuLinks(string $html): array
    {
        preg_match_all('/<a href="([^"]+)"[^>]*aria-current="page"/', $html, $matches);

        return $matches[1];
    }

    public function test_ログインしないとホームは開けない(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect('/login');
    }

    public function test_カレンダーは今月だけで_日曜はじまりのマス目になる(): void
    {
        $weeks = HomeCalendar::weeks(CarbonImmutable::create(2026, 10, 1));

        // 10月1日は木曜なので、日〜水の 4 マスを空ける。31日は土曜で、5 週になる
        $this->assertCount(5, $weeks);
        $this->assertSame([null, null, null, null], array_slice($weeks[0], 0, 4));
        $this->assertSame('2026-10-01', $weeks[0][4]?->toDateString());
        $this->assertSame('2026-10-31', $weeks[4][6]?->toDateString());

        $this->get('/')->assertOk()->assertSee('今月（10月）のカレンダー')->assertSee('aria-current="date"', false);
    }

    public function test_カレンダーのマスには支出と収入の合計を出し_移動と調整は出さない(): void
    {
        $food = $this->parent('食費');
        $this->record(RecordType::Expense, '2026-10-09', 680, $food);
        $this->record(RecordType::Expense, '2026-10-09', 520, $food);
        $this->record(RecordType::Income, '2026-10-09', 3000, $this->parent('給与', CategoryKind::Income));
        Record::factory()->create(['date' => '2026-10-10', 'type' => RecordType::Transfer, 'amount' => 7777,
            'account_id' => $this->bank->id, 'to_account_id' => $this->cash->id]);
        $this->record(RecordType::Adjustment, '2026-10-11', -555);
        // 先月の記録は出さない
        $this->record(RecordType::Expense, '2026-09-09', 4444, $food);

        $calendar = $this->calendarHtml();

        // 9日のマス：支出 680 ＋ 520、収入は「+」をつけて緑の太字
        $this->assertMatchesRegularExpression('/>9<.*?支出.*?1,200.*?fw-bold text-success.*?収入.*?\+3,000/s', $calendar);
        $this->assertStringNotContainsString('7,777', $calendar);
        $this->assertStringNotContainsString('555', $calendar);
        $this->assertStringNotContainsString('4,444', $calendar);
    }

    #[DataProvider('shortAmounts')]
    public function test_スマホのマスでは1万円以上を短く出す(int $amount, string $expected): void
    {
        $this->assertSame($expected, HomeCalendar::shortAmount($amount));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function shortAmounts(): array
    {
        return [
            '1 万円未満はそのまま' => [9999, '9,999'],
            'ちょうど 1 万' => [10000, '1万'],
            '小数 1 けたまで' => [121600, '12.2万'],
            '.0 はつけない' => [250000, '25万'],
            '四捨五入' => [12350, '1.2万'],
        ];
    }

    public function test_カレンダーの日を押すと記録の一覧のその日か_その前でいちばん近い記録のある日へ動く(): void
    {
        $this->record(RecordType::Expense, '2026-10-05', 100, $this->parent('食費'));
        Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Transfer, 'amount' => 1000,
            'account_id' => $this->bank->id, 'to_account_id' => $this->cash->id]);

        $dates = ['2026-10-05', '2026-10-08'];
        $this->assertNull(HomeCalendar::jumpDate(CarbonImmutable::create(2026, 10, 4), $dates));
        $this->assertSame('2026-10-05', HomeCalendar::jumpDate(CarbonImmutable::create(2026, 10, 5), $dates));
        $this->assertSame('2026-10-05', HomeCalendar::jumpDate(CarbonImmutable::create(2026, 10, 7), $dates));
        // 移動だけの日も、記録の一覧に見出しがあるので、その日へ
        $this->assertSame('2026-10-08', HomeCalendar::jumpDate(CarbonImmutable::create(2026, 10, 20), $dates));

        // 月も URL に入れる（ホームを開いたまま月が変わっても、カレンダーの月の一覧を開くように）
        $this->get('/')->assertSee('/records?month=2026-10#day-2026-10-08', false)->assertSee('/records?month=2026-10#day-2026-10-05', false);
        // 記録の一覧には、動いていく先の目印がある
        $this->get('/records')->assertSee('id="day-2026-10-08"', false);
    }

    public function test_今の残高は今日までの記録で計算し_使わない口座は出さない(): void
    {
        $this->record(RecordType::Expense, '2026-10-14', 1000, $this->parent('食費'));
        // 先の日付の記録は、今の残高には入らないが、今月の収支には入る
        $this->record(RecordType::Expense, '2026-10-20', 50000, $this->parent('趣味・娯楽'), $this->bank);
        Account::factory()->create(['name' => '古い口座', 'initial_balance' => 0, 'is_active' => false]);

        $response = $this->get('/')->assertOk();

        // 合計 ＝ 財布 9,000 ＋ みどり銀行 200,000
        $response->assertSeeInOrder(['今の残高の合計', '209,000', '口座ごと']);
        // 口座ごと：行を押すと残高を合わせる画面へ（種類の順：銀行口座 → 現金）
        $response->assertSeeInOrder([
            route('accounts.adjust', $this->bank), 'みどり銀行', '200,000',
            route('accounts.adjust', $this->cash), '財布', '9,000',
        ], false);
        $response->assertDontSee('古い口座');
        $response->assertSeeInOrder(['今月の収支', '-51,000']);
    }

    public function test_先月末と来月はじめの記録は今月のカレンダー_収支_多く使った分類に入れない(): void
    {
        $this->record(RecordType::Expense, '2026-09-30', 3333, $this->parent('日用品'));
        $this->record(RecordType::Expense, '2026-11-01', 4444, $this->parent('交通費'));
        $this->record(RecordType::Expense, '2026-10-31', 1111, $this->parent('食費'));

        $calendar = $this->calendarHtml();
        $this->assertStringNotContainsString('3,333', $calendar);
        $this->assertStringNotContainsString('4,444', $calendar);
        $this->assertStringContainsString('1,111', $calendar);
        $this->assertStringNotContainsString('#day-2026-09-30', $calendar);

        $response = $this->get('/');
        $response->assertSeeInOrder(['今月の収支', '-1,111', '収入', '0', '支出', '1,111', '多く使った分類', '食費', '1,111', '最近の記録']);
    }

    public function test_残高がマイナスの口座は_合計にも口座ごとにも赤い字のマイナスで出す(): void
    {
        $card = Account::factory()->create(['name' => 'クレジットカード', 'type' => AccountType::CreditCard, 'initial_balance' => 0]);
        $this->record(RecordType::Expense, '2026-10-10', 250000, $this->parent('趣味・娯楽'), $card);

        // 合計 ＝ 10,000 ＋ 200,000 − 250,000
        $this->get('/')->assertSeeInOrder([
            '今の残高の合計', 'text-danger', '-40,000',
            'クレジットカード', 'text-danger', '-250,000',
        ], false);
    }

    public function test_口座がまだないときは口座を追加するへ案内する(): void
    {
        Record::query()->delete();
        Account::query()->delete();

        $this->get('/')->assertOk()->assertSee('まだ口座がありません')->assertSee(route('accounts.create'));
    }

    public function test_今月の収支は移動と調整を入れず_月のまとめと同じ金額になる(): void
    {
        $this->record(RecordType::Income, '2026-10-01', 250000, $this->parent('給与', CategoryKind::Income));
        $this->record(RecordType::Expense, '2026-10-02', 182400, $this->parent('住まい'));
        Record::factory()->create(['date' => '2026-10-03', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->bank->id, 'to_account_id' => $this->cash->id]);
        $this->record(RecordType::Adjustment, '2026-10-04', -500);

        $this->get('/')->assertSeeInOrder(['今月の収支', '67,600', '収入', '250,000', '支出', '182,400']);
        $this->get('/summary')->assertSeeInOrder(['収入', '250,000', '支出', '182,400', '差', '67,600']);
        // 収支と多く使った分類のカードは、月のまとめへ
        $this->get('/')->assertSee('href="'.route('summary.index').'"', false);
    }

    public function test_多く使った分類は今月の支出の大分類を多い順に3つ(): void
    {
        $food = $this->parent('食費');
        $groceries = Category::factory()->create(['name' => '食料品', 'kind' => CategoryKind::Expense, 'parent_id' => $food->id]);
        $this->record(RecordType::Expense, '2026-10-01', 15000, $groceries);
        $this->record(RecordType::Expense, '2026-10-02', 3200, $food);
        $this->record(RecordType::Expense, '2026-10-03', 12500, $this->parent('趣味・娯楽'));
        $this->record(RecordType::Expense, '2026-10-04', 6100, $this->parent('日用品'));
        $this->record(RecordType::Expense, '2026-10-05', 900, $this->parent('交通費'));

        $response = $this->get('/');

        // 食費 ＝ 小分類 15,000 ＋ 小分類なし 3,200
        $response->assertSeeInOrder(['多く使った分類', '食費', '18,200', '趣味・娯楽', '12,500', '日用品', '6,100', '最近の記録']);
        // 4 つめの交通費は、カードに出さない（最近の記録には出るので、カードの中だけを見る）
        $html = (string) $response->getContent();
        $start = (int) strpos($html, '多く使った分類');
        $this->assertStringNotContainsString('交通費', substr($html, $start, (int) strpos($html, '最近の記録') - $start));
    }

    public function test_最近の記録は入力した日時の新しい順に5件で_押すと編集の画面へ(): void
    {
        $food = $this->parent('食費');
        foreach (range(1, 6) as $i) {
            $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, $i));
            // 6 件目（いちばん最後に入力）は、日付が古い 10月1日の記録
            $this->record(RecordType::Expense, $i === 6 ? '2026-10-01' : '2026-10-1'.$i, 100 * $i, $food, partner: '店'.$i);
        }

        $response = $this->get('/');

        $response->assertSeeInOrder(['最近の記録', '10月1日（木）', '店6', '店5', '店4', '店3', '店2', '記録の一覧へ']);
        $response->assertDontSee('店1');
        $response->assertSee(route('records.edit', Record::query()->latest('id')->first()));
    }

    public function test_記録がまだないときは記録するへ案内する(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('まだ記録がありません')
            ->assertSee('今月の支出はまだありません');
    }

    public function test_メニューはログインしたあとの画面に出て_いま開いている画面に印がつく(): void
    {
        $home = $this->get('/')->assertOk()
            ->assertSee('aria-label="メニュー"', false)
            ->assertSeeInOrder(['ホーム', '記録の一覧', '記録する', '月のまとめ', '設定'])
            ->getContent();
        $this->assertSame([route('home'), route('home')], $this->currentMenuLinks((string) $home));

        // 口座の画面では、パソコンのメニューは「口座」に、スマホのメニューは「設定」に印がつく
        $accounts = $this->get('/accounts')->assertOk()->getContent();
        $this->assertSame([route('accounts.index'), route('settings.index')], $this->currentMenuLinks((string) $accounts));

        // 記録の登録の画面では、右下の「＋ 記録する」を出さない（保存のボタンに重ならないように）
        $this->get('/records/create')->assertOk()->assertDontSee('record-fab');
        $this->get('/')->assertSee('record-fab');
    }

    public function test_ログイン画面にはメニューを出さない(): void
    {
        auth()->logout();

        $this->get('/login')->assertOk()->assertDontSee('aria-label="メニュー"', false);
    }
}
