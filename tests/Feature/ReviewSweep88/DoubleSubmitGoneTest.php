<?php

namespace Tests\Feature\ReviewSweep88;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Livewire\AdjustBalance;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * イシュー #88：記録・定期的な記録・残高を合わせるの新しい登録で、1 回目のあとに作ったものがほかの画面で削除されたとき、
 * 同じ合言葉の 2 回目は、作り直さず、「保存しました」とも言わずに知らせる。打った内容は欄に残す
 * （画面設計書 5.4・5.7・5.10。目標・口座などの追加 5.6・5.13 と同じ考え方。2026-11-07 に決定）。
 * もう一度押せば、新しく保存できる。今日は 2026年10月24日（土）
 */
class DoubleSubmitGoneTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        $this->bank = Account::factory()->create(['name' => 'みずほ銀行', 'type' => AccountType::Bank]);
    }

    private function food(): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->firstOrFail();
    }

    private function recordForm(): Testable
    {
        return Livewire::test(RecordForm::class)
            ->set('amount', '680')->set('partnerName', 'コンビニ')
            ->set('categoryId', $this->food()->id)->set('accountId', $this->bank->id);
    }

    public function test_記録の1回目のあとに削除されたら_同じ合言葉の2回目は作り直さず知らせ_打った内容は残す(): void
    {
        $form = $this->recordForm();
        $form->call('save')->assertHasNoErrors()->assertRedirect();
        Record::query()->delete();

        $form->call('save')->assertHasErrors('blocker')->assertNoRedirect()
            ->assertSee('ほかの画面で削除されていた')->assertSee('保存していません')
            ->assertSet('amount', '680')->assertSet('partnerName', 'コンビニ');
        $this->assertSame(0, Record::query()->count(), '作り直さない');

        // もう一度押せば、新しい記録として保存できる（ずっと止まったままにしない）
        $form->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(1, Record::query()->count());
    }

    public function test_記録の1回目のあとに削除されていなければ_今までどおり2回目は保存しましたの流れ(): void
    {
        $form = $this->recordForm();
        $form->call('save')->assertRedirect();
        $form->call('save')->assertHasNoErrors()->assertRedirect();

        $this->assertSame(1, Record::query()->count());
    }

    public function test_定期的な記録の1回目のあとに削除されたら_同じ合言葉の2回目は作り直さず知らせる(): void
    {
        $form = Livewire::test(RecurringForm::class)
            ->set('name', '給料')->set('isFixed', '1')->set('amount', '250,000')->set('day', '25')
            ->set('partnerName', '株式会社まる')->set('categoryId', $this->food()->id)->set('accountId', $this->bank->id);
        $form->call('save')->assertHasNoErrors()->assertRedirect();
        Recurring::query()->delete();

        $form->call('save')->assertHasErrors('save')->assertNoRedirect()
            ->assertSee('ほかの画面で削除されていた')->assertSet('name', '給料');
        $this->assertSame(0, Recurring::query()->count());

        $form->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(1, Recurring::query()->count());
    }

    public function test_残高を合わせたあと調整の記録が削除されたら_同じ合言葉の2回目は作り直さず知らせる(): void
    {
        $account = Account::factory()->create(['initial_balance' => 10000]);
        $form = Livewire::test(AdjustBalance::class, ['account' => $account])->set('actual', '9000');
        $form->call('save')->assertHasNoErrors()->assertRedirect();
        Record::query()->where('type', 'adjustment')->delete();

        $form->call('save')->assertHasErrors('actual')->assertNoRedirect()
            ->assertSee('ほかの画面で削除されていた')->assertSet('actual', '9000');
        $this->assertSame(0, Record::query()->where('type', 'adjustment')->count());

        // もう一度押せば、いまの差で合わせ直せる
        $form->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(-1000, Record::query()->where('type', 'adjustment')->sole()->amount);
    }
}
