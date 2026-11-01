<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * 保存が 2 回届いても、記録・定期的な記録が 2 件できないこと（画面設計書 5.4・5.10、5.13 の「2 回押し」）。
 * 貯金の目標は、すでに同じ決まり（5.13）。今日は 2026年10月24日（土）
 */
class DoubleSubmitTest extends TestCase
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

    private function recurringForm(): Testable
    {
        return Livewire::test(RecurringForm::class)
            ->set('name', '給料')->set('isFixed', '1')->set('amount', '250,000')->set('day', '25')
            ->set('partnerName', '株式会社まる')->set('categoryId', $this->food()->id)->set('accountId', $this->bank->id);
    }

    public function test_記録の保存が2回届いても_記録は1件だけで_2回目も保存したのと同じ流れで戻る(): void
    {
        $form = $this->recordForm();

        $form->call('save')->assertHasNoErrors()->assertRedirect();
        // 保存を 2 回押した・通信が重なって 2 回届いた（古い画面のまま、同じ合言葉で届く）
        $form->call('save')->assertHasNoErrors()->assertRedirect();

        $this->assertSame(1, Record::query()->count());
    }

    public function test_保存して続けて入力も_2回届いて記録は1件だけ(): void
    {
        $form = $this->recordForm();

        $form->call('save', true)->assertRedirect();
        $form->call('save', true)->assertRedirect();

        $this->assertSame(1, Record::query()->count());
    }

    public function test_画面を開き直せば_同じ内容でも別の記録として保存できる(): void
    {
        $this->recordForm()->call('save')->assertRedirect();
        $this->recordForm()->call('save')->assertRedirect();

        $this->assertSame(2, Record::query()->count());
    }

    public function test_まちがいで保存できなかったあとは_直して保存し直せる(): void
    {
        $form = $this->recordForm()->set('amount', '');

        // 入力のまちがいで止まった。合言葉は使わない
        $form->call('save')->assertHasErrors('amount');
        $form->set('amount', '680')->call('save')->assertHasNoErrors()->assertRedirect();

        $this->assertSame(1, Record::query()->count());
    }

    public function test_保存の途中で失敗したあとは_もう一度押せば保存できる(): void
    {
        // 最初の 1 回だけ、記録を作る途中で失敗する（データベースの一時的なエラーなど）
        $calls = 0;
        Record::creating(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new RuntimeException('一時的な失敗');
            }
        });
        $form = $this->recordForm();

        try {
            $form->call('save');
        } catch (RuntimeException) {
            // 失敗は想定どおり
        }
        $this->assertSame(0, Record::query()->count());

        // 失敗したときは合言葉を使い切らない。もう一度押せば保存される（2 回目と見なして、黙って捨てない）
        $form->call('save')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(1, Record::query()->count());
    }

    public function test_定期的な記録の保存が2回届いても_予定は1件だけ(): void
    {
        $form = $this->recurringForm();

        $form->call('save')->assertHasNoErrors()->assertRedirect();
        $form->call('save')->assertHasNoErrors()->assertRedirect();

        $this->assertSame(1, Recurring::query()->count());
    }

    public function test_定期的な記録も_画面を開き直せば同じ内容でも別の予定として保存できる(): void
    {
        $this->recurringForm()->call('save')->assertRedirect();
        $this->recurringForm()->call('save')->assertRedirect();

        $this->assertSame(2, Recurring::query()->count());
    }

    public function test_保存したあと同じ画面に戻って別の内容を入れて保存しても_保存したと言わずに知らせる(): void
    {
        $form = $this->recordForm();
        $form->call('save')->assertRedirect();

        // ブラウザの「戻る」で、保存済みの画面がそのまま戻ってきた。別の内容を入れて保存する（同じ合言葉で届く）
        $form->set('amount', '1,200')->call('save')
            ->assertHasErrors('blocker')
            ->assertNoRedirect();

        // 黙って「保存しました」にはしない。2 件目は作られないが、入れた内容は画面に残る
        $this->assertSame(1, Record::query()->count());
        $form->assertSet('amount', '1200');
    }

    public function test_定期的な記録も_保存したあとの別の内容は_保存したと言わずに知らせる(): void
    {
        $form = $this->recurringForm();
        $form->call('save')->assertRedirect();

        $form->set('name', '賞与')->call('save')->assertHasErrors('save')->assertNoRedirect();

        $this->assertSame(1, Recurring::query()->count());
    }

    public function test_データベースのキャッシュでも_2回目は1件だけにする(): void
    {
        // 本番と手元は CACHE_STORE=database。テストの既定（array）ではなく、データベースのキャッシュの「なければ入れる」で確かめる
        config(['cache.default' => 'database']);
        $this->app['cache']->forgetDriver('database');

        $form = $this->recordForm();
        $form->call('save')->assertRedirect();
        $form->call('save')->assertRedirect();

        $this->assertSame(1, Record::query()->count());
        $this->assertSame(1, DB::table('cache')->where('key', 'like', '%submitted:%')->count());
    }

    public function test_期限が切れた合言葉の行は_毎日の片づけで消え_まだ使える行は残る(): void
    {
        config(['cache.default' => 'database']);
        $this->app['cache']->forgetDriver('database');
        // 1 日の期限の合言葉と、3 日の期限の行を入れて、2 日あとに進める
        Cache::put('submitted:expired', ['state' => 'saved', 'hash' => 'x'], now()->addDay());
        Cache::put('submitted:alive', ['state' => 'saved', 'hash' => 'y'], now()->addDays(3));
        $this->travel(2)->days();

        $this->artisan('cache:prune-expired')->assertSuccessful();

        $keys = DB::table('cache')->pluck('key')->all();
        $this->assertCount(0, array_filter($keys, fn (string $k) => str_contains($k, 'submitted:expired')), '期限が切れた行は消える');
        $this->assertCount(1, array_filter($keys, fn (string $k) => str_contains($k, 'submitted:alive')), 'まだ使える行は残る');
    }

    // ------------------------------------------------------------------
    // 残高を合わせる（画面設計書 5.7）：保存が 2 回届いても、調整の記録は 1 件だけで、2 回目も「保存しました」の流れで戻る
    // ------------------------------------------------------------------

    private function adjustForm(Account $account, string $actual = '9000'): Testable
    {
        return Livewire::test(AdjustBalance::class, ['account' => $account])->set('actual', $actual);
    }

    public function test_残高を合わせる保存が2回届いても_調整の記録は1件だけで_2回目も保存したのと同じ流れで戻る(): void
    {
        $account = Account::factory()->create(['initial_balance' => 10000]);
        $form = $this->adjustForm($account);

        $form->call('save')->assertHasNoErrors()->assertRedirect();
        // 2 回目が届く。差は 0 になっているが、「ずれはありません」と失敗の形で出さない
        $form->call('save')->assertHasNoErrors()->assertRedirect();

        $this->assertSame(1, Record::query()->where('type', 'adjustment')->count());
        $this->assertSame(-1000, Record::query()->where('type', 'adjustment')->sole()->amount);
    }

    public function test_残高を合わせたあと同じ画面で別の金額を保存しても_保存したと言わずに知らせる(): void
    {
        $account = Account::factory()->create(['initial_balance' => 10000]);
        $form = $this->adjustForm($account);
        $form->call('save')->assertRedirect();

        $form->set('actual', '8000')->call('save')->assertHasErrors('actual')->assertNoRedirect();

        $this->assertSame(1, Record::query()->where('type', 'adjustment')->count());
    }

    public function test_残高を合わせる保存がずれなしで止まったあとは_金額を直して保存し直せる(): void
    {
        $account = Account::factory()->create(['initial_balance' => 10000]);
        $form = $this->adjustForm($account, '10000');

        // ずれはない（差が 0）ので、保存できない。合言葉は使い切らない
        $form->call('save')->assertHasErrors('actual');
        $form->set('actual', '9000')->call('save')->assertHasNoErrors()->assertRedirect();

        $this->assertSame(1, Record::query()->where('type', 'adjustment')->count());
    }

    public function test_残高を合わせたあと日付だけ変えて保存しても_保存したと言わずに知らせ_存在しないボタンの案内は出ない(): void
    {
        $account = Account::factory()->create(['initial_balance' => 10000]);
        $form = $this->adjustForm($account);
        $form->call('save')->assertRedirect();

        $form->set('date', today()->subDay()->toDateString())->call('save')
            ->assertHasErrors('actual')->assertNoRedirect();
        // この画面に「保存して続けて入力」のボタンはない。案内にも出さない
        $message = $form->errors()->first('actual');
        $this->assertStringContainsString('もう保存しています', $message);
        $this->assertStringNotContainsString('続けて入力', $message);
        $this->assertSame(1, Record::query()->where('type', 'adjustment')->count());
    }

    public function test_残高を合わせたあと別の画面で記録が増えて差が変わっていたら_同じ金額の2回目は保存したと言わずに知らせる(): void
    {
        $account = Account::factory()->create(['initial_balance' => 10000]);
        $form = $this->adjustForm($account);
        $form->call('save')->assertRedirect();

        // 1 回目の保存のあと、別の画面でこの口座の記録が増えた（実際の金額 9,000 円と、アプリの残高がずれた）
        Record::factory()->create(['account_id' => $account->id, 'type' => 'expense', 'amount' => 500, 'date' => today()->toDateString()]);
        $form->call('save')->assertHasErrors('actual')->assertNoRedirect();

        $this->assertSame(1, Record::query()->where('type', 'adjustment')->count());
    }
}
