<?php

namespace Tests\Feature\Recurrings;

use App\Enums\RecordType;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\User;
use App\Support\RecurringRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 要件定義書 10.2「自動で記録する」のうち、画面とコマンドの項目を確かめる（画面設計書 5.3・5.4・5.10）
 * 記録する月の決まりそのものは RecurringRecorderSpecTest で確かめる。今日は 2026年10月24日（土）
 */
class RecurringAutoRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    }

    /** 10 月から始まる、毎月 27 日・次の平日の家賃（移動・決まった金額 70,000 円） */
    private function rent(array $values = []): Recurring
    {
        return Recurring::factory()->create(['start_month' => '2026-10-01', ...$values]);
    }

    public function test_コマンドは_日付が来た分を記録して件数を出す(): void
    {
        // 9 月から。9月27日（日）→ 9月28日（月）の分だけ日付が来ている
        $rent = $this->rent(['start_month' => '2026-09-01']);

        $this->artisan('recurrings:record')->expectsOutput('1 件を記録しました')->assertSuccessful();
        $this->artisan('recurrings:record')->expectsOutput('記録するものはありませんでした')->assertSuccessful();

        $record = Record::query()->sole();
        $this->assertSame('2026-09-28', $record->date->toDateString());
        $this->assertSame($rent->id, $record->recurring_id);
        $this->assertSame('2026-09-01', $record->recurring_month?->toDateString());
    }

    public function test_スケジューラーは毎日0時5分に_重ねずに動かす(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'recurrings:record'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt, '途中で止まったときの鍵は 60 分で切れる');
    }

    public function test_今日が記録する日の予定を保存すると_その場で記録される(): void
    {
        // 10月23日（金）が記録する日。その日の 0 時 5 分よりあとに、使わないから使うに戻す
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
        $rent = $this->rent(['day' => 23, 'is_active' => false]);

        Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('isActive', true)
            ->call('save')
            ->assertNotDispatched('open-modal')
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame('2026-10-23', Record::query()->sole()->date->toDateString());
        $this->assertSame('保存しました。日付が来ている分を記録しました', session('status'));
    }

    public function test_日付を過ぎた日に直すと_小窓で聞き_記録するを選ぶとその日で記録する(): void
    {
        $rent = $this->rent();

        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('day', '5')
            ->call('save')
            ->assertDispatched('open-modal', id: 'skip-dialog')
            ->assertSet('passedNotice', '10月の分（10月5日）は、日付がもう過ぎています。')
            ->assertSet('passedRecordLabel', '10月5日で記録する');
        $this->assertSame(27, $rent->fresh()?->day, '小窓を出したときは、まだ保存しない');

        $form->call('save', true, 'record')->assertRedirect(route('recurrings.index'));

        $record = Record::query()->sole();
        $this->assertSame('2026-10-05', $record->date->toDateString());
        $this->assertSame(0, $rent->skips()->count());
    }

    public function test_日付を過ぎた日に直して_今回はなしを選ぶと記録しない(): void
    {
        $rent = $this->rent();

        Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('day', '5')
            ->call('save', true, 'skip')
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame(0, Record::query()->count());
        $this->assertSame(['2026-10-01'], $rent->skips()->pluck('month')->map(fn ($m) => CarbonImmutable::parse($m)->toDateString())->all());
    }

    public function test_金額が変わる予定で金額がまだなら_日付を過ぎた日に直しても小窓を出さない(): void
    {
        $rent = $this->rent(['is_fixed' => false, 'amount' => null, 'estimated_amount' => 9000]);

        Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('day', '5')
            ->call('save')
            ->assertNotDispatched('open-modal')
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame(0, Record::query()->count());
    }

    public function test_もう日付が過ぎていた月は_日付を直しても小窓を出さない(): void
    {
        // 20日の予定（10月20日（火）に記録済み）を 5日に直す
        $rent = $this->rent(['day' => 20]);
        RecurringRecorder::recordDue($rent);

        Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('day', '5')
            ->call('save')
            ->assertNotDispatched('open-modal')
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame(1, Record::query()->count());
    }

    public function test_自動の記録を削除すると_小窓で知らせ_その月は今回はなしになる(): void
    {
        $rent = $this->rent(['day' => 20]);
        RecurringRecorder::recordDue($rent);
        $record = Record::query()->sole();

        Livewire::test(RecordForm::class, ['record' => $record])
            ->assertSee('10月の家賃は『今回はなし』になり、自動では記録されません')
            ->call('delete');

        $this->assertSame(0, Record::query()->count());
        $this->assertSame(1, $rent->skips()->where('month', '2026-10-01')->count());
        // 次に自動の仕組みが動いても、もう記録しない
        $this->assertSame(0, RecurringRecorder::recordAllDue());
        // 決まった金額の予定は、月ごとの金額を使わない
        $this->assertSame(0, RecurringAmount::query()->count());
    }

    public function test_金額が変わる予定の自動の記録を削除すると_直したあとの金額を月ごとの金額に書き写す(): void
    {
        $bill = $this->rent(['day' => 20, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 9000]);
        RecurringAmount::query()->create(['recurring_id' => $bill->id, 'month' => '2026-10-01', 'amount' => 9200]);
        RecurringRecorder::recordDue($bill);
        $record = Record::query()->sole();
        $record->update(['amount' => 9500]);

        Livewire::test(RecordForm::class, ['record' => $record])->call('delete');

        $this->assertSame(9500, (int) RecurringAmount::query()->where('recurring_id', $bill->id)->sole()->amount);
        $this->assertSame(1, $bill->skips()->count());
    }

    public function test_手で入れた記録を削除しても_今回はなしは作らない(): void
    {
        $record = Record::factory()->create();

        Livewire::test(RecordForm::class, ['record' => $record])
            ->assertDontSee('今回はなし')
            ->call('delete');

        $this->assertSame(0, Record::query()->count());
        $this->assertDatabaseCount('recurring_skips', 0);
    }

    public function test_記録の一覧で_自動の記録には自動と出て_直しても残る(): void
    {
        $rent = $this->rent(['day' => 20]);
        RecurringRecorder::recordDue($rent);
        Record::query()->sole()->update(['amount' => 71000]);
        Record::factory()->create(['date' => '2026-10-21', 'type' => RecordType::Transfer]);

        $this->get(route('records.index', ['month' => '2026-10']))
            ->assertOk()
            ->assertSee('71,000');
        // 自動の印は、自動の記録の 1 件だけ
        $this->assertSame(1, substr_count((string) $this->get(route('records.index', ['month' => '2026-10']))->getContent(), '">自動</span>'));
    }
}
