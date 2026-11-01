<?php

namespace Tests\Feature\Recurrings;

use App\Livewire\RecurringForm;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\RecurringSkip;
use App\Models\User;
use App\Support\RecurringMonthly;
use App\Support\RecurringRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 0 時 5 分の自動の記録・月ごとの金額の保存が、予定を読んだあとに、ほかの画面で予定が直されたときの確かめ直し
 * （データベース設計書 3.6・3.7、画面設計書 5.10・5.12）。今日は 2026年10月24日（土）
 *
 * 読んだ予定（$stale）は古い中身。予定の行を押さえたあとの、いちばん新しい中身で確かめて、
 * 「使わない」にされた・最後の月が前になった・日付が先になった予定を、記録しないこと
 */
class RecurringRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    }

    public function test_読んだあとに予定が使わないにされたら_自動の記録は記録しない(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-09-01']);
        $stale = Recurring::query()->findOrFail($rent->id);
        $rent->update(['is_active' => false]);

        $this->assertSame(0, RecurringRecorder::recordDue($stale));
        $this->assertSame(0, Record::query()->count());
    }

    public function test_読んだあとに最後の月が前にされたら_最後の月より後の月は記録しない(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-08-01']);
        $stale = Recurring::query()->findOrFail($rent->id);
        $rent->update(['end_month' => '2026-08-01']);

        // 読んだ時点では 8 月・9 月の 2 件。最後の月が 8 月になったので、記録するのは 8 月の分だけ
        $this->assertSame(1, RecurringRecorder::recordDue($stale));
        $this->assertSame('2026-08-01', Record::query()->sole()->recurring_month->toDateString());
    }

    public function test_読んだあとに日付が先に直されたら_まだ来ていない日付の分は記録しない(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-10-01', 'day' => 5]);
        $stale = Recurring::query()->findOrFail($rent->id);
        // 今日は 24 日。28 日に直されたので、10 月の分はまだ記録しない
        $rent->update(['day' => 28]);

        $this->assertSame(0, RecurringRecorder::recordDue($stale));
        $this->assertSame(0, Record::query()->count());
    }

    public function test_読んだあとに変わっていなければ_ふつうに記録する(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-09-01']);
        $stale = Recurring::query()->findOrFail($rent->id);

        $this->assertSame(1, RecurringRecorder::recordDue($stale));
        $this->assertSame(1, Record::query()->count());
    }

    public function test_月ごとの金額を保存するとき_読んだあとに決まっている金額に直された予定には_金額の行を作らない(): void
    {
        $electric = Recurring::factory()->create([
            'start_month' => '2026-11-01', 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 8000,
        ]);
        $stale = Recurring::query()->findOrFail($electric->id);
        // 別の画面で「決まっている」に直された（金額の行は残らない）
        $electric->update(['is_fixed' => true, 'amount' => 8000]);

        $saved = RecurringMonthly::saveAmount($stale, CarbonImmutable::create(2026, 11, 1), 5000);

        $this->assertFalse($saved);
        $this->assertSame(0, RecurringAmount::query()->count());
    }

    public function test_月ごとの金額を保存するとき_読んだあとに日付が先に直されたら_まだ来ていない日付の分は記録しない(): void
    {
        $electric = Recurring::factory()->create([
            'start_month' => '2026-10-01', 'day' => 5, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 8000,
        ]);
        $stale = Recurring::query()->findOrFail($electric->id);
        // 今日は 24 日。別の画面で 28 日に直された
        $electric->update(['day' => 28]);

        RecurringMonthly::saveAmount($stale, CarbonImmutable::create(2026, 10, 1), 5000);

        // 金額は入る（月ごとの金額）が、日付（28 日）がまだ来ていないので、記録はしない
        $this->assertSame(1, RecurringAmount::query()->count());
        $this->assertSame(0, Record::query()->count());
    }

    public function test_今回はなしをもどすとき_読んだあとに日付が先に直されたら_まだ来ていない日付の分は記録しない(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-10-01', 'day' => 5]);
        RecurringSkip::query()->create(['recurring_id' => $rent->id, 'month' => '2026-10-01']);
        $stale = Recurring::query()->findOrFail($rent->id);
        // 今日は 24 日。別の画面で 28 日に直された
        $rent->update(['day' => 28]);

        $recorded = RecurringMonthly::restore($stale, CarbonImmutable::create(2026, 10, 1));

        // 今回はなしは外れるが、日付（28 日）がまだ来ていないので、記録はしない
        $this->assertFalse($recorded);
        $this->assertSame(0, RecurringSkip::query()->count());
        $this->assertSame(0, Record::query()->count());
    }

    public function test_月ごとの金額を保存したあと_決まっている金額に直されていたら_入れた金額は使っていないと知らせる(): void
    {
        $electric = Recurring::factory()->create([
            'name' => '電気代', 'start_month' => '2026-11-01', 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 8000,
        ]);
        $stale = Recurring::query()->findOrFail($electric->id);
        $electric->update(['is_fixed' => true, 'amount' => 8000]);

        $problem = (string) RecurringMonthly::recordProblem($stale, CarbonImmutable::create(2026, 11, 1), 5000);

        $this->assertStringContainsString('電気代', $problem);
        $this->assertStringContainsString('金額が決まっている予定', $problem);
    }

    public function test_変更の画面を開いたあとに_自動の記録と今回はなしと月ごとの金額が入っても_まちがって止まらず保存できる(): void
    {
        // 「ほかの画面で直された」と見なすのは、予定そのものの中身が変わったときだけ。
        // 予定の中身を変えない操作（自動の記録・今回はなし・月ごとの金額）で、保存が止まってはいけない
        $rent = Recurring::factory()->create(['start_month' => '2026-09-01']);
        $electric = Recurring::factory()->create([
            'name' => '電気代', 'start_month' => '2026-11-01', 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 8000,
        ]);
        $rentForm = Livewire::test(RecurringForm::class, ['recurring' => $rent]);
        $electricForm = Livewire::test(RecurringForm::class, ['recurring' => $electric]);

        RecurringRecorder::recordDue($rent);
        RecurringSkip::query()->create(['recurring_id' => $rent->id, 'month' => '2026-11-01']);
        RecurringMonthly::saveAmount($electric, CarbonImmutable::create(2026, 11, 1), 8300);

        $rentForm->set('name', '家賃（新）')->call('save')->assertHasNoErrors()->assertRedirect();
        $electricForm->set('name', '電気代（新）')->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame('家賃（新）', $rent->fresh()?->name);
        $this->assertSame('電気代（新）', $electric->fresh()?->name);
    }
}
