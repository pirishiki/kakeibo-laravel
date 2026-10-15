<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Livewire\MonthlyAmounts;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use App\Support\RecurringRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * まれでも起きると困ること（お金がずれる・入力が黙って消える・エラーの画面）を確かめる（2026-10-15 の見直し）
 * 別のタブ・開いたままの古い画面・0 時 5 分の自動の記録と重なるときなど。今日は 2026年10月24日（土）
 */
class RareCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    }

    /** 移動の記録（分類・相手のいらない形） */
    private function transfer(int $amount): Record
    {
        return Record::factory()->create(['type' => RecordType::Transfer, 'amount' => $amount, 'to_account_id' => Account::factory()->create()->id]);
    }

    public function test_使わない口座には_もどすでも記録せず_理由を出す(): void
    {
        // 9 月の家賃を今回はなしにして、予定も口座も「使わない」にした
        $rent = Recurring::factory()->create(['start_month' => '2026-09-01', 'is_active' => false]);
        $rent->skips()->create(['month' => '2026-09-01']);
        $rent->account->update(['is_active' => false]);

        Livewire::test(MonthlyAmounts::class, ['month' => CarbonImmutable::create(2026, 9, 1)])
            ->call('restore', $rent->id)
            ->assertSee('が『使わない』なので、「家賃」を記録できません。先に口座を『使う』に戻してください。');

        $this->assertSame(0, Record::query()->count());
        $this->assertSame(0, $rent->account->balance());
    }

    public function test_自動の記録は_使わない口座の予定を記録せずログに残す(): void
    {
        $rent = Recurring::factory()->create(['start_month' => '2026-09-01']);
        $rent->toAccount?->update(['is_active' => false]);
        Log::shouldReceive('warning')->once();

        $this->assertSame(0, RecurringRecorder::recordAllDue());
        $this->assertSame(0, Record::query()->count());
    }

    public function test_最後の月の分がまだ記録されていない予定の口座は_使わないにできない(): void
    {
        // 毎月末・次の平日、最後の月が 10 月。10月31日（土）→ 11月2日（月）に記録する。11月1日（日）の時点
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
        $account = Account::factory()->create(['initial_balance' => 0]);
        Recurring::factory()->create([
            'name' => '積立', 'day' => 31, 'shift' => RecurringShift::Next, 'account_id' => $account->id,
            'start_month' => '2026-10-01', 'end_month' => '2026-10-01',
        ]);

        $this->assertSame('定期的な記録「積立」の最後の月の分（11月2日）が、まだ記録されていないので、『使わない』にできません。その日が過ぎてから『使わない』にしてください', $account->deactivationBlocker());
    }

    public function test_開いたあとにまとめられた相手の記録を古い画面で保存しても_元の名前の相手を作り直さない(): void
    {
        $seven = Partner::query()->create(['name' => 'セブン']);
        $target = Partner::query()->create(['name' => 'セブンイレブン']);
        $category = Category::query()->create(['name' => '食費のテスト', 'kind' => CategoryKind::Expense]);
        $record = Record::factory()->create(['partner_id' => $seven->id, 'category_id' => $category->id, 'type' => RecordType::Expense]);

        $old = Livewire::test(RecordForm::class, ['record' => $record]);
        $this->post(route('partners.merge', $seven), ['merge_target' => 'セブンイレブン'])->assertRedirect(route('partners.index'));

        // 古い画面（相手の欄は「セブン」のまま）で金額だけ直して保存しても、まとめ先のまま
        $old->set('amount', '1,200')->call('save')->assertHasNoErrors();
        $this->assertSame($target->id, $record->fresh()?->partner_id);
        $this->assertSame(1200, $record->fresh()?->amount);
        $this->assertSame(0, Partner::query()->where('name', 'セブン')->count());
    }

    public function test_候補から選んだ相手の名前が_開いたあとに変わっても_その相手のまま保存する(): void
    {
        $partner = Partner::query()->create(['name' => 'ローソン']);
        $category = Category::query()->create(['name' => '食費のテスト', 'kind' => CategoryKind::Expense]);
        $account = Account::factory()->create();

        $form = Livewire::test(RecordForm::class)
            ->set('amount', '500')->set('partnerName', 'ロー')->call('selectPartner', $partner->id)
            ->set('categoryId', $category->id)->set('accountId', $account->id);
        $partner->update(['name' => 'ローソン 駅前店']);

        $form->call('save')->assertHasNoErrors();
        $this->assertSame($partner->id, Record::query()->sole()->partner_id);
        $this->assertSame(0, Partner::query()->where('name', 'ローソン')->count());
    }

    public function test_ほかの画面で削除された記録を保存すると_エラーの画面ではなく理由を出す(): void
    {
        $record = $this->transfer(1000);
        $form = Livewire::test(RecordForm::class, ['record' => $record]);
        $record->delete();

        $form->set('amount', '2,000')->call('save')->assertHasErrors('blocker')->assertSee(RecordForm::GONE_MESSAGE);
        $form->call('delete')->assertRedirect();
    }

    public function test_ほかの画面で直された記録を古い画面で保存すると_上書きせずに知らせる(): void
    {
        $record = $this->transfer(1000);
        $old = Livewire::test(RecordForm::class, ['record' => $record]);
        $this->travel(1)->minutes();
        Livewire::test(RecordForm::class, ['record' => $record->fresh()])->set('amount', '1,500')->call('save');

        $old->set('amount', '900')->call('save')->assertHasErrors('blocker');
        $this->assertSame(1500, $record->fresh()?->amount);
    }

    public function test_ほかの画面で削除された予定は_保存も削除もエラーの画面にしない(): void
    {
        $rent = Recurring::factory()->create();
        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent]);
        $rent->delete();

        $form->call('save')->assertHasErrors('save');
        $form->call('deactivate')->assertHasErrors('delete');
        $form->call('delete')->assertRedirect(route('recurrings.index'));
    }

    public function test_確認の小窓を出したあとに日付が変わったら_小窓を出し直し_知らせていない月を今回はなしにしない(): void
    {
        // 10月26日（月）23 時 59 分：毎月 26 日の家賃を 4 月から使うに戻すと、4〜9 月が今回はなしと出る（10 月の分は今日なので、保存したら記録する）
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(23, 59));
        $rent = Recurring::factory()->create(['day' => 26, 'start_month' => '2026-04-01', 'is_active' => false]);
        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])
            ->set('isActive', true)
            ->call('save')
            ->assertSet('skipNotice', '2026年4月〜9月の分は記録せず、『今回はなし』にします');

        // 0 時をすぎて 10月27日になってから「保存する」を押す。10 月の分も過ぎた月に入るので、黙って保存せずに小窓を出し直す
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(0, 1));
        $form->call('save', true)->assertNoRedirect()
            ->assertSet('skipNotice', '2026年4月〜10月の分は記録せず、『今回はなし』にします');
        $this->assertFalse((bool) $rent->fresh()?->is_active);
    }

    public function test_動いている予定が使う小分類は_使わないの大分類の下へ移せない(): void
    {
        $food = Category::query()->create(['name' => '食費のテスト', 'kind' => CategoryKind::Expense]);
        $old = Category::query()->create(['name' => '昔の分類', 'kind' => CategoryKind::Expense, 'is_active' => false]);
        $lunch = Category::query()->create(['name' => '昼ごはん', 'kind' => CategoryKind::Expense, 'parent_id' => $food->id]);
        Recurring::factory()->create(['type' => RecordType::Expense, 'to_account_id' => null, 'category_id' => $lunch->id,
            'partner_id' => Partner::query()->create(['name' => '社食'])->id]);

        $this->put(route('categories.update', $lunch), ['name' => '昼ごはん', 'parent_id' => $old->id, 'is_active' => '1'])
            ->assertSessionHasErrors('parent_id');
        $this->assertSame($food->id, $lunch->fresh()?->parent_id);
    }
}
