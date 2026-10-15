<?php

namespace Tests\Feature\Recurrings;

use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Livewire\MonthlyAmounts;
use App\Livewire\PendingAmounts;
use App\Livewire\RecordForm;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\User;
use App\Support\RecurringRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 要件定義書 10.2「月ごとの金額・今回はなし」「ホームのお知らせ」のうち、画面の項目を確かめる（画面設計書 5.10・5.2）
 * 出すものの決まりそのものは RecurringMonthlySpecTest で確かめる。今日は 2026年10月24日（土）
 */
class MonthlyAmountsTest extends TestCase
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

    public function test_開いたときは今月で_予定のいちばん早い最初の月から12か月先まで切りかえられる(): void
    {
        $this->get(route('recurrings.index'))->assertOk()
            ->assertSee('2026年10月')
            ->assertSee(route('recurrings.index', ['month' => '2026-09']), false)
            ->assertSee(route('recurrings.index', ['month' => '2026-11']), false);

        // 9 月より前には戻れない
        $this->get(route('recurrings.index', ['month' => '2026-09']))->assertOk()
            ->assertDontSee(route('recurrings.index', ['month' => '2026-08']), false);
        // 12 か月先（2027年10月）より先へは進めない。範囲の外の月を開くと、いちばん近い月を出す
        $this->get(route('recurrings.index', ['month' => '2030-01']))->assertOk()
            ->assertSee('2027年10月')
            ->assertDontSee(route('recurrings.index', ['month' => '2027-11']), false);
    }

    public function test_決まった金額の行と金額が変わる行が出る(): void
    {
        Livewire::test(MonthlyAmounts::class, ['month' => $this->october()])
            ->assertSeeInOrder(['電気代', '概算 9,000', '家賃', '70,000', '決まった金額'])
            ->assertSee('10月20日（火）');
    }

    public function test_日付が過ぎた月に金額を入れて保存すると_予定の日付で記録される(): void
    {
        Livewire::test(MonthlyAmounts::class, ['month' => $this->october()])
            ->set('amounts.'.$this->power->id, '９，２００')
            ->call('save')
            ->assertRedirect(route('recurrings.index', ['month' => '2026-10']));

        $record = Record::query()->where('recurring_id', $this->power->id)->sole();
        $this->assertSame('2026-10-20', $record->date->toDateString());
        $this->assertSame(9200, $record->amount);
        $this->assertSame('保存しました', session('status'));
    }

    public function test_来月の分を先に入れると_記録はせず_金額だけ保存する(): void
    {
        Livewire::test(MonthlyAmounts::class, ['month' => CarbonImmutable::create(2026, 11, 1)])
            ->set('amounts.'.$this->power->id, '8500')
            ->call('save');

        $this->assertSame(0, Record::query()->where('recurring_id', $this->power->id)->count());
        $this->assertSame(8500, (int) RecurringAmount::query()->sole()->amount);
    }

    public function test_空にして保存すると金額がまだに戻る(): void
    {
        RecurringAmount::query()->create(['recurring_id' => $this->power->id, 'month' => '2026-11-01', 'amount' => 8500]);

        Livewire::test(MonthlyAmounts::class, ['month' => CarbonImmutable::create(2026, 11, 1)])
            ->assertSet('amounts.'.$this->power->id, '8,500')
            ->set('amounts.'.$this->power->id, '')
            ->call('save');

        $this->assertSame(0, RecurringAmount::query()->count());
    }

    public function test_開いたままの古い画面で保存しても_ほかの画面で入れた金額は消さない(): void
    {
        $november = CarbonImmutable::create(2026, 11, 1);
        $old = Livewire::test(MonthlyAmounts::class, ['month' => $november]);
        // 別の画面で、11 月の電気代を入れておく
        RecurringAmount::query()->create(['recurring_id' => $this->power->id, 'month' => '2026-11-01', 'amount' => 8500]);

        $old->call('save');

        $this->assertSame(8500, (int) RecurringAmount::query()->sole()->amount);
    }

    public function test_金額は1円以上の整数(): void
    {
        Livewire::test(MonthlyAmounts::class, ['month' => $this->october()])
            ->set('amounts.'.$this->power->id, '0')
            ->call('save')
            ->assertHasErrors(['amounts.'.$this->power->id => 'min'])
            ->set('amounts.'.$this->power->id, 'abc')
            ->call('save')
            ->assertHasErrors(['amounts.'.$this->power->id => 'integer'])
            ->assertSee('金額は、数字だけで入れてください。');
    }

    public function test_記録済みの行は金額と記録済みだけが出て_記録の編集へ行ける(): void
    {
        RecurringRecorder::recordDue($this->rent);
        $record = Record::query()->where('recurring_month', '2026-09-01')->sole();
        $record->update(['amount' => 71000]);

        Livewire::test(MonthlyAmounts::class, ['month' => CarbonImmutable::create(2026, 9, 1)])
            ->assertSee('71,000')
            ->assertSee('記録済み')
            ->assertSee(route('records.edit', $record), false)
            ->assertDontSee('今回はなし');
    }

    public function test_今回はなしともどす_もどすと日付が過ぎていればすぐに記録する(): void
    {
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);

        // 家賃（10月27日）は記録される前なので、今回はなしにできる
        $component->call('skip', $this->rent->id)->assertSee('もどす');
        $this->assertSame(1, $this->rent->skips()->count());

        $component->call('restore', $this->rent->id);
        $this->assertSame(0, $this->rent->skips()->count());
        // 10月27日はまだ来ていないので、記録しない
        $this->assertSame(0, Record::query()->count());
    }

    public function test_使わないの予定でも_過ぎた月の今回はなしをもどすと記録される(): void
    {
        $this->rent->skips()->create(['month' => '2026-09-01']);
        $this->rent->update(['is_active' => false]);

        Livewire::test(MonthlyAmounts::class, ['month' => CarbonImmutable::create(2026, 9, 1)])
            ->assertSeeInOrder(['家賃', '（使わない）', '9月28日（月）'])
            ->call('restore', $this->rent->id);

        $record = Record::query()->sole();
        $this->assertSame('2026-09-28', $record->date->toDateString());
        $this->assertSame(RecordType::Transfer, $record->type);
    }

    public function test_ずらしたときは理由が出て_もとの予定の月に出る(): void
    {
        // 毎月末・次の平日。2026年10月31日（土）→ 11月2日（月）
        Recurring::factory()->create(['name' => '月末の積立', 'day' => 31, 'shift' => RecurringShift::Next, 'start_month' => '2026-10-01']);

        Livewire::test(MonthlyAmounts::class, ['month' => $this->october()])
            ->assertSeeInOrder(['月末の積立', '11月2日（月）', '（31日が土曜のため次の平日）']);
    }

    public function test_予定のない月は案内が出る(): void
    {
        $this->rent->update(['end_month' => '2026-10-01']);
        $this->power->update(['end_month' => '2026-10-01']);

        Livewire::test(MonthlyAmounts::class, ['month' => CarbonImmutable::create(2026, 12, 1)])
            ->assertSee('この月の予定はありません');
    }

    public function test_ホームに金額がまだのお知らせが出て_入れたものだけ保存し記録する(): void
    {
        // 9 月から始まる給料（25日・前の平日、金額が変わる）：9月25日（金）・10月23日（金）の分がまだ
        $salary = Recurring::factory()->create([
            'name' => '給料', 'type' => RecordType::Transfer, 'day' => 25, 'shift' => RecurringShift::Previous,
            'is_fixed' => false, 'amount' => null, 'estimated_amount' => 250000, 'start_month' => '2026-09-01',
        ]);

        $this->get(route('home'))->assertOk()->assertSee('金額がまだのものが 3 件あります');

        $component = Livewire::test(PendingAmounts::class)
            // 過ぎた月の分（9 月）が、印つきでいちばん上
            ->assertSeeInOrder(['9月の分', '給料', '電気代', '給料']);

        $component->set('amounts.'.PendingAmounts::key($salary, CarbonImmutable::create(2026, 9, 1)), '248,000')
            ->call('save')
            ->assertRedirect(route('home'));

        $record = Record::query()->sole();
        $this->assertSame('2026-09-25', $record->date->toDateString());
        $this->assertSame(248000, $record->amount);
        // 空の欄は保存しない
        $this->assertSame(1, RecurringAmount::query()->count());
    }

    public function test_お知らせの今回はなしで_その予定はお知らせから消え_なくなるとカードごと出ない(): void
    {
        $component = Livewire::test(PendingAmounts::class)->assertSee('金額がまだのものが 1 件あります');

        // 使える額・カレンダーも変わるので、ホームを出し直す
        $component->call('skip', PendingAmounts::key($this->power, $this->october()))
            ->assertRedirect(route('home'));
        $this->assertSame(1, $this->power->skips()->count());
        $this->get(route('home'))->assertDontSee('お知らせ');
    }

    public function test_お知らせの今回はなしのあとも_ほかの欄に打ちかけた金額は消えず_開いたまま出る(): void
    {
        $gas = Recurring::factory()->create([
            'name' => 'ガス代', 'day' => 15, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 4000, 'start_month' => '2026-10-01',
        ]);
        $gasKey = PendingAmounts::key($gas, $this->october());

        Livewire::test(PendingAmounts::class)
            ->set('amounts.'.$gasKey, '4,100')
            ->call('skip', PendingAmounts::key($this->power, $this->october()))
            ->assertRedirect(route('home'));

        // 次に開いたホームでは、ガス代の欄に 4,100 が残り、欄は開いたまま
        Livewire::test(PendingAmounts::class)
            ->assertSet('amounts.'.$gasKey, '4,100')
            ->assertSet('open', true)
            ->assertSee('金額がまだのものが 1 件あります');
    }

    public function test_開いたあとに自動で記録された行の金額を直して保存すると_使わなかったことを知らせる(): void
    {
        // 10 月の電気代は 9,200 で入れてあり、日付（10月20日）は過ぎている。開いたあとに自動で記録されたことにする
        RecurringAmount::query()->create(['recurring_id' => $this->power->id, 'month' => '2026-10-01', 'amount' => 9200]);
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);
        RecurringRecorder::recordDue($this->power);

        $component->set('amounts.'.$this->power->id, '9,500')
            ->call('save')
            ->assertNoRedirect()
            ->assertSee('「電気代」は、開いたあとに記録されたか『今回はなし』になっていたので、入れた金額は使っていません。');
        $this->assertSame(9200, Record::query()->where('recurring_id', $this->power->id)->sole()->amount);
    }

    public function test_開いたあとに記録された行の今回はなしを押すと_できなかったことを知らせる(): void
    {
        RecurringAmount::query()->create(['recurring_id' => $this->power->id, 'month' => '2026-10-01', 'amount' => 9200]);
        $component = Livewire::test(MonthlyAmounts::class, ['month' => $this->october()]);
        RecurringRecorder::recordDue($this->power);

        $component->call('skip', $this->power->id)
            ->assertSee('「電気代」は、開いたあとに記録されたか、ほかの画面で変わっていたので、今回はなしにできませんでした。')
            ->assertSee('記録済み');
        $this->assertSame(0, $this->power->skips()->count());
    }

    public function test_お知らせで_開いたあとに記録された予定の今回はなしを押すと_知らせる(): void
    {
        $component = Livewire::test(PendingAmounts::class);
        // 別の画面で金額を入れて、記録された
        RecurringAmount::query()->create(['recurring_id' => $this->power->id, 'month' => '2026-10-01', 'amount' => 9200]);
        RecurringRecorder::recordDue($this->power);

        $component->call('skip', PendingAmounts::key($this->power, $this->october()))->assertRedirect(route('home'));
        // 出し直したホームで知らせる
        Livewire::test(PendingAmounts::class)
            ->assertSee('「電気代」は、開いたあとに記録されたか『今回はなし』になっていたので、今回はなしにはしていません。');
        $this->assertSame(0, $this->power->skips()->count());
        $this->assertSame(1, Record::query()->count());
    }

    public function test_お知らせで_開いたあとに消えた予定に金額を入れて保存すると_知らせる(): void
    {
        $component = Livewire::test(PendingAmounts::class);
        $this->power->skips()->create(['month' => '2026-10-01']);

        $component->set('amounts.'.PendingAmounts::key($this->power, $this->october()), '9,300')
            ->call('save')
            ->assertNoRedirect()
            ->assertSee('「電気代」は、開いたあとに記録されたか『今回はなし』になっていたので、入れた金額は使っていません。');
        $this->assertSame(0, RecurringAmount::query()->count());
    }

    public function test_候補を出したあとに使わないになった相手を押すと_選べない理由を出す(): void
    {
        $partner = Partner::query()->create(['name' => 'ローソン']);
        $component = Livewire::test(RecordForm::class)->set('partnerName', 'ロー');
        $partner->update(['is_active' => false]);

        $component->call('selectPartner', $partner->id)
            ->assertSet('partnerId', null)
            ->assertHasErrors('partnerName')
            ->assertSee(Partner::UNAVAILABLE_MESSAGE);
    }
}
