<?php

namespace Tests\Feature\Recurrings;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Livewire\RecurringForm;
use App\Models\Account;
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
 * 要件定義書 10.2「定期的な記録の登録・変更」のうち、登録の項目を確かめる（画面設計書 5.10）
 * 今日は 2026年10月24日（土）にする（要件定義書の例と同じ日）
 */
class RecurringCreateTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private Account $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行', 'type' => AccountType::Bank]);
        $this->card = Account::factory()->create(['name' => 'カードA', 'type' => AccountType::CreditCard]);
    }

    private function category(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    /** 給料（収入・毎月 25 日・前の平日・毎月変わる）の欄をうめた部品 */
    private function salary(): Testable
    {
        return Livewire::test(RecurringForm::class)
            ->set('type', 'income')
            ->set('name', '給料')
            ->set('isFixed', '0')
            ->set('estimatedAmount', '250,000')
            ->set('day', '25')
            ->set('partnerName', '株式会社まる')
            ->set('categoryId', $this->category('給与', CategoryKind::Income)->id)
            ->set('accountId', $this->bank->id);
    }

    public function test_ログインしないと開けない(): void
    {
        auth()->logout();

        $this->get('/recurrings')->assertRedirect('/login');
        $this->get('/recurrings/create')->assertRedirect('/login');
    }

    public function test_最初は支出で_次の平日_毎月_使う(): void
    {
        $this->get('/recurrings/create')->assertOk()->assertSeeLivewire(RecurringForm::class);

        Livewire::test(RecurringForm::class)
            ->assertSet('type', 'expense')
            ->assertSet('shift', 'next')
            ->assertSet('monthsMode', 'every')
            ->assertSet('isFixed', '1')
            ->assertSet('isActive', true);
    }

    public function test_ずらし方の最初の値は_収入なら前の平日_支出と移動なら次の平日(): void
    {
        Livewire::test(RecurringForm::class)
            ->set('type', 'income')->assertSet('shift', 'previous')
            ->set('type', 'transfer')->assertSet('shift', 'next')
            ->set('type', 'expense')->assertSet('shift', 'next');
    }

    public function test_最初の月は記録する日がまだ来ていない月(): void
    {
        // 10月25日（日）を前の平日にずらすと 10月23日で、もう過ぎているので 11 月（要件定義書 10.2 の例）
        $this->salary()->assertSet('startYear', '2026')->assertSet('startMonth', '11');

        // 27日（火）は、まだ来ていないので 10 月
        Livewire::test(RecurringForm::class)->set('day', '27')->assertSet('startMonth', '10');
    }

    public function test_今日が記録する日なら最初の月は今月(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));

        $this->salary()->assertSet('startMonth', '10');
    }

    public function test_選んだ月だけなら_選んだ月の中でいちばん早い月(): void
    {
        Livewire::test(RecurringForm::class)
            ->set('day', '10')
            ->set('monthsMode', 'selected')
            ->set('months', ['6', '12'])
            ->assertSet('startYear', '2026')
            ->assertSet('startMonth', '12')
            ->set('months', ['6'])
            ->assertSet('startYear', '2027')
            ->assertSet('startMonth', '6');
    }

    public function test_次の平日にずらして来月になる先月の分がまだなら_最初の月は先月(): void
    {
        // 11月1日（日）。10月31日（土）の分は次の平日の 11月2日なので、まだ来ていない
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));

        Livewire::test(RecurringForm::class)
            ->set('day', '31')
            ->assertSet('startYear', '2026')
            ->assertSet('startMonth', '10');
    }

    public function test_自分で選んだ最初の月は_日付を変えても書きかえない(): void
    {
        Livewire::test(RecurringForm::class)
            ->set('startMonth', '12')
            ->set('day', '5')
            ->assertSet('startMonth', '12');
    }

    public function test_金額が変わる収入を登録できる(): void
    {
        $this->salary()->call('save')->assertHasNoErrors()->assertRedirect(route('recurrings.index'));

        $recurring = Recurring::query()->sole();
        $this->assertSame('給料', $recurring->name);
        $this->assertSame(RecordType::Income, $recurring->type);
        $this->assertSame(25, $recurring->day);
        $this->assertSame(RecurringShift::Previous, $recurring->shift);
        $this->assertNull($recurring->months);
        $this->assertFalse($recurring->is_fixed);
        $this->assertNull($recurring->amount);
        $this->assertSame(250_000, $recurring->estimated_amount);
        $this->assertSame('株式会社まる', $recurring->partner?->name, '新しい相手は保存したときに追加する');
        $this->assertSame('2026-11-01', $recurring->start_month->toDateString());
        $this->assertNull($recurring->end_month);
        $this->assertTrue($recurring->is_active);
        $this->assertSame(0, RecurringSkip::query()->count());
    }

    public function test_決まった金額の移動を_選んだ月だけで登録できる(): void
    {
        $food = $this->category('食費');

        Livewire::test(RecurringForm::class)
            ->set('type', 'transfer')
            ->set('name', 'カードAの引き落とし')
            ->set('amount', '52000')
            ->set('day', '31')
            ->set('monthsMode', 'selected')
            ->set('months', ['12', '6', '6'])
            ->set('accountId', $this->bank->id)
            ->set('toAccountId', $this->card->id)
            // 移動のときは、分類・相手は入らない
            ->set('categoryId', $food->id)
            ->set('partnerName', '入らない')
            ->set('endYear', '2027')
            ->set('endMonth', '12')
            ->call('save')
            ->assertHasNoErrors();

        $recurring = Recurring::query()->sole();
        $this->assertSame(RecordType::Transfer, $recurring->type);
        $this->assertSame(31, $recurring->day);
        $this->assertSame([6, 12], $recurring->months, '小さい順・重なりなしで入る');
        $this->assertSame(52_000, $recurring->amount);
        $this->assertSame($this->card->id, $recurring->to_account_id);
        $this->assertNull($recurring->category_id);
        $this->assertNull($recurring->partner_id);
        $this->assertSame('2026-12-01', $recurring->start_month->toDateString());
        $this->assertSame('2027-12-01', $recurring->end_month?->toDateString());
        $this->assertFalse(Partner::query()->where('name', '入らない')->exists());
    }

    public function test_必須の欄と金額のチェック(): void
    {
        Livewire::test(RecurringForm::class)
            ->call('save')
            ->assertHasErrors(['name' => 'required', 'amount' => 'required', 'partnerName' => 'required', 'categoryId' => 'required', 'accountId' => 'required']);

        // 金額が決まっているなら金額、変わるなら概算が必須。どちらも 1 円以上の整数
        Livewire::test(RecurringForm::class)->set('amount', '0')->call('save')->assertHasErrors(['amount' => 'min']);
        Livewire::test(RecurringForm::class)->set('isFixed', '0')->call('save')
            ->assertHasErrors(['estimatedAmount' => 'required'])->assertHasNoErrors('amount');
        Livewire::test(RecurringForm::class)->set('isFixed', '0')->set('estimatedAmount', '1.5')->call('save')
            ->assertHasErrors(['estimatedAmount' => 'integer']);
    }

    public function test_名前は50文字まで_同じ名前は2つ登録できる(): void
    {
        $this->salary()->set('name', str_repeat('あ', 51))->call('save')->assertHasErrors(['name' => 'max']);

        $this->salary()->call('save')->assertHasNoErrors();
        $this->salary()->call('save')->assertHasNoErrors();
        $this->assertSame(2, Recurring::query()->where('name', '給料')->count());
    }

    public function test_日付は1日から28日か月末だけ(): void
    {
        foreach (['29', '30', '0', 'x'] as $day) {
            $this->salary()->set('day', $day)->call('save')->assertHasErrors(['day' => 'in']);
        }
        $this->salary()->set('day', '28')->call('save')->assertHasNoErrors();
        $this->salary()->set('day', '31')->call('save')->assertHasNoErrors();
    }

    public function test_選んだ月だけなら1つ以上選ぶ(): void
    {
        $this->salary()->set('monthsMode', 'selected')->set('months', [])->call('save')->assertHasErrors(['months' => 'required']);
        $this->salary()->set('monthsMode', 'selected')->set('months', ['13'])->call('save')->assertHasErrors(['months.0' => 'in']);
    }

    public function test_最後の月は最初の月より前にできない(): void
    {
        $this->salary()->set('endYear', '2026')->set('endMonth', '10')->call('save')
            ->assertHasErrors('endMonth')
            ->assertSee('最後の月は、最初の月より前にはできません。');

        // 最初の月と同じ月はよい
        $this->salary()->set('endYear', '2026')->set('endMonth', '11')->call('save')->assertHasNoErrors();
    }

    public function test_最後の月の年をなしに戻すと月も空になる(): void
    {
        $this->salary()->set('endYear', '2027')->set('endMonth', '3')->set('endYear', '')->assertSet('endMonth', '')
            ->call('save')->assertHasNoErrors();

        $this->assertNull(Recurring::query()->sole()->end_month);
    }

    public function test_使わない口座と分類は選べず_移動の口座は同じものにできない(): void
    {
        $old = Account::factory()->create(['is_active' => false]);
        $this->salary()->set('accountId', $old->id)->call('save')->assertHasErrors(['accountId' => 'exists']);

        $bonus = $this->category('賞与', CategoryKind::Income);
        $bonus->update(['is_active' => false]);
        $this->salary()->set('categoryId', $bonus->id)->call('save')->assertHasErrors(['categoryId' => 'exists']);

        // 支出用の分類は、収入では選べない
        $this->salary()->set('categoryId', $this->category('食費')->id)->call('save')->assertHasErrors(['categoryId' => 'exists']);

        Livewire::test(RecurringForm::class)
            ->set('type', 'transfer')->set('name', '移す')->set('amount', '100')
            ->set('accountId', $this->bank->id)->set('toAccountId', $this->bank->id)
            ->call('save')
            ->assertHasErrors(['toAccountId' => 'different']);
    }

    public function test_相手を選ぶと前回の分類と口座が入る(): void
    {
        $partner = Partner::factory()->create(['name' => '東京電力']);
        $utilities = $this->category('水道・光熱費');
        Record::factory()->create(['partner_id' => $partner->id, 'category_id' => $utilities->id, 'account_id' => $this->bank->id, 'date' => '2026-09-27']);

        Livewire::test(RecurringForm::class)
            ->set('partnerName', '東京')
            ->call('selectPartner', $partner->id)
            ->assertSet('partnerName', '東京電力')
            ->assertSet('categoryId', $utilities->id)
            ->assertSet('accountId', $this->bank->id);
    }

    public function test_記録する日が過ぎた月を最初の月にすると_確認してから今回はなしにする(): void
    {
        // 10月24日に、10月23日に記録する給料を、4 月から登録する
        $component = $this->salary()->set('startMonth', '4')->call('save');

        // まだ保存しない。確認の小窓を開く
        $this->assertSame(0, Recurring::query()->count());
        $component->assertSet('skipNotice', '2026年4月〜10月の分は記録せず、『今回はなし』にします')
            ->assertDispatched('open-modal', id: 'skip-dialog');

        $component->call('save', true)->assertHasNoErrors()->assertRedirect(route('recurrings.index'));

        $recurring = Recurring::query()->sole();
        $this->assertSame('2026-04-01', $recurring->start_month->toDateString());
        $this->assertSame(
            ['2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'],
            $recurring->skips()->orderBy('month')->pluck('month')->map(fn ($m) => substr((string) $m, 0, 10))->all(),
        );
    }

    public function test_選んだ月だけなら今回はなしも選んだ月だけ(): void
    {
        $component = $this->salary()->set('monthsMode', 'selected')->set('months', ['6', '12'])
            ->set('startYear', '2025')->set('startMonth', '1')
            ->call('save');

        $component->assertSet('skipNotice', '2025年6月、12月、2026年6月の分は記録せず、『今回はなし』にします');
    }

    public function test_今日が記録する日の月は今回はなしにしない(): void
    {
        // 今日（10月23日）が記録する日。今日の分は、自動で記録するかたまりで、今日記録する
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));

        $this->salary()->set('startMonth', '10')->call('save')->assertHasNoErrors()->assertNotDispatched('open-modal');

        $this->assertSame(0, RecurringSkip::query()->count());
    }

    public function test_使わないで登録すると今回はなしは作らない(): void
    {
        $this->salary()->set('startMonth', '4')->set('isActive', false)->call('save')->assertHasNoErrors();

        $this->assertSame(0, RecurringSkip::query()->count());
    }

    public function test_金額を変えて続きを作るから開くと_同じ内容で最初の月が最後の月の次の月(): void
    {
        $rent = Recurring::factory()->create([
            'name' => '家賃', 'day' => 27, 'amount' => 70000, 'account_id' => $this->bank->id, 'to_account_id' => $this->card->id,
            'start_month' => '2026-04-01', 'end_month' => '2026-12-01',
        ]);

        Livewire::withQueryParams(['from' => (string) $rent->id])
            ->test(RecurringForm::class)
            ->assertSet('recurringId', null)
            ->assertSet('name', '家賃')
            ->assertSet('type', 'transfer')
            ->assertSet('amount', '70,000')
            ->assertSet('day', '27')
            ->assertSet('accountId', $this->bank->id)
            ->assertSet('toAccountId', $this->card->id)
            ->assertSet('startYear', '2027')
            ->assertSet('startMonth', '1')
            ->assertSet('endYear', '')
            ->set('amount', '72000')
            ->call('save')
            ->assertHasNoErrors();

        $next = Recurring::query()->whereKeyNot($rent->id)->sole();
        $this->assertSame(72_000, $next->amount);
        $this->assertSame('2027-01-01', $next->start_month->toDateString());
    }
}
