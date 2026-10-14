<?php

namespace Tests\Feature\Recurrings;

use App\Enums\RecordType;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\RecurringSkip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 要件定義書 10.2「定期的な記録の登録・変更」のうち、変更・削除と一覧の項目を確かめる（画面設計書 5.10）
 * 今日は 2026年10月24日（土）
 */
class RecurringEditTest extends TestCase
{
    use RefreshDatabase;

    private Recurring $rent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        // 毎月 27 日・次の平日の家賃（移動）。4 月から
        $this->rent = Recurring::factory()->create(['start_month' => '2026-04-01']);
    }

    /** その予定の、この月の分の記録を作る（自動で記録されたことにする） */
    private function recordFor(Recurring $recurring, string $month, int $amount = 70000): Record
    {
        $record = Record::factory()->create([
            'type' => RecordType::Transfer, 'amount' => $amount, 'date' => $month.'-27',
            'account_id' => $recurring->account_id, 'to_account_id' => $recurring->to_account_id,
        ]);
        $record->forceFill(['recurring_id' => $recurring->id, 'recurring_month' => $month.'-01'])->save();

        return $record;
    }

    /**
     * @return list<string>
     */
    private function skippedMonths(Recurring $recurring): array
    {
        return $recurring->skips()->orderBy('month')->get()->map(fn (RecurringSkip $s) => $s->month->format('Y-m'))->all();
    }

    public function test_変更の画面に保存してある内容が出る(): void
    {
        $this->get(route('recurrings.edit', $this->rent))->assertOk()->assertSeeLivewire(RecurringForm::class);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->assertSet('recurringId', $this->rent->id)
            ->assertSet('name', '家賃')
            ->assertSet('type', 'transfer')
            ->assertSet('amount', '70,000')
            ->assertSet('startYear', '2026')
            ->assertSet('startMonth', '4')
            ->assertSee('金額を変えて続きを作る');
    }

    public function test_変更して保存すると_予定の内容が変わり記録は変わらない(): void
    {
        $record = $this->recordFor($this->rent, '2026-09');

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('amount', '72000')
            ->set('day', '25')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame(72_000, $this->rent->refresh()->amount);
        $this->assertSame(25, $this->rent->day);
        $this->assertSame(70_000, $record->refresh()->amount, '記録された月の記録は変わらない');
    }

    public function test_変更の画面では最初の月を書きかえない(): void
    {
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])->set('day', '5')->assertSet('startMonth', '4');
    }

    public function test_使わないから使うに戻すと_過ぎた月は確認してから今回はなし(): void
    {
        $this->rent->update(['is_active' => false]);
        // 4〜6 月は記録済み、7 月はもう今回はなし
        $this->recordFor($this->rent, '2026-04');
        $this->recordFor($this->rent, '2026-05');
        $this->recordFor($this->rent, '2026-06');
        RecurringSkip::query()->create(['recurring_id' => $this->rent->id, 'month' => '2026-07-01']);

        $component = Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('isActive', true)
            ->call('save');

        // 10 月の分（10月27日）は、まだ来ていないので入らない
        $component->assertSet('skipNotice', '2026年8月〜9月の分は記録せず、『今回はなし』にします');
        $this->assertFalse($this->rent->refresh()->is_active, '確認するまでは保存しない');

        $component->call('save', true)->assertHasNoErrors();

        $this->assertTrue($this->rent->refresh()->is_active);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $this->skippedMonths($this->rent));
    }

    public function test_使うに戻すとき_使わないの口座のままでは保存できない(): void
    {
        // 予定を止めてから口座を「使わない」にした（止めている予定は、口座を「使わない」にするのを止めない）
        $this->rent->update(['is_active' => false]);
        Account::query()->whereKey($this->rent->account_id)->update(['is_active' => false]);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('isActive', true)
            ->call('save', true)
            ->assertHasErrors(['accountId' => 'exists']);
        $this->assertFalse($this->rent->refresh()->is_active);

        // 止めたままなら、名前などは直せる
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('name', '家賃（止めた）')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_毎月にすると_選んでいなかった過ぎた月だけが今回はなし(): void
    {
        // 6 月と 9 月だけの予定。6 月は記録済み、9 月はもう今回はなし
        $this->rent->update(['months' => [6, 9]]);
        $this->recordFor($this->rent, '2026-06');
        RecurringSkip::query()->create(['recurring_id' => $this->rent->id, 'month' => '2026-09-01']);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('monthsMode', 'every')
            ->call('save')
            ->assertSet('skipNotice', '2026年4月〜5月、7月〜8月の分は記録せず、『今回はなし』にします')
            ->call('save', true);

        $this->assertSame(['2026-04', '2026-05', '2026-07', '2026-08', '2026-09'], $this->skippedMonths($this->rent));
    }

    public function test_記録済みの月は_使うに戻しても今回はなしを入れない(): void
    {
        $this->rent->update(['is_active' => false]);
        foreach (['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'] as $month) {
            $this->recordFor($this->rent, $month);
        }

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('isActive', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotDispatched('open-modal')
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame([], $this->skippedMonths($this->rent));
    }

    public function test_年は選ぶ欄に出ている年だけ(): void
    {
        // 送る値を書きかえて、ずっと前の年から始めることはできない
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('startYear', '2000')
            ->call('save')
            ->assertHasErrors('startYear');
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('endYear', '2100')->set('endMonth', '1')
            ->call('save')
            ->assertHasErrors('endYear');

        // 開いたときの年は、範囲の外でも選べる（ずっと前に始めた予定）
        $old = Recurring::factory()->create(['start_month' => '2020-01-01', 'is_active' => false]);
        Livewire::test(RecurringForm::class, ['recurring' => $old])
            ->assertSee('<option value="2020">2020年</option>', false)
            ->set('name', '古い予定')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_最後の月を延ばすと_のびた過ぎた月が今回はなし(): void
    {
        $this->rent->update(['end_month' => '2026-06-01']);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('endYear', '2026')->set('endMonth', '12')
            ->call('save')
            ->assertSet('skipNotice', '2026年7月〜9月の分は記録せず、『今回はなし』にします')
            ->call('save', true);

        $this->assertSame(['2026-07', '2026-08', '2026-09'], $this->skippedMonths($this->rent));
    }

    public function test_最初の月を前にすると_前の過ぎた月が今回はなし(): void
    {
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('startMonth', '1')
            ->call('save')
            ->assertSet('skipNotice', '2026年1月〜3月の分は記録せず、『今回はなし』にします')
            ->call('save', true);

        $this->assertSame(['2026-01', '2026-02', '2026-03'], $this->skippedMonths($this->rent));
    }

    public function test_もとから対象の月は_名前や金額を直しても今回はなしにしない(): void
    {
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('name', '家賃（新）')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotDispatched('open-modal')
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame([], $this->skippedMonths($this->rent));
    }

    public function test_毎月変わるから決まっているに変えると_記録されていない月の金額は消え_今回はなしは残る(): void
    {
        $this->rent->update(['is_fixed' => false, 'amount' => null, 'estimated_amount' => 9000]);
        $this->recordFor($this->rent, '2026-09', 9200);
        RecurringAmount::query()->create(['recurring_id' => $this->rent->id, 'month' => '2026-09-01', 'amount' => 9200]);
        RecurringAmount::query()->create(['recurring_id' => $this->rent->id, 'month' => '2026-11-01', 'amount' => 9500]);
        RecurringSkip::query()->create(['recurring_id' => $this->rent->id, 'month' => '2026-12-01']);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->set('isFixed', '1')
            ->set('amount', '9000')
            ->call('save')
            ->assertHasNoErrors();

        $this->rent->refresh();
        $this->assertTrue($this->rent->is_fixed);
        $this->assertNull($this->rent->estimated_amount);
        $this->assertSame(['2026-09'], $this->rent->amounts()->get()->map(fn ($a) => $a->month->format('Y-m'))->all(), '記録済みの月の行は残る');
        $this->assertSame(['2026-12'], $this->skippedMonths($this->rent));
    }

    public function test_金額を変えて続きを作るは_最後の月が保存されていないと移らない(): void
    {
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            // 欄に入れただけで、まだ保存していない
            ->set('endYear', '2026')->set('endMonth', '12')
            ->call('continueWithNewAmount')
            ->assertHasErrors('continue')
            ->assertSee('先に最後の月を入れて保存してください')
            ->assertNoRedirect();

        $this->rent->update(['end_month' => '2026-12-01']);
        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->call('continueWithNewAmount')
            ->assertRedirect(route('recurrings.create', ['from' => $this->rent->id]));
    }

    public function test_金額を変えて続きを作るは_金額が決まっている予定にだけ出る(): void
    {
        $this->rent->update(['is_fixed' => false, 'amount' => null, 'estimated_amount' => 9000]);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])->assertDontSee('金額を変えて続きを作る');
        Livewire::test(RecurringForm::class)->assertDontSee('金額を変えて続きを作る');
    }

    public function test_記録がない予定は_月ごとの金額と今回はなしといっしょに削除できる(): void
    {
        RecurringAmount::query()->create(['recurring_id' => $this->rent->id, 'month' => '2026-11-01', 'amount' => 70000]);
        RecurringSkip::query()->create(['recurring_id' => $this->rent->id, 'month' => '2026-12-01']);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->assertSee('「家賃」を削除しますか？')
            ->call('delete')
            ->assertRedirect(route('recurrings.index'));

        $this->assertSame(0, Recurring::query()->count());
        $this->assertSame(0, RecurringAmount::query()->count());
        $this->assertSame(0, RecurringSkip::query()->count());
    }

    public function test_記録がある予定は削除できず_使わないにするかを聞く(): void
    {
        $this->recordFor($this->rent, '2026-09');

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->assertSee('記録があるので削除できません。『使わない』にしますか？')
            ->call('delete')
            ->assertHasErrors('delete')
            ->assertNoRedirect();
        $this->assertTrue(Recurring::query()->whereKey($this->rent->id)->exists());

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])
            ->call('deactivate')
            ->assertRedirect(route('recurrings.index'));
        $this->assertFalse($this->rent->refresh()->is_active);
    }

    public function test_変更している予定は画面の側から変えられない(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(RecurringForm::class, ['recurring' => $this->rent])->set('recurringId', 999);
    }

    public function test_一覧は日付の早い順で_終わったものと使わないものは下にまとめる(): void
    {
        $this->rent->update(['name' => '家賃（12月まで）', 'end_month' => '2026-12-01']);
        Recurring::factory()->create(['name' => '家賃（1月から）', 'start_month' => '2027-01-01', 'amount' => 72000]);
        Recurring::factory()->create(['name' => 'カードの引き落とし', 'day' => 15, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 52000]);
        Recurring::factory()->create(['name' => '給料', 'type' => RecordType::Income, 'day' => 25, 'months' => [6, 12], 'shift' => 'previous', 'to_account_id' => null]);
        Recurring::factory()->create(['name' => 'ジムの会費', 'day' => 1, 'is_active' => false]);
        Recurring::factory()->create(['name' => '古いローン', 'day' => 1, 'start_month' => '2025-01-01', 'end_month' => '2026-09-01']);

        $this->get(route('recurrings.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'カードの引き落とし', '毎月15日（次の平日）・移動', '金額が変わる（概算 52,000）',
                '給料', '6月・12月の25日（前の平日）・収入',
                '家賃（12月まで）', '毎月27日（次の平日）・移動・70,000', '2026年12月まで',
                '家賃（1月から）', '2027年1月から',
                '＋ 定期的な記録を登録する',
                // 下のまとまりも同じ並べ方（同じ日付なら、最初の月が早い順）
                '終わったもの・使わないもの', '古いローン', '2026年9月まで', 'ジムの会費（使わない）',
            ]);
    }

    public function test_まだないときは次にすることを出す(): void
    {
        Recurring::query()->delete();

        $this->get(route('recurrings.index'))->assertSee('まだ定期的な記録がありません');
    }
}
