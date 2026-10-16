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
 * 要件定義書 10.1 の記録の登録・編集・削除（No.5、画面設計書 5.4）のうち、編集・削除の項目を確かめる
 */
class RecordEditTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $bank;

    private Category $food;

    private Partner $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 14)->setTime(12, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 10000]);
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行', 'type' => AccountType::Bank]);
        $this->food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
        $this->shop = Partner::factory()->create(['name' => 'コンビニ']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function expense(array $attributes = []): Record
    {
        return Record::factory()->create([
            'date' => '2026-10-09',
            'type' => RecordType::Expense,
            'amount' => 680,
            'account_id' => $this->cash->id,
            'category_id' => $this->food->id,
            'partner_id' => $this->shop->id,
            'item' => 'おにぎり',
            ...$attributes,
        ]);
    }

    private function edit(Record $record): Testable
    {
        return Livewire::test(RecordForm::class, ['record' => $record]);
    }

    public function test_一覧の行から編集の画面が開き_もとの値が入っている(): void
    {
        $sub = Category::factory()->childOf($this->food)->create(['name' => '外食']);
        $record = $this->expense(['category_id' => $sub->id, 'amount' => 1200, 'memo' => '昼']);

        $this->get('/records')->assertSee(route('records.edit', $record));
        $this->get(route('records.edit', $record))->assertOk()->assertSeeLivewire(RecordForm::class)->assertSee('記録を直す');

        $this->edit($record)
            ->assertSet('type', 'expense')
            ->assertSet('amount', '1,200')
            ->assertSet('date', '2026-10-09')
            ->assertSet('accountId', $this->cash->id)
            ->assertSet('categoryId', $this->food->id)
            ->assertSet('subcategoryId', $sub->id)
            ->assertSet('partnerName', 'コンビニ')
            ->assertSet('item', 'おにぎり')
            ->assertSet('memo', '昼')
            // 編集のときは「保存」と「削除」。「保存して続けて入力」は出さない
            ->assertSee('削除')
            ->assertDontSee('保存して続けて入力');
    }

    public function test_ない番号の記録を開くと_エラーの画面でなく一覧で知らせる(): void
    {
        $this->get('/records/999/edit')
            ->assertRedirect(route('records.index'))
            ->assertSessionHas('warning', 'この記録は、ほかの画面で削除されていました。');
    }

    public function test_直して保存すると_その記録の月の一覧に戻る(): void
    {
        $record = $this->expense();
        session()->setPreviousUrl(url('/records?month=2026-10'));

        $this->edit($record)
            ->set('amount', '700')
            ->set('date', '2026-09-30')
            ->set('item', 'パン')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('records.index', ['month' => '2026-09']));

        $record->refresh();
        $this->assertSame(700, $record->amount);
        $this->assertSame('2026-09-30', $record->date->toDateString());
        $this->assertSame('パン', $record->item);
        $this->assertSame(1, Record::query()->count(), '新しい記録は増えない');
        $this->assertSame('保存しました', session('status'));
    }

    public function test_一覧以外から開いたときは元の画面に戻る(): void
    {
        session()->setPreviousUrl(url('/'));

        $this->edit($this->expense())->set('amount', '700')->call('save')->assertRedirect(url('/'));
    }

    public function test_残高も直した金額で計算される(): void
    {
        $record = $this->expense();
        $this->assertSame(10000 - 680, $this->cash->balance());

        $this->edit($record)->set('amount', '700')->call('save')->assertHasNoErrors();

        $this->assertSame(10000 - 700, $this->cash->balance());
    }

    public function test_種類を変えて直せる(): void
    {
        $record = $this->expense();

        $this->edit($record)
            ->set('type', 'transfer')
            ->set('accountId', $this->cash->id)
            ->set('toAccountId', $this->bank->id)
            ->call('save')
            ->assertHasNoErrors();

        $record->refresh();
        $this->assertSame(RecordType::Transfer, $record->type);
        $this->assertNull($record->category_id);
        $this->assertNull($record->partner_id);
        $this->assertNull($record->item);
    }

    public function test_編集で相手を選び直しても_ほかの欄は変わらない(): void
    {
        $record = $this->expense();
        $other = Partner::factory()->create(['name' => '駅']);
        $transport = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '交通費')->firstOrFail();
        Record::factory()->create(['date' => '2026-10-01', 'type' => RecordType::Expense, 'account_id' => $this->bank->id,
            'category_id' => $transport->id, 'partner_id' => $other->id]);

        $this->edit($record)
            ->set('partnerName', '駅')
            ->call('selectPartner', $other->id)
            ->assertSet('partnerId', $other->id)
            ->assertSet('categoryId', $this->food->id)
            ->assertSet('accountId', $this->cash->id);
    }

    public function test_もともと入っている使わない口座_分類_相手は出て_そのまま保存できる(): void
    {
        $record = $this->expense();
        $this->cash->update(['is_active' => false]);
        $this->food->update(['is_active' => false]);
        $this->shop->update(['is_active' => false]);

        $this->edit($record)
            ->assertSee('財布')
            ->assertSee('食費')
            ->set('memo', 'メモだけ直す')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('メモだけ直す', $record->refresh()->memo);
        $this->assertFalse($this->shop->refresh()->is_active, 'もともとの相手のままなら「使う」に戻さない');
    }

    public function test_もともと入っていない使わない口座は選べない(): void
    {
        $old = Account::factory()->create(['name' => '古い財布', 'is_active' => false]);

        $this->edit($this->expense())
            ->assertDontSee('古い財布')
            ->set('accountId', $old->id)
            ->call('save')
            ->assertHasErrors(['accountId' => 'exists']);
    }

    public function test_調整の記録は種類を変えられず_マイナスも入れられて_0にはできない(): void
    {
        $record = Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Adjustment, 'amount' => -200,
            'account_id' => $this->cash->id]);

        $this->edit($record)
            ->assertSee('調整')
            ->assertDontSee('相手')
            ->assertSet('amount', '-200')
            ->set('type', 'expense')
            ->assertSet('type', 'adjustment')
            ->set('amount', '0')
            ->call('save')
            ->assertHasErrors(['amount' => 'not_in'])
            ->set('amount', '-1,500')
            ->call('save')
            ->assertHasNoErrors();

        $record->refresh();
        $this->assertSame(RecordType::Adjustment, $record->type);
        $this->assertSame(-1500, $record->amount);
    }

    public function test_ほかの記録を調整に変えることはできない(): void
    {
        $record = $this->expense();

        $this->edit($record)->set('type', 'adjustment')->assertSet('type', 'expense');
    }

    public function test_使わない口座の記録は_残高が変わる直しはできず_メモは直せる(): void
    {
        $old = Account::factory()->create(['name' => '旧カード', 'initial_balance' => 680]);
        $record = $this->expense(['account_id' => $old->id]);
        $old->update(['is_active' => false]);

        $this->edit($record)
            ->set('amount', '5680')
            ->call('save')
            ->assertHasErrors('blocker')
            ->assertSee('この記録を直すと、『使わない』口座の旧カードの残高が -5,000 円になるので、保存できません。先に旧カードを『使う』に戻してから直してください');
        $this->assertSame(680, $record->refresh()->amount);

        $this->edit($record)->set('date', '2026-10-01')->call('save')->assertHasErrors('blocker')->assertSee('日付は変えられません');
        $this->edit($record)->set('accountId', $this->cash->id)->call('save')->assertHasErrors('blocker');

        $this->edit($record)->set('memo', '旧カードの最後の買い物')->call('save')->assertHasNoErrors();
        $this->assertSame('旧カードの最後の買い物', $record->refresh()->memo);
        $this->assertSame(0, $old->balance());
    }

    public function test_削除すると記録が消えて_元の画面に戻る(): void
    {
        $record = $this->expense();
        session()->setPreviousUrl(url('/records?month=2026-10'));

        $this->edit($record)
            ->assertSee('この記録を削除しますか？')
            ->call('delete')
            ->assertRedirect(route('records.index', ['month' => '2026-10']));

        $this->assertNull(Record::query()->find($record->id));
        $this->assertSame('削除しました', session('status'));
        $this->assertSame(10000, $this->cash->balance());
    }

    public function test_使わない口座の記録は削除できない(): void
    {
        $old = Account::factory()->create(['name' => '旧カード', 'initial_balance' => 680]);
        $record = $this->expense(['account_id' => $old->id]);
        $old->update(['is_active' => false]);

        $this->edit($record)
            ->assertSee('この記録を削除すると、『使わない』口座の旧カードの残高が 680 円になるので、削除できません。')
            ->assertDontSee('この記録を削除しますか？')
            ->call('delete');

        $this->assertNotNull(Record::query()->find($record->id));
    }

    public function test_編集で種類を切りかえても_口座はもとのまま(): void
    {
        $record = $this->expense();
        // 収入で最後に登録した記録は銀行。登録の画面なら、収入に切りかえると銀行が入る
        Record::factory()->create(['date' => '2026-10-01', 'type' => RecordType::Income, 'account_id' => $this->bank->id]);

        $this->edit($record)
            ->set('type', 'income')
            ->assertSet('accountId', $this->cash->id)
            ->set('type', 'expense')
            ->assertSet('accountId', $this->cash->id);
    }

    public function test_使わない口座がどこへの移動は_金額を変えたり削除したりできない(): void
    {
        $old = Account::factory()->create(['name' => '旧IC', 'initial_balance' => -3000]);
        $record = Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Transfer, 'amount' => 3000,
            'account_id' => $this->cash->id, 'to_account_id' => $old->id]);
        $old->update(['is_active' => false]);

        $this->edit($record)->set('amount', '2000')->call('save')->assertHasErrors('blocker')->assertSee('旧IC');
        // どこから と どこへ を入れかえる
        $this->edit($record)->set('accountId', $old->id)->set('toAccountId', $this->cash->id)->call('save')->assertHasErrors('blocker');
        $this->edit($record)->assertSee('この記録を削除すると、『使わない』口座の旧ICの残高が -3,000 円になるので');
        $this->assertSame(0, $old->balance());
    }

    public function test_使わない口座の記録は_種類を変えて残高が変わるなら止め_変わらないなら止めない(): void
    {
        $old = Account::factory()->create(['name' => '旧カード', 'initial_balance' => 680]);
        $record = $this->expense(['account_id' => $old->id]);
        $old->update(['is_active' => false]);
        $income = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Income)->firstOrFail();

        // 支出 → 収入：-680 が +680 になる
        $this->edit($record)->set('type', 'income')->set('categoryId', $income->id)->call('save')->assertHasErrors('blocker');

        // 支出 → 移動（どこから：旧カード）：旧カードから 680 円出るのは同じ
        $this->edit($record)->set('type', 'transfer')->set('toAccountId', $this->cash->id)->call('save')->assertHasNoErrors();
        $this->assertSame(RecordType::Transfer, $record->refresh()->type);
        $this->assertSame(0, $old->balance());
    }

    public function test_使わない口座の調整の記録は_金額を変えられない(): void
    {
        $old = Account::factory()->create(['name' => '旧財布', 'initial_balance' => 200]);
        $record = Record::factory()->create(['date' => '2026-10-08', 'type' => RecordType::Adjustment, 'amount' => -200,
            'account_id' => $old->id]);
        $old->update(['is_active' => false]);

        $this->edit($record)->set('amount', '-100')->call('save')->assertHasErrors('blocker');
        $this->edit($record)->set('memo', '数えまちがい')->call('save')->assertHasNoErrors();
    }

    public function test_もともと入っていない使わない分類は選べない(): void
    {
        $record = $this->expense();
        $old = Category::factory()->create(['name' => '古い分類', 'is_active' => false]);
        $oldSub = Category::factory()->childOf($this->food)->create(['name' => '古い小分類', 'is_active' => false]);

        $this->edit($record)
            ->assertDontSee('古い分類')
            ->set('categoryId', $old->id)
            ->call('save')
            ->assertHasErrors(['categoryId' => 'exists'])
            ->set('categoryId', $this->food->id)
            ->assertDontSee('古い小分類')
            ->set('subcategoryId', $oldSub->id)
            ->call('save')
            ->assertHasErrors(['subcategoryId' => 'exists']);
    }

    public function test_もともとの使わない相手の名前を打ち直しても_使うに戻さない(): void
    {
        $record = $this->expense();
        $this->shop->update(['is_active' => false]);

        $this->edit($record)
            ->set('partnerName', 'コンビ')
            ->set('partnerName', 'コンビニ')
            ->assertDontSee('保存すると『使う』に戻して使います')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->shop->refresh()->is_active);
        $this->assertSame($this->shop->id, $record->refresh()->partner_id);
    }

    public function test_登録の画面では種類を調整にできない(): void
    {
        Livewire::test(RecordForm::class)->set('type', 'adjustment')->assertSet('type', 'expense');
    }

    public function test_戻る先によそのサイトや編集の画面は使わない(): void
    {
        // 「//よそのサイト」は、ブラウザがよそのサイトとして読むので、ホームに戻す
        $this->get('/records/create?back='.urlencode('http://localhost//evil.example'))->assertOk();
        Livewire::withQueryParams(['back' => 'http://localhost//evil.example'])
            ->test(RecordForm::class)
            ->assertSet('backUrl', route('home'));

        $record = $this->expense();
        session()->setPreviousUrl(route('records.edit', $record));
        $this->edit($record)->assertSet('backUrl', route('home'));
    }

    public function test_左上のやめるで_保存せずに開く前の画面に戻れる(): void
    {
        $record = $this->expense();
        session()->setPreviousUrl(url('/records?month=2026-09'));

        $this->edit($record)
            ->assertSeeInOrder(['‹ やめる', '記録を直す'])
            ->assertSee('href="'.url('/records?month=2026-09').'"', false);

        // 登録の画面にも同じ位置に出す
        session()->setPreviousUrl(url('/'));
        Livewire::test(RecordForm::class)
            ->assertSeeInOrder(['‹ やめる', '記録する'])
            ->assertSee('href="'.url('/').'"', false);
    }

    public function test_編集している記録の番号は画面の側から変えられない(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->edit($this->expense())->set('recordId', $this->expense(['amount' => 1])->id);
    }

    public function test_記録がない月の記録するボタンは_その月の1日を日付に入れる(): void
    {
        $this->get('/records?month=2026-08')->assertSee(route('records.create', ['date' => '2026-08-01']), false);
        // 今月のときは今日のまま
        $this->get('/records')->assertSee('href="'.route('records.create').'"', false);
    }
}
