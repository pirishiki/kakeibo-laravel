<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringSkip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * お金・入力が「知らせなしに」ずれたり消えたりしないことの固定（実装は見ずに、設計書の決まりだけを根拠に書いた）。
 * A 群（場面 1〜3）は決まりを足したばかりで、実装がまだ無ければ落ちるのが正しい。
 * B 群（場面 4〜8）は今も守れているはずの決まりで、落ちたら実装のバグを疑う。
 * 今日は 2026年10月24日（土）12:00
 */
class MoneyInputGuardTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 秒の端数まで 0 にそろえる
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0, 0));
        $this->cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'created_at' => '2026-01-01 00:00:00']);
    }

    private function parent(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    private function expense(string $date, int $amount, Category $category, Partner $partner, ?string $item = null): Record
    {
        return Record::factory()->create([
            'date' => $date, 'type' => RecordType::Expense, 'amount' => $amount,
            'account_id' => $this->cash->id, 'category_id' => $category->id, 'partner_id' => $partner->id, 'item' => $item,
        ]);
    }

    private function usualBudget(Category $category, int $amount, string $start = '2026-04-01'): Budget
    {
        return Budget::create(['category_id' => $category->id, 'month' => null, 'start_month' => $start, 'end_month' => null, 'amount' => $amount]);
    }

    // ==================================================================
    // A 群
    // ==================================================================

    // ------------------------------------------------------------------
    // 場面 1：種類を切りかえたとき、保存されなくなる欄に入っているものを知らせる
    // 根拠：画面設計書 5.4「種類を切りかえたあと、保存されなくなる欄に入っているものがあるときは、保存の前に知らせる」
    //   品物は支出のときだけ、相手は支出・収入のときだけ保存する。知らせは保存を止めない。種類を戻せば消える。登録のときも同じ。
    //   文言は設計書の例に合わせ、「品物」「おにぎり」「保存されません」の語で確かめる
    // ------------------------------------------------------------------

    private function onigiriRecord(?string $item = 'おにぎり'): Record
    {
        return $this->expense('2026-10-09', 680, $this->parent('食費'), Partner::factory()->create(['name' => 'コンビニ']), $item);
    }

    public function test_編集で種類を収入にすると_品物が保存されない知らせが出て_支出に戻すと消える(): void
    {
        $record = $this->onigiriRecord();

        Livewire::test(RecordForm::class, ['record' => $record])
            // 開いた直後（支出のまま）は、保存されなくなるものがないので出ない
            ->assertDontSee('保存されません')
            ->set('type', 'income')
            ->assertSee('品物')->assertSee('おにぎり')->assertSee('保存されません')
            ->set('type', 'expense')
            ->assertDontSee('保存されません');
    }

    public function test_編集で相手の入った支出を移動にすると_相手の名前つきで保存されない知らせが出る(): void
    {
        $record = $this->onigiriRecord(item: null);

        Livewire::test(RecordForm::class, ['record' => $record])
            ->set('type', 'transfer')
            ->assertSee('相手')->assertSee('コンビニ')->assertSee('保存されません')
            // 品物は入っていないので、品物の知らせは出ない
            ->assertDontSee('品物『');
    }

    public function test_品物も相手も入った支出を移動にすると_2行の知らせが出る(): void
    {
        $record = $this->onigiriRecord();

        Livewire::test(RecordForm::class, ['record' => $record])
            ->set('type', 'transfer')
            // 品物の行と相手の行の両方（「両方あるときは 2 行」）。Livewire のテストの出力は日本語がエスケープされた形なので、
            // assertSeeInOrder（順番の確認）は使えない。行ごとに assertSee で確かめる
            ->assertSee('品物『おにぎり』は保存されません')
            ->assertSee('相手『コンビニ』は保存されません');
    }

    public function test_収入にしたときは_相手は保存される種類なので出ず_品物だけ出る(): void
    {
        $record = $this->onigiriRecord();

        Livewire::test(RecordForm::class, ['record' => $record])
            ->set('type', 'income')
            ->assertSee('品物『おにぎり』は保存されません')
            // 相手『コンビニ』についての知らせは出ない（相手の名前は欄の値としては出ていてよい）
            ->assertDontSee('相手『コンビニ』は保存されません');
    }

    public function test_品物も相手も入っていないときは_どの種類に切りかえても知らせは出ない(): void
    {
        $record = $this->onigiriRecord(item: null);

        $form = Livewire::test(RecordForm::class, ['record' => $record])->set('partnerName', '');
        foreach (['income', 'transfer', 'expense'] as $type) {
            $form->set('type', $type)->assertDontSee('保存されません');
        }
    }

    public function test_登録のときも同じ知らせが出て_入っているものがなければ出ない(): void
    {
        // 何も入れずに種類を切りかえても出ない
        $form = Livewire::test(RecordForm::class);
        foreach (['income', 'transfer', 'expense'] as $type) {
            $form->set('type', $type)->assertDontSee('保存されません');
        }

        // 支出のまま品物と相手を入れて、収入・移動に切りかえる
        Livewire::test(RecordForm::class)
            ->set('item', 'おにぎり')->set('partnerName', 'スーパー')
            ->set('type', 'income')
            ->assertSee('品物『おにぎり』は保存されません')->assertDontSee('相手『スーパー』は保存されません')
            ->set('type', 'transfer')
            ->assertSee('品物『おにぎり』は保存されません')->assertSee('相手『スーパー』は保存されません')
            ->set('type', 'expense')
            ->assertDontSee('保存されません');
    }

    public function test_知らせが出ても保存は止まらず_保存すると品物がnullになる(): void
    {
        $record = $this->onigiriRecord();

        Livewire::test(RecordForm::class, ['record' => $record])
            ->set('type', 'income')
            ->assertSee('保存されません')
            // 収入では分類（収入用）と口座を選び直す必要がある（種類を変えると分類は空に戻る）
            ->set('categoryId', $this->parent('給与', CategoryKind::Income)->id)
            ->set('accountId', $this->cash->id)
            ->call('save')
            ->assertHasNoErrors();

        $record->refresh();
        $this->assertSame(RecordType::Income, $record->type);
        $this->assertNull($record->item, '収入では品物を保存しない');
        $this->assertNotNull($record->partner_id, '収入でも相手は保存する');
    }

    // ------------------------------------------------------------------
    // 場面 2：最後の月は、すでに記録された月のうちいちばん新しい月より前にできない
    // 根拠：画面設計書 5.10「入力欄とチェック」の「最後の月」の行
    //   （「最後の月は、記録された 2026年9月より前にはできません。」）
    // ------------------------------------------------------------------

    /** その予定の、この月の分の記録を作る（自動で記録されたことにする） */
    private function recordedFor(Recurring $recurring, string $month): Record
    {
        $record = Record::factory()->create([
            'type' => RecordType::Transfer, 'amount' => 70000, 'date' => $month.'-27',
            'account_id' => $recurring->account_id, 'to_account_id' => $recurring->to_account_id,
        ]);
        $record->forceFill(['recurring_id' => $recurring->id, 'recurring_month' => $month.'-01'])->save();

        return $record;
    }

    public function test_最後の月は_記録された最新の月より前にできず_その月なら保存できる(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-04-01']);
        $this->recordedFor($rent, '2026-08');
        $this->recordedFor($rent, '2026-09');

        // 8 月までにすると、9 月の記録が残るのに予定が終わった扱いになる → 止める
        Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('endYear', '2026')->set('endMonth', '8')
            ->call('save')
            ->assertHasErrors('endMonth')
            ->assertNoRedirect();
        $this->assertNull($rent->fresh()?->end_month, '保存されない');

        // 記録された最新の月（9 月）ちょうどなら保存できる
        Livewire::test(RecurringForm::class, ['recurring' => $rent->fresh()])
            ->set('endYear', '2026')->set('endMonth', '9')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('2026-09-01', $rent->fresh()?->end_month?->toDateString());
    }

    public function test_記録がまだない予定は_最初の月以降ならどの最後の月でも保存できる(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-04-01']);

        // 最初の月と同じ月
        Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('endYear', '2026')->set('endMonth', '4')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('2026-04-01', $rent->fresh()?->end_month?->toDateString());

        // もっと先の月
        Livewire::test(RecurringForm::class, ['recurring' => $rent->fresh()])
            ->set('endYear', '2027')->set('endMonth', '12')
            ->call('save')->assertHasNoErrors();

        // 最初の月より前は、これまでどおり止まる
        Livewire::test(RecurringForm::class, ['recurring' => $rent->fresh()])
            ->set('endYear', '2026')->set('endMonth', '3')
            ->call('save')->assertHasErrors('endMonth');
    }

    // ------------------------------------------------------------------
    // 場面 3：別タブで相手が別の相手に直されたときは、古い画面から保存しない
    // 根拠：画面設計書 5.4・5.10 の「開いたあとにほかの画面で直された記録は、上書きしない」（開いたあとにほかの画面で直されたものは、上書きせずに知らせる）
    //   エラーの欄の名前と文言は設計書にないので、エラーが出ること・保存されないことで確かめる
    // ------------------------------------------------------------------

    public function test_記録の編集で_開いたあとにほかの画面で相手が直されていたら_古い画面から保存しない(): void
    {
        $a = Partner::factory()->create(['name' => '相手A']);
        $b = Partner::factory()->create(['name' => '相手B']);
        $record = $this->expense('2026-10-09', 500, $this->parent('食費'), $a);

        $old = Livewire::test(RecordForm::class, ['record' => $record]);

        // 別の画面で、相手を B に直して保存した（A も B も残っている）
        $this->travel(10)->minutes();
        Record::query()->whereKey($record->id)->update(['partner_id' => $b->id, 'updated_at' => now()]);

        // 古い画面（相手は A のまま）から金額だけ変えて保存。B に直された内容を A に戻して上書きしてはいけない
        $old->set('amount', '900')->call('save')->assertHasErrors()->assertSee('開いたあとにほかの画面で直されました')->assertNoRedirect();

        $fresh = $record->fresh();
        $this->assertSame(500, $fresh?->amount);
        $this->assertSame($b->id, $fresh?->partner_id);
        $this->assertTrue(Partner::query()->whereKey($a->id)->exists());
    }

    public function test_定期的な記録の変更で_開いたあとにほかの画面で相手が直されていたら_古い画面から保存しない(): void
    {
        $a = Partner::factory()->create(['name' => '相手A']);
        $b = Partner::factory()->create(['name' => '相手B']);
        $rent = Recurring::factory()->create([
            'type' => RecordType::Expense, 'to_account_id' => null, 'start_month' => '2026-04-01',
            'category_id' => $this->parent('食費')->id, 'partner_id' => $a->id,
        ]);

        $old = Livewire::test(RecurringForm::class, ['recurring' => $rent]);

        $this->travel(10)->minutes();
        Recurring::query()->whereKey($rent->id)->update(['partner_id' => $b->id, 'updated_at' => now()]);

        $old->set('amount', '72000')->call('save')->assertHasErrors()->assertSee('開いたあとにほかの画面で直されました')->assertNoRedirect();

        $fresh = $rent->fresh();
        $this->assertSame(70000, $fresh?->amount);
        $this->assertSame($b->id, $fresh?->partner_id);
    }

    // ==================================================================
    // B 群（今も守れているはずの決まり。落ちたら実装のバグの疑い）
    // ==================================================================

    // ------------------------------------------------------------------
    // 場面 4：「今回はなしにする月」と「小窓で知らせる月」が重ならず、どちらも落ちない
    // 根拠：画面設計書 5.10「決まり」
    //   ・変更したせいで過ぎた月があとから対象に入ったときは、その月を「今回はなし」にする（保存の前に小窓で知らせる）
    //   ・変更したせいで、今月のまだ記録されていない分の日付がもう過ぎた日になったときは、小窓で
    //     「今回はなし」か「その日で記録する」を選んでもらう
    //   同時に起きたとき、前者は新しく対象に入った月、後者は「もとから対象だった今月」で、同じ月が両方に入らず、どちらの月も落ちない。
    //   今日は 10月24日（土）。毎月 27 日（次の平日）の 10 月の分は、10月27日（火）なのでまだ来ていない
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function skippedMonths(Recurring $recurring): array
    {
        return $recurring->skips()->orderBy('month')->get()->map(fn (RecurringSkip $s) => $s->month->format('Y-m'))->all();
    }

    /** 小窓の確認が済むまでは保存せず、小窓の内容に 2 つの知らせの両方が出ていることを確かめて、確認ボタンで進める */
    private function saveThroughDialogs(Testable $form, string $skipNotice, string $passedNotice, string $choice): void
    {
        $form->call('save')
            ->assertSet('skipNotice', $skipNotice)
            ->assertSet('passedNotice', $passedNotice);
        $form->call('save', true, $choice)->assertHasNoErrors();
    }

    public function test_最初の月を前にしながら日付を過ぎた日に直したとき_前の月は今回はなしで_今月は記録するを選べる(): void
    {
        // 7 月から（7〜9 月は記録済み）。10 月の分（10月27日）はまだ来ていない
        $rent = Recurring::factory()->create(['start_month' => '2026-07-01']);
        foreach (['2026-07', '2026-08', '2026-09'] as $month) {
            $this->recordedFor($rent, $month);
        }

        // 最初の月を 4 月に前倒し（4〜6 月が新しく対象に入る）、同時に日付を 5 日に（10月5日はもう過ぎた）
        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])->set('startMonth', '4')->set('day', '5');
        $this->saveThroughDialogs(
            $form,
            '2026年4月〜6月の分は記録せず、『今回はなし』にします',
            '10月の分（10月5日）は、日付がもう過ぎています。',
            'record',
        );

        // 4〜6 月は今回はなし。10 月は「記録する」を選んだので 10月5日で記録され、今回はなしには入らない
        $this->assertSame(['2026-04', '2026-05', '2026-06'], $this->skippedMonths($rent));
        $this->assertSame('2026-10-05', Record::query()->where('recurring_id', $rent->id)->whereDate('recurring_month', '2026-10-01')->sole()->date->toDateString());
        $this->assertSame(5, $rent->fresh()?->day);
    }

    public function test_毎月に変えながら日付を過ぎた日に直したとき_選んでいなかった月は今回はなしで_今月は今回はなしを選べる(): void
    {
        // 6・9・10 月だけの予定。6・9 月は記録済み。10 月（27日）はまだ来ていない
        $rent = Recurring::factory()->create(['start_month' => '2026-04-01', 'months' => [6, 9, 10]]);
        $this->recordedFor($rent, '2026-06');
        $this->recordedFor($rent, '2026-09');

        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])->set('monthsMode', 'every')->set('day', '5');
        $this->saveThroughDialogs(
            $form,
            '2026年4月〜5月、7月〜8月の分は記録せず、『今回はなし』にします',
            '10月の分（10月5日）は、日付がもう過ぎています。',
            'skip',
        );

        // 新しく対象に入った 4・5・7・8 月と、「今回はなし」を選んだ 10 月。記録済みの 6・9 月は入らない
        $this->assertSame(['2026-04', '2026-05', '2026-07', '2026-08', '2026-10'], $this->skippedMonths($rent));
        $this->assertSame(2, Record::query()->where('recurring_id', $rent->id)->count(), '10 月の記録は作られない');
    }

    public function test_土日祝日のずらし方を次の平日から前の平日に直したとき_今月の日付が過ぎれば小窓で聞き_最初の月を前にした分は今回はなし(): void
    {
        // 24 日・次の平日 → 10月24日（土）は 10月26日（月）にずれる＝まだ来ていない
        $rent = Recurring::factory()->create(['start_month' => '2026-07-01', 'day' => 24]);
        foreach (['2026-07', '2026-08', '2026-09'] as $month) {
            $this->recordedFor($rent, $month);
        }

        // 前の平日にすると 10月23日（金）にずれる＝もう過ぎた。同時に最初の月を 4 月にする
        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])->set('shift', 'previous')->set('startMonth', '4');
        $this->saveThroughDialogs(
            $form,
            '2026年4月〜6月の分は記録せず、『今回はなし』にします',
            '10月の分（10月23日）は、日付がもう過ぎています。',
            'record',
        );

        $this->assertSame(['2026-04', '2026-05', '2026-06'], $this->skippedMonths($rent));
        $this->assertSame('2026-10-23', Record::query()->where('recurring_id', $rent->id)->whereDate('recurring_month', '2026-10-01')->sole()->date->toDateString());
    }

    // ------------------------------------------------------------------
    // 場面 5：開いたときの値（originals）が送られてこない古い画面から、すでに予算がある分類へ別の金額を送っても、上書きしない
    // 根拠：画面設計書 5.11「古い画面から保存したとき」（開いたあとにほかの画面で予算が変わっていたら、その分類は保存せず知らせる）
    //   開いたときの値が分からない以上、「変わっていない」とは言えないので、上書きしない側に倒す。
    //   予算がまだ無い分類は、上書きするものがないので、originals が無くても新規保存できる
    // ------------------------------------------------------------------

    public function test_originalsが無い古い画面から_すでに予算がある分類へ別の金額を送っても_上書きせず知らせる(): void
    {
        $food = $this->parent('食費');
        $this->usualBudget($food, 30000);

        // originals のキー自体を送らない
        $this->followingRedirects()->put('/budgets', ['amounts' => [$food->id => '40000']])
            ->assertSee('「食費」は、開いたあとにほかの画面で予算が変わっていたので、保存していません');

        $this->assertSame(1, Budget::query()->where('category_id', $food->id)->count());
        $this->assertSame(30000, Budget::query()->where('category_id', $food->id)->value('amount'), '既存の予算を黙って上書きしない');
    }

    public function test_originalsが無い古い画面でも_予算がまだ無い分類は新規保存できて_ある分類だけ保存しない(): void
    {
        $food = $this->parent('食費');
        $daily = $this->parent('日用品');
        $this->usualBudget($food, 30000);

        $this->followingRedirects()->put('/budgets', ['amounts' => [$food->id => '40000', $daily->id => '8000']])
            ->assertSee('ほかの分類は保存しました。')
            ->assertSee('「食費」は、開いたあとにほかの画面で予算が変わっていたので、保存していません');

        $this->assertSame(30000, Budget::query()->where('category_id', $food->id)->value('amount'));
        $this->assertSame(8000, Budget::query()->where('category_id', $daily->id)->value('amount'));
    }

    public function test_originalsが無い古い画面でも_予算がまだ無い分類だけなら普通に保存できる(): void
    {
        $daily = $this->parent('日用品');

        $this->followingRedirects()->put('/budgets', ['amounts' => [$daily->id => '8000']])
            ->assertSee('保存しました')->assertDontSee('保存していません');

        $this->assertSame(8000, Budget::query()->where('category_id', $daily->id)->value('amount'));
    }

    // ------------------------------------------------------------------
    // 場面 6：相手のまとめ
    // 根拠：画面設計書 5.9「ほかの相手にまとめる決まり」
    //   記録だけでなく、その相手を使っている定期的な記録も、まとめ先に移す。まとめ先は自分自身と「使わない」相手は選べない
    // ------------------------------------------------------------------

    public function test_まとめ元の相手を使う記録と定期的な記録は全部まとめ先に移り_まとめ元は消える(): void
    {
        $typo = Partner::factory()->create(['name' => 'コンビ二']);
        $right = Partner::factory()->create(['name' => 'コンビニ']);
        $other = Partner::factory()->create(['name' => '別の店']);
        $food = $this->parent('食費');

        $records = collect([
            $this->expense('2026-10-01', 100, $food, $typo),
            $this->expense('2026-10-02', 200, $food, $typo),
            $this->expense('2026-10-03', 300, $food, $typo),
        ]);
        $mine = $this->expense('2026-10-04', 400, $food, $right);
        $others = $this->expense('2026-10-05', 500, $food, $other);
        $recurrings = collect([
            Recurring::factory()->create(['name' => '定期1', 'type' => RecordType::Expense, 'to_account_id' => null, 'category_id' => $food->id, 'partner_id' => $typo->id]),
            Recurring::factory()->create(['name' => '定期2', 'type' => RecordType::Expense, 'to_account_id' => null, 'category_id' => $food->id, 'partner_id' => $typo->id]),
        ]);
        $otherRecurring = Recurring::factory()->create(['name' => '定期3', 'type' => RecordType::Expense, 'to_account_id' => null, 'category_id' => $food->id, 'partner_id' => $other->id]);

        $this->post(route('partners.merge', $typo), ['merge_target' => 'コンビニ'])->assertRedirect(route('partners.index'));

        $this->assertFalse(Partner::query()->whereKey($typo->id)->exists(), 'まとめ元は消える');
        foreach ($records as $record) {
            $this->assertSame($right->id, $record->fresh()?->partner_id);
        }
        foreach ($recurrings as $recurring) {
            $this->assertSame($right->id, $recurring->fresh()?->partner_id);
        }
        $this->assertSame(4, Record::query()->where('partner_id', $right->id)->count(), '移った 3 件と、もとからの 1 件');
        // 関係のない記録・定期的な記録は変わらない
        $this->assertSame($right->id, $mine->fresh()?->partner_id);
        $this->assertSame($other->id, $others->fresh()?->partner_id);
        $this->assertSame($other->id, $otherRecurring->fresh()?->partner_id);
        // 金額も変わらない（件数と合計）
        $this->assertSame(1500, (int) Record::query()->sum('amount'));
    }

    public function test_まとめ先が使わない相手または自分自身のときは_何も変わらない(): void
    {
        $source = Partner::factory()->create(['name' => 'まとめ元']);
        $unused = Partner::factory()->create(['name' => '旧ジム', 'is_active' => false]);
        $food = $this->parent('食費');
        $record = $this->expense('2026-10-01', 100, $food, $source);
        $recurring = Recurring::factory()->create(['type' => RecordType::Expense, 'to_account_id' => null, 'category_id' => $food->id, 'partner_id' => $source->id]);

        foreach (['旧ジム', 'まとめ元'] as $target) {
            $this->post(route('partners.merge', $source), ['merge_target' => $target])->assertSessionHasErrors('merge_target');

            $this->assertTrue(Partner::query()->whereKey($source->id)->exists());
            $this->assertSame($source->id, $record->fresh()?->partner_id);
            $this->assertSame($source->id, $recurring->fresh()?->partner_id);
        }
        $this->assertTrue(Partner::query()->whereKey($unused->id)->exists());
    }

    // ------------------------------------------------------------------
    // 場面 7：日付を別の月に直した記録
    // 根拠：画面設計書 5.3（月の一覧）・5.5（月のまとめ）・5.11（予算の使った額は月のまとめで見る）
    //   10月31日の支出を 11月1日に直すと、10 月の支出・分類別の合計・予算の使った額から減り、11 月に増える
    // ------------------------------------------------------------------

    public function test_10月31日の支出を11月1日に直すと_月のまとめの支出と分類別の合計と予算の使った額が_10月は減り11月は増える(): void
    {
        $food = $this->parent('食費');
        $shop = Partner::factory()->create(['name' => 'スーパー']);
        $this->usualBudget($food, 30000);
        $this->expense('2026-10-10', 1000, $food, $shop);
        $moved = $this->expense('2026-10-31', 5000, $food, $shop);

        // 直す前：10 月は 6,000（予算 30,000 の 20%）。11 月は記録なし
        $this->get('/summary?month=2026-10')->assertOk()
            ->assertSeeInOrder(['支出', '6,000', '支出の分類', '食費', '6,000', '予算 30,000', 'あと 24,000（20%）']);
        $this->get('/summary?month=2026-11')->assertSee('この月の記録はまだありません');

        Livewire::test(RecordForm::class, ['record' => $moved])
            ->set('date', '2026-11-01')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('2026-11-01', $moved->fresh()?->date->toDateString());

        // 直したあと：10 月は 1,000（3%）に減り、11 月は 5,000（16%）に増える。5,000 が 10 月に残らない
        $this->get('/summary?month=2026-10')->assertOk()
            ->assertSeeInOrder(['支出', '1,000', '支出の分類', '食費', '1,000', '予算 30,000', 'あと 29,000（3%）'])
            ->assertDontSee('6,000')->assertDontSee('5,000');
        $this->get('/summary?month=2026-11')->assertOk()
            ->assertSeeInOrder(['支出', '5,000', '支出の分類', '食費', '5,000', '予算 30,000', 'あと 25,000（16%）'])
            ->assertDontSee('この月の記録はまだありません');
    }

    // ------------------------------------------------------------------
    // 場面 8：大分類を「使わない」にしたとき
    // 根拠：画面設計書 5.5（使わない分類も、その月に記録があれば元の名前で出る）・5.11（使わない分類の予算は、
    //   今月以降の月のまとめ・お知らせでは使わないが、過ぎた月の月のまとめではそのときの予算の状況を出す）
    //   使わないにする前と後で、過去の月のまとめの合計・分類別の表は同じ。今月以降は、予算の棒だけが出なくなる
    // ------------------------------------------------------------------

    public function test_大分類を使わないにしても_過去の月のまとめの合計と分類別の表は前と同じで_今月以降は予算の棒だけ出なくなる(): void
    {
        $car = $this->parent('自動車');
        $gas = Category::factory()->create(['name' => 'ガソリン', 'kind' => $car->kind, 'parent_id' => $car->id]);
        $shop = Partner::factory()->create(['name' => 'スタンド']);
        $this->usualBudget($gas, 6000);
        $this->usualBudget($car, 20000);
        $this->expense('2026-09-10', 5400, $gas, $shop);
        $this->expense('2026-09-20', 2000, $car, $shop);
        $this->expense('2026-10-10', 3000, $gas, $shop);
        $this->expense('2026-11-05', 1000, $gas, $shop);

        $septemberBefore = $this->get('/summary?month=2026-09')->assertOk()
            ->assertSeeInOrder(['支出', '7,400', '支出の分類', '自動車', '7,400', 'ガソリン', '5,400', '小分類なし', '2,000']);

        $car->update(['is_active' => false]);

        // 過去の月：合計も分類別の表も、使わないにする前と同じ（予算の状況も、そのときのまま）
        $this->get('/summary?month=2026-09')->assertOk()
            ->assertSeeInOrder(['支出', '7,400', '支出の分類', '自動車', '7,400', 'ガソリン', '5,400', '小分類なし', '2,000'])
            ->assertSee('予算 20,000')->assertSee('予算 6,000');
        $this->assertSame(
            substr_count((string) $septemberBefore->getContent(), '7,400'),
            substr_count((string) $this->get('/summary?month=2026-09')->getContent(), '7,400'),
            '使わないにする前後で、9 月のまとめに出る合計の数は同じ',
        );

        // 今月・来月：記録は合計と分類別の表に出るが、予算の棒は出ない
        foreach ([['2026-10', '3,000'], ['2026-11', '1,000']] as [$month, $amount]) {
            $this->get('/summary?month='.$month)->assertOk()
                ->assertSeeInOrder(['支出', $amount, '支出の分類', '自動車', $amount, 'ガソリン', $amount])
                ->assertDontSee('予算 6,000')->assertDontSee('予算 20,000');
        }
        // 予算を決める画面にも出ない。使わないのあいだ、予算の行そのものは消さずに残っている
        $this->get('/budgets')->assertDontSee('ガソリン');
        $this->assertSame(2, Budget::query()->whereIn('category_id', [$car->id, $gas->id])->count());
    }

    // ------------------------------------------------------------------
    // 保存が済んだ直後に、ほかの画面でその記録が消されても、エラーの画面にならない
    // 根拠：画面設計書 5.4（記録の編集）、要件定義書 3 章のはじめの決まり（エラーの画面にしない）
    // ------------------------------------------------------------------

    public function test_保存が済んだ直後にほかの画面で記録が消されても_エラーの画面にならず一覧に戻る(): void
    {
        $record = $this->onigiriRecord();
        $form = Livewire::test(RecordForm::class, ['record' => $record]);

        // 保存（データベースの更新）が済んだ直後に、ほかの画面で同じ記録が消された、を再現する
        // （モデルの「更新された」の合図で、イベントなしで行を消す）
        Record::updated(fn (Record $r) => Record::query()->whereKey($r->id)->delete());

        $form->set('amount', '700')->call('save')->assertHasNoErrors()->assertRedirect();
    }

    // ------------------------------------------------------------------
    // 定期的な記録でも、種類を移動に切りかえて保存されなくなる相手を、保存の前に知らせる
    // 根拠：画面設計書 5.10（記録の登録 5.4 と同じ「保存されなくなる欄に入っているものを知らせる」）
    // ------------------------------------------------------------------

    public function test_定期的な記録で相手の入った予定を移動にすると_相手の名前つきで保存されない知らせが出る(): void
    {
        $recurring = Recurring::factory()->create([
            'type' => RecordType::Expense, 'partner_id' => Partner::factory()->create(['name' => '大家'])->id,
            'category_id' => $this->parent('住まい')->id, 'to_account_id' => null,
        ]);

        Livewire::test(RecurringForm::class, ['recurring' => $recurring])
            ->assertDontSee('保存されません')
            ->set('type', 'transfer')
            ->assertSee('相手『大家』は保存されません')
            ->set('type', 'expense')
            ->assertDontSee('保存されません');
    }
}
