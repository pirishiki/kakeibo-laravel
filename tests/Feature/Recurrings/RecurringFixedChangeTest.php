<?php

namespace Tests\Feature\Recurrings;

use App\Livewire\RecurringForm;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 「毎月変わる」から「決まっている」に変えて保存するときの、保存の前の確認（画面設計書 5.10 の「決まり」）。
 * 入れておいた金額が消える・金額がまだだった過ぎた月が決まった金額で記録される、のに黙っていないこと。今日は 2026年10月24日（土）
 */
class RecurringFixedChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    }

    /** 毎月変わる電気代（毎月 5 日）。最初の月は引数で決める */
    private function electric(string $start): Recurring
    {
        return Recurring::factory()->create([
            'name' => '電気代', 'day' => 5, 'start_month' => $start,
            'is_fixed' => false, 'amount' => null, 'estimated_amount' => 8000,
        ]);
    }

    public function test_入れておいた先の月の金額が消えるときは_保存の前に知らせて_確認するまで保存しない(): void
    {
        $electric = $this->electric('2026-11-01');
        RecurringAmount::query()->create(['recurring_id' => $electric->id, 'month' => '2026-12-01', 'amount' => 8300]);

        $form = Livewire::test(RecurringForm::class, ['recurring' => $electric])
            ->set('isFixed', '1')->set('amount', '9000')
            ->call('save')
            ->assertSet('fixedNotice', fn (string $notice) => str_contains($notice, '2026年12月') && str_contains($notice, '8,300') && str_contains($notice, '消えます'))
            ->assertNoRedirect();

        // 確認するまでは、何も変わっていない
        $this->assertFalse($electric->fresh()->is_fixed);
        $this->assertSame(1, RecurringAmount::query()->count());

        $form->call('save', true)->assertRedirect();
        $this->assertTrue($electric->fresh()->is_fixed);
        $this->assertSame(0, RecurringAmount::query()->count());
    }

    public function test_金額がまだだった過ぎた月が決まった金額で記録されるときは_月と金額を保存の前に知らせる(): void
    {
        // 9 月・10 月は 5 日が過ぎているのに、金額が入っていない（ホームの「金額がまだ」に出ていた月）
        $electric = $this->electric('2026-09-01');

        $form = Livewire::test(RecurringForm::class, ['recurring' => $electric])
            ->set('isFixed', '1')->set('amount', '70000')
            ->call('save')
            ->assertSet('fixedNotice', fn (string $notice) => str_contains($notice, '2026年9月') && str_contains($notice, '10月') && str_contains($notice, '70,000') && str_contains($notice, '記録します'))
            ->assertNoRedirect();

        $this->assertSame(0, Record::query()->count());
        $this->assertFalse($electric->fresh()->is_fixed);

        $form->call('save', true)->assertRedirect();
        $this->assertSame(2, Record::query()->count());
        $this->assertSame([70000], Record::query()->pluck('amount')->unique()->values()->all());
    }

    public function test_消える金額も記録される月もないときは_小窓を出さずにそのまま保存する(): void
    {
        $electric = $this->electric('2026-11-01');

        Livewire::test(RecurringForm::class, ['recurring' => $electric])
            ->set('isFixed', '1')->set('amount', '9000')
            ->call('save')
            ->assertSet('fixedNotice', '')
            ->assertRedirect();

        $this->assertTrue($electric->fresh()->is_fixed);
    }

    public function test_小窓を出したあとに入れた金額が増えたら_知らせていない分は黙って消さずに小窓を出し直す(): void
    {
        $electric = $this->electric('2026-11-01');
        RecurringAmount::query()->create(['recurring_id' => $electric->id, 'month' => '2026-12-01', 'amount' => 8300]);

        $form = Livewire::test(RecurringForm::class, ['recurring' => $electric])
            ->set('isFixed', '1')->set('amount', '9000')
            ->call('save');

        // 小窓を出したあとに、ほかの画面で別の月の金額が入った。確認の「保存する」を押しても、知らせていない月があるので保存しない
        RecurringAmount::query()->create(['recurring_id' => $electric->id, 'month' => '2027-01-01', 'amount' => 8400]);
        $form->call('save', true)->assertNoRedirect();

        $this->assertFalse($electric->fresh()->is_fixed);
        $this->assertSame(2, RecurringAmount::query()->count());
    }

    public function test_決まっているから毎月変わるに変えるときは_小窓を出さない(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-11-01']);

        Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('isFixed', '0')->set('estimatedAmount', '9000')
            ->call('save')
            ->assertSet('fixedNotice', '')
            ->assertRedirect();

        $this->assertFalse($rent->fresh()->is_fixed);
    }

    public function test_過ぎた月に金額が入っていて_まだ記録されていないときも_決まった金額で記録されると知らせる(): void
    {
        // 9 月分は 8,300 円が入っているのに、まだ記録されていない（0 時 5 分に動けなかった日など）。10 月分は金額がまだ
        $electric = $this->electric('2026-09-01');
        RecurringAmount::query()->create(['recurring_id' => $electric->id, 'month' => '2026-09-01', 'amount' => 8300]);

        $form = Livewire::test(RecurringForm::class, ['recurring' => $electric])
            ->set('isFixed', '1')->set('amount', '9000')
            ->call('save')
            // 9 月は金額が入っていたので「消えます」にも出るが、9 月も 10 月も決まった金額で記録される、と「記録します」の行にも出る
            ->assertSet('fixedNotice', fn (string $notice) => str_contains($notice, '消えます：2026年9月 8,300')
                && str_contains($notice, '2026年9月〜10月の分は') && str_contains($notice, '9,000') && str_contains($notice, '記録します'))
            ->assertNoRedirect();

        // 入れていた 8,300 円ではなく、決まった 9,000 円で記録される（知らせたとおり）
        $form->call('save', true)->assertRedirect();
        $this->assertSame([9000], Record::query()->pluck('amount')->unique()->values()->all());
    }

    public function test_小窓で確認したあと_保存の途中でほかの画面が金額を入れたら_知らせていない分は消さずに止める(): void
    {
        $electric = $this->electric('2026-11-01');
        RecurringAmount::query()->create(['recurring_id' => $electric->id, 'month' => '2026-12-01', 'amount' => 8300]);

        $form = Livewire::test(RecurringForm::class, ['recurring' => $electric])
            ->set('isFixed', '1')->set('amount', '9000')
            ->call('save');

        // 確認の「保存する」を押したあと、画面での確かめを通り、保存のトランザクションが始まる瞬間に、ほかの画面で金額が入った、を再現する
        $added = false;
        Event::listen(TransactionBeginning::class, function () use (&$added, $electric) {
            if (! $added) {
                $added = true;
                RecurringAmount::query()->create(['recurring_id' => $electric->id, 'month' => '2027-01-01', 'amount' => 8400]);
            }
        });
        $form->call('save', true)->assertHasErrors('save')->assertNoRedirect();

        // 足した金額（2027年1月）は、保存のトランザクションの中で入れたので、止めたときに一緒に取り消される。
        // 止まった理由（知らせていない月があった）が出ていて、小窓で知らせた月の金額も消えていない、ことを確かめる
        $this->assertTrue($added);
        $this->assertSame(['2026-12-01'], RecurringAmount::query()->get()->map(fn ($a) => $a->month->toDateString())->all(), '金額を消していない');
        $this->assertFalse($electric->fresh()->is_fixed, '知らせていない月があるので、保存していない');
    }
}
