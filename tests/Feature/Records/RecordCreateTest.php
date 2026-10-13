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
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 要件定義書 10.1 の記録の登録（No.5・7、画面設計書 5.4）のうち、登録の項目を確かめる。
 * 編集・削除は、記録の編集のイシューで確かめる。
 */
class RecordCreateTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $bank;

    private Category $food;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash]);
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行', 'type' => AccountType::Bank]);
        $this->food = $this->category('食費');
    }

    private function category(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    public function test_ログインしないと記録の画面は開けない(): void
    {
        auth()->logout();

        $this->get('/records/create')->assertRedirect('/login');
    }

    public function test_最初は支出で日付は今日(): void
    {
        $this->get('/records/create')->assertOk()->assertSeeLivewire(RecordForm::class);

        Livewire::test(RecordForm::class)
            ->assertSet('type', 'expense')
            ->assertSet('date', '2026-10-13')
            ->assertSet('accountId', null);
    }

    public function test_支出を記録できる(): void
    {
        $sub = Category::factory()->childOf($this->food)->create(['name' => '外食']);

        Livewire::test(RecordForm::class)
            ->set('amount', '680')
            ->set('partnerName', 'コンビニ')
            ->set('categoryId', $this->food->id)
            ->set('subcategoryId', $sub->id)
            ->set('accountId', $this->cash->id)
            ->set('item', 'おにぎり')
            ->set('memo', '昼')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('home'));

        $record = Record::query()->sole();
        $this->assertSame(RecordType::Expense, $record->type);
        $this->assertSame(680, $record->amount);
        $this->assertSame('2026-10-13', $record->date->toDateString());
        $this->assertSame($sub->id, $record->category_id, '小分類を選んだときは小分類');
        $this->assertSame($this->cash->id, $record->account_id);
        $this->assertSame('コンビニ', $record->partner->name);
        $this->assertSame('おにぎり', $record->item);
        $this->assertSame(-680, $this->cash->balance());
    }

    public function test_収入を記録できて品物は入らない(): void
    {
        Livewire::test(RecordForm::class)
            ->set('type', 'income')
            ->set('amount', '250000')
            ->set('partnerName', '株式会社まる')
            ->set('categoryId', $this->category('給与', CategoryKind::Income)->id)
            ->set('accountId', $this->bank->id)
            ->set('item', '入らない')
            ->call('save')
            ->assertHasNoErrors();

        $record = Record::query()->sole();
        $this->assertSame(RecordType::Income, $record->type);
        $this->assertNull($record->item);
        $this->assertSame(250_000, $this->bank->balance());
    }

    public function test_移動を記録できて_どこからとどこへに同じ口座は選べない(): void
    {
        Livewire::test(RecordForm::class)
            ->set('type', 'transfer')
            ->set('amount', '3000')
            ->set('accountId', $this->cash->id)
            ->set('toAccountId', $this->cash->id)
            ->call('save')
            ->assertHasErrors(['toAccountId' => 'different'])
            ->set('toAccountId', $this->bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $record = Record::query()->sole();
        $this->assertSame(RecordType::Transfer, $record->type);
        $this->assertNull($record->category_id);
        $this->assertNull($record->partner_id);
        $this->assertSame(0, $this->cash->balance() + $this->bank->balance(), '移動しても合計は変わらない');
    }

    public function test_金額はカンマや全角の数字でも受けとり_1円から99999999円まで(): void
    {
        foreach (['1,000' => 1000, '１０００' => 1000, ' 680 ' => 680] as $input => $expected) {
            Record::query()->delete();
            $this->expense()->set('amount', $input)->call('save')->assertHasNoErrors();
            $this->assertSame($expected, Record::query()->sole()->amount);
        }

        $this->expense()->set('amount', '')->call('save')->assertHasErrors(['amount' => 'required']);
        $this->expense()->set('amount', '0')->call('save')->assertHasErrors(['amount' => 'min']);
        $this->expense()->set('amount', '100000000')->call('save')->assertHasErrors(['amount' => 'max']);
        $this->expense()->set('amount', '1.5')->call('save')->assertHasErrors(['amount' => 'integer']);
        $this->expense()->set('amount', '-5')->call('save')->assertHasErrors('amount');
        $this->expense()->set('amount', '99999999')->call('save')->assertHasNoErrors();
    }

    public function test_必須の欄が空だと保存できない(): void
    {
        Livewire::test(RecordForm::class)
            ->call('save')
            ->assertHasErrors(['amount' => 'required', 'partnerName' => 'required', 'categoryId' => 'required', 'accountId' => 'required']);
        $this->assertSame(0, Record::query()->count());
    }

    public function test_使わない口座と分類は出ず_送られても保存しない(): void
    {
        $old = Account::factory()->create(['name' => '旧カード', 'is_active' => false]);
        $hobby = $this->category('趣味・娯楽');
        $hobby->update(['is_active' => false]);

        $this->get('/records/create')->assertDontSee('旧カード')->assertDontSee('趣味・娯楽')->assertSee('食費');

        $this->expense()->set('accountId', $old->id)->call('save')->assertHasErrors(['accountId' => 'exists']);
        $this->expense()->set('categoryId', $hobby->id)->call('save')->assertHasErrors(['categoryId' => 'exists']);
    }

    public function test_分類はその種類の大分類だけで_小分類は選んだ分類のものだけ(): void
    {
        $other = Category::factory()->childOf($this->category('日用品'))->create(['name' => '洗剤']);

        $this->expense()->set('categoryId', $this->category('給与', CategoryKind::Income)->id)->call('save')->assertHasErrors(['categoryId' => 'exists']);
        $this->expense()->set('subcategoryId', $other->id)->call('save')->assertHasErrors(['subcategoryId' => 'exists']);
    }

    public function test_分類を変えたら小分類は空に戻り_種類を変えても金額と日付とメモは残る(): void
    {
        $sub = Category::factory()->childOf($this->food)->create(['name' => '外食']);

        Livewire::test(RecordForm::class)
            ->set('categoryId', $this->food->id)
            ->set('subcategoryId', $sub->id)
            ->set('categoryId', $this->category('日用品')->id)
            ->assertSet('subcategoryId', null)
            ->set('amount', '500')
            ->set('date', '2026-10-10')
            ->set('memo', 'メモ')
            ->set('type', 'income')
            ->assertSet('amount', '500')
            ->assertSet('date', '2026-10-10')
            ->assertSet('memo', 'メモ')
            ->assertSet('categoryId', null);
    }

    public function test_口座の最初の値は_その種類で最後に保存した記録と同じ口座(): void
    {
        Record::factory()->create(['type' => RecordType::Expense, 'account_id' => $this->bank->id, 'date' => '2026-10-12']);
        Record::factory()->create(['type' => RecordType::Expense, 'account_id' => $this->cash->id, 'date' => '2026-09-01']);
        Record::factory()->create(['type' => RecordType::Transfer, 'account_id' => $this->bank->id, 'to_account_id' => $this->cash->id]);

        // 日付ではなく、あとで保存した記録の口座
        Livewire::test(RecordForm::class)
            ->assertSet('accountId', $this->cash->id)
            ->set('type', 'transfer')
            ->assertSet('accountId', $this->bank->id)
            ->assertSet('toAccountId', $this->cash->id)
            ->set('type', 'income')
            ->assertSet('accountId', null);

        $this->cash->update(['is_active' => false]);
        Livewire::test(RecordForm::class)->assertSet('accountId', null);
    }

    public function test_候補にない相手は保存したときに追加される(): void
    {
        Livewire::test(RecordForm::class)
            ->set('partnerName', '八百屋')
            ->assertSee('「八百屋」を新しい相手として追加します');

        $this->expense(['partnerName' => '八百屋'])->call('save')->assertHasNoErrors();
        $this->assertTrue(Partner::query()->where('name', '八百屋')->sole()->is_active);
    }

    public function test_使わない相手と同じ名前なら新しく作らず使うに戻す(): void
    {
        $gym = Partner::factory()->create(['name' => 'ジム', 'is_active' => false]);

        Livewire::test(RecordForm::class)
            ->set('partnerName', 'ジム')
            ->assertSee('『使わない』の相手です')
            ->assertDontSee('新しい相手として追加します');

        $this->expense(['partnerName' => 'ジム'])->call('save')->assertHasNoErrors();
        $this->assertSame(1, Partner::query()->where('name', 'ジム')->count());
        $this->assertTrue($gym->refresh()->is_active);
        $this->assertSame($gym->id, Record::query()->sole()->partner_id);
    }

    public function test_相手の候補は打った文字をふくむ使う相手を最近使った順に出す(): void
    {
        $a = Partner::factory()->create(['name' => 'コンビニA']);
        $b = Partner::factory()->create(['name' => 'コンビニB']);
        Partner::factory()->create(['name' => 'コンビニ旧', 'is_active' => false]);
        Partner::factory()->create(['name' => 'スーパー']);
        Record::factory()->create(['partner_id' => $a->id, 'date' => '2026-10-01']);
        Record::factory()->create(['partner_id' => $b->id, 'date' => '2026-10-09']);

        $form = Livewire::test(RecordForm::class)->set('partnerName', 'コンビ');

        $this->assertSame(['コンビニB', 'コンビニA'], $form->instance()->partnerSuggestions->pluck('name')->all());
        $form->assertSee('コンビニB')->assertDontSee('コンビニ旧')->assertDontSee('スーパー');
    }

    public function test_相手を選ぶと_同じ種類のいちばん新しい記録の分類と小分類と口座が入る(): void
    {
        $partner = Partner::factory()->create(['name' => 'コンビニ']);
        $sub = Category::factory()->childOf($this->food)->create(['name' => '外食']);
        Record::factory()->create(['partner_id' => $partner->id, 'type' => RecordType::Expense, 'category_id' => $this->category('日用品')->id, 'account_id' => $this->bank->id, 'date' => '2026-10-01']);
        Record::factory()->create(['partner_id' => $partner->id, 'type' => RecordType::Expense, 'category_id' => $sub->id, 'account_id' => $this->cash->id, 'date' => '2026-10-12']);
        // 先の日付と、ほかの種類の記録は使わない
        Record::factory()->create(['partner_id' => $partner->id, 'type' => RecordType::Expense, 'category_id' => $this->category('交通費')->id, 'account_id' => $this->bank->id, 'date' => '2026-10-20']);
        Record::factory()->create(['partner_id' => $partner->id, 'type' => RecordType::Income, 'category_id' => $this->category('臨時収入', CategoryKind::Income)->id, 'account_id' => $this->bank->id, 'date' => '2026-10-13']);

        Livewire::test(RecordForm::class)
            ->set('categoryId', $this->category('保険')->id)
            ->set('partnerName', 'コン')
            ->call('selectPartner', $partner->id)
            ->assertSet('partnerName', 'コンビニ')
            ->assertSet('categoryId', $this->food->id)
            ->assertSet('subcategoryId', $sub->id)
            ->assertSet('accountId', $this->cash->id);
    }

    public function test_相手を選んでも_使わない分類と口座は空にし_記録がない相手なら何も変えない(): void
    {
        $partner = Partner::factory()->create(['name' => 'コンビニ']);
        Record::factory()->create(['partner_id' => $partner->id, 'category_id' => $this->food->id, 'account_id' => $this->cash->id, 'date' => '2026-10-12']);
        $this->food->update(['is_active' => false]);
        $this->cash->update(['is_active' => false]);

        Livewire::test(RecordForm::class)
            ->call('selectPartner', $partner->id)
            ->assertSet('categoryId', null)
            ->assertSet('accountId', null);

        $fresh = Partner::factory()->create(['name' => '新しいお店']);
        Livewire::test(RecordForm::class)
            ->set('accountId', $this->bank->id)
            ->set('categoryId', $this->category('日用品')->id)
            ->call('selectPartner', $fresh->id)
            ->assertSet('accountId', $this->bank->id)
            ->assertSet('categoryId', $this->category('日用品')->id);
    }

    public function test_保存して続けて入力は_種類と日付と口座を残して同じ画面を開き直す(): void
    {
        $this->expense(['date' => '2026-10-10'])
            ->call('save', true)
            ->assertRedirect(route('records.create', ['type' => 'expense', 'date' => '2026-10-10', 'account' => $this->cash->id, 'back' => route('home')]));

        Livewire::withQueryParams(['type' => 'transfer', 'date' => '2026-10-10', 'account' => $this->cash->id, 'to_account' => $this->bank->id])
            ->test(RecordForm::class)
            ->assertSet('type', 'transfer')
            ->assertSet('date', '2026-10-10')
            ->assertSet('accountId', $this->cash->id)
            ->assertSet('toAccountId', $this->bank->id)
            ->assertSet('amount', '');
    }

    public function test_保存すると開く前の画面に戻り_ほかのサイトには戻らない(): void
    {
        Livewire::withQueryParams(['back' => route('accounts.index')])->test(RecordForm::class)
            ->assertSet('backUrl', route('accounts.index'));
        Livewire::withQueryParams(['back' => 'https://example.com/x'])->test(RecordForm::class)
            ->assertSet('backUrl', route('home'));
    }

    public function test_危ない戻る先は使わず_戻る先は画面から書きかえられない(): void
    {
        $host = parse_url(url('/'), PHP_URL_HOST);

        foreach (["javascript://{$host}/%0aalert(1)", "//{$host}/x", 'ftp://'.$host.'/x'] as $back) {
            Livewire::withQueryParams(['back' => $back])->test(RecordForm::class)->assertSet('backUrl', route('home'));
        }

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(RecordForm::class)->set('backUrl', 'https://example.com');
    }

    public function test_ur_lの値がおかしくても画面は開けて最初の値になる(): void
    {
        $this->get('/records/create?date[]=x&back[]=y&type[]=z')->assertOk();

        Livewire::withQueryParams(['date' => 'tomorrow', 'type' => 'other'])->test(RecordForm::class)
            ->assertSet('date', '2026-10-13')
            ->assertSet('type', 'expense');
    }

    public function test_日付は2000年から2099年まで(): void
    {
        $this->expense(['date' => '0026-10-13'])->call('save')->assertHasErrors(['date' => 'after_or_equal']);
        $this->expense(['date' => '2100-01-01'])->call('save')->assertHasErrors(['date' => 'before_or_equal']);
        $this->expense(['date' => '2026-13-01'])->call('save')->assertHasErrors(['date' => 'date_format']);
        $this->expense(['date' => '2000-01-01'])->call('save')->assertHasNoErrors();
    }

    public function test_頭に0がついた金額も受けとる(): void
    {
        $this->expense(['amount' => '0100'])->call('save')->assertHasNoErrors();
        $this->assertSame(100, Record::query()->sole()->amount);
    }

    public function test_品物は100文字まで_メモは1000文字まで(): void
    {
        $this->expense(['item' => str_repeat('あ', 101)])->call('save')->assertHasErrors(['item' => 'max']);
        $this->expense(['memo' => str_repeat('あ', 1001)])->call('save')->assertHasErrors(['memo' => 'max']);
        $this->expense(['item' => str_repeat('あ', 100), 'memo' => str_repeat('あ', 1000)])->call('save')->assertHasNoErrors();
    }

    public function test_保存すると保存しましたと出る(): void
    {
        $this->expense()->call('save');

        $this->get(route('home'))->assertSee('保存しました');
    }

    public function test_移動には分類と相手が入らない(): void
    {
        Livewire::test(RecordForm::class)
            ->set('categoryId', $this->food->id)
            ->set('partnerName', 'コンビニ')
            ->set('type', 'transfer')
            ->set('amount', '1000')
            ->set('accountId', $this->bank->id)
            ->set('toAccountId', $this->cash->id)
            ->call('save')
            ->assertHasNoErrors();

        $record = Record::query()->sole();
        $this->assertNull($record->category_id);
        $this->assertNull($record->partner_id);
        $this->assertSame(0, Partner::query()->where('name', 'コンビニ')->count(), '移動では相手を作らない');
    }

    public function test_相手の名前は空白の形をそろえてから探して保存する(): void
    {
        $seven = Partner::factory()->create(['name' => 'セブン イレブン']);

        Livewire::test(RecordForm::class)
            ->set('partnerName', 'セブン　イレブン　')
            ->assertDontSee('新しい相手として追加します');

        $this->expense(['partnerName' => 'セブン　 イレブン'])->call('save')->assertHasNoErrors();
        $this->assertSame($seven->id, Record::query()->sole()->partner_id);
        $this->assertSame(1, Partner::query()->where('name', 'like', 'セブン%')->count());

        // 相手の管理で打ったときも、同じ形にそろえる
        $this->post('/partners', ['name' => 'セブン　イレブン'])->assertSessionHasErrors('name');
    }

    /**
     * 必須の欄を入れた支出の画面
     *
     * @param  array<string, mixed>  $overrides
     */
    private function expense(array $overrides = []): Testable
    {
        $test = Livewire::test(RecordForm::class);
        foreach (array_merge(['amount' => '680', 'partnerName' => 'コンビニ', 'categoryId' => $this->food->id, 'accountId' => $this->cash->id], $overrides) as $key => $value) {
            $test->set($key, $value);
        }

        return $test;
    }
}
