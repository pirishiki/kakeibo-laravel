<?php

namespace Tests\Feature\ReviewSweep88;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Livewire\MonthlyAmounts;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * イシュー #88：2 回届いたとき（二重送信）の 2 回目（画面設計書 5.4・5.10。2026-11-07 に決定）。
 * 2 回目は、失敗の知らせを出さず、データは 1 回目のまま。今日は 2026年10月24日（土）
 */
class DoubleCallTest extends TestCase
{
    use RefreshDatabase;

    private Recurring $rent;

    private Recurring $power;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        // 毎月 27 日・次の平日の家賃（決まった金額 70,000 円）。9 月から
        $this->rent = Recurring::factory()->create(['start_month' => '2026-09-01']);
        // 毎月 20 日・次の平日の電気代（金額が変わる、概算 9,000 円）。10 月から
        $this->power = Recurring::factory()->create([
            'name' => '電気代', 'day' => 20, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 9000, 'start_month' => '2026-10-01',
        ]);
    }

    private function october(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 10, 1);
    }

    // ---- K-1：今回はなし ----

    public function test_k1_今回はなしを2回呼んでも_2回目に失敗の知らせを出さず_データは1回目のまま(): void
    {
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);

        $component->call('skip', $this->rent->id)->assertSet('notice', '');
        $component->call('skip', $this->rent->id)
            ->assertSet('notice', '')
            ->assertDontSee('できませんでした');

        $this->assertSame(1, $this->rent->skips()->count());
    }

    public function test_k1_金額が変わる予定の今回はなしを2回呼んでも_同じ(): void
    {
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);

        $component->call('skip', $this->power->id)->call('skip', $this->power->id)
            ->assertSet('notice', '')
            ->assertDontSee('できませんでした');

        $this->assertSame(1, $this->power->skips()->count());
        $this->assertSame(0, Record::query()->count());
    }

    // ---- K-2：もどす ----

    public function test_k2_もどすを2回呼んでも_2回目に失敗の知らせを出さず_データは1回目のまま(): void
    {
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);
        $component->call('skip', $this->rent->id);

        $component->call('restore', $this->rent->id)->assertSet('notice', '');
        $component->call('restore', $this->rent->id)
            ->assertSet('notice', '')
            ->assertDontSee('もどせませんでした');

        $this->assertSame(0, $this->rent->skips()->count());
        $this->assertSame(0, Record::query()->count(), '10月27日はまだ来ていないので記録しない');
    }

    public function test_k2_もどした時点で記録された行の_もどすの2回目も_記録を増やさず知らせない(): void
    {
        // 電気代（10月20日、日付は過ぎている）に金額を入れてあり、今回はなしにしてある
        RecurringAmount::query()->create(['recurring_id' => $this->power->id, 'month' => '2026-10-01', 'amount' => 9200]);
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);
        $component->call('skip', $this->power->id);

        $component->call('restore', $this->power->id);
        $this->assertSame(1, Record::query()->where('recurring_id', $this->power->id)->count(), '1 回目で記録される');

        $component->call('restore', $this->power->id)
            ->assertSet('notice', '')
            ->assertDontSee('もどせませんでした');
        $this->assertSame(1, Record::query()->where('recurring_id', $this->power->id)->count(), '2 回目で増えない');
        $this->assertSame(0, $this->power->skips()->count());
    }

    // ---- K-4：月ごとの金額の保存 ----

    public function test_k4_保存を2回呼んでも_1回目で記録された行について_入れた金額は使っていませんと出さない(): void
    {
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);

        $component->set('amounts.'.$this->power->id, '9,200')->call('save')
            ->assertRedirect(route('recurrings.index', ['month' => '2026-10']));
        $this->assertSame(1, Record::query()->where('recurring_id', $this->power->id)->count());

        $component->call('save')
            ->assertSet('notice', '')
            ->assertDontSee('入れた金額は使っていません');

        $this->assertSame(1, Record::query()->where('recurring_id', $this->power->id)->count(), '記録は増えない');
        $this->assertSame(9200, Record::query()->where('recurring_id', $this->power->id)->sole()->amount);
    }

    // ---- K-3：記録・定期的な記録の変更の保存 ----

    private function expenseRecord(): Record
    {
        $cash = Account::factory()->create(['name' => '財布', 'type' => AccountType::Cash, 'initial_balance' => 10000]);
        $food = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();

        return Record::factory()->create([
            'date' => '2026-10-09', 'type' => RecordType::Expense, 'amount' => 680, 'account_id' => $cash->id,
            'category_id' => $food->id, 'partner_id' => Partner::factory()->create(['name' => 'コンビニ'])->id, 'item' => 'おにぎり',
        ]);
    }

    public function test_k3_記録の変更の保存を開いた画面のまま2回呼ぶと_2回目は直されましたと出さず保存しましたの流れ(): void
    {
        $record = $this->expenseRecord();
        $component = Livewire::test(RecordForm::class, ['record' => $record]);

        $component->set('amount', '700')->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(700, $record->refresh()->amount);

        $component->call('save')
            ->assertHasNoErrors()
            ->assertDontSee('開いたあとにほかの画面で直されました')
            ->assertRedirect();

        $this->assertSame('保存しました', session('status'));
        $this->assertSame(700, $record->refresh()->amount);
        $this->assertSame(1, Record::query()->count());
    }

    public function test_k3_相手を変えた記録の変更の保存を2回呼んでも_2回目は直されましたと出さない(): void
    {
        $record = $this->expenseRecord();
        Partner::factory()->create(['name' => '駅']);
        $component = Livewire::test(RecordForm::class, ['record' => $record]);

        $component->set('partnerName', '駅')->call('save')->assertHasNoErrors();
        $component->call('save')
            ->assertHasNoErrors()
            ->assertDontSee('開いたあとにほかの画面で直されました')
            ->assertRedirect();

        $this->assertSame('駅', $record->refresh()->partner?->name);
    }

    public function test_k3_ほかの画面で直されていた記録は_今までどおり上書きせずに知らせる(): void
    {
        $record = $this->expenseRecord();
        $component = Livewire::test(RecordForm::class, ['record' => $record]);

        // ほかの画面で、メモを別の中身に直した（この画面が保存しようとしている中身とはちがう）
        Record::query()->whereKey($record->id)->update(['memo' => '別の画面で直した']);

        $component->set('amount', '700')->call('save')->assertHasErrors()->assertSee('開いたあとにほかの画面で直されました');
        $this->assertSame(680, $record->refresh()->amount);
    }

    public function test_k3_定期的な記録の変更の保存を開いた画面のまま2回呼ぶと_2回目は直されましたと出さず保存しましたの流れ(): void
    {
        $component = Livewire::test(RecurringForm::class, ['recurring' => $this->rent]);

        $component->set('name', '家賃（新）')->call('save')->assertHasNoErrors();
        $this->assertSame('家賃（新）', $this->rent->refresh()->name);

        $component->call('save')
            ->assertHasNoErrors()
            ->assertDontSee('開いたあとにほかの画面で直されました')
            ->assertRedirect();

        $this->assertSame('保存しました', session('status'));
        $this->assertSame('家賃（新）', $this->rent->refresh()->name);
        $this->assertSame(2, Recurring::query()->count());
    }

    public function test_k3_ほかの画面で直されていた定期的な記録は_今までどおり上書きせずに知らせる(): void
    {
        $component = Livewire::test(RecurringForm::class, ['recurring' => $this->rent]);

        Recurring::query()->whereKey($this->rent->id)->update(['name' => '別の画面で直した']);

        $component->set('name', '家賃（新）')->call('save')->assertHasErrors()->assertSee('開いたあとにほかの画面で直されました');
        $this->assertSame('別の画面で直した', $this->rent->refresh()->name);
    }
}
