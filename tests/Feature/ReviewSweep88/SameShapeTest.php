<?php

namespace Tests\Feature\ReviewSweep88;

use App\Enums\AccountType;
use App\Enums\CategoryKind;
use App\Livewire\PendingAmounts;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * イシュー #88 の決まりと「同じ形」のところ（設計書だけから作ったテスト 33 件の外で、実装のときに見つけて、いっしょに直したもの）
 * - ホームの「まとめて保存」が 2 回届いた 2 回目（月ごとの金額の保存 K-4 と同じ形。画面設計書 5.2・5.10）
 * - 口座・分類・相手の追加で、1 回目のあとに削除された 2 回目（目標の追加 K-5 と同じ形。画面設計書 5.6）
 * - 「使わない」大分類への小分類の追加の画面・一覧のリンク（画面設計書 5.8）
 * - 「その他」の変更の画面の、押せない・変えられない形（画面設計書 5.9）
 * - ためしのデータで、0 円にする記録を飛ばしたときの「使わない」口座（要件定義書 5.4）
 */
class SameShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    }

    private function food(): Category
    {
        return $this->find('食費');
    }

    private function find(string $name): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', $name)->firstOrFail();
    }

    // ---- ホームの「まとめて保存」 ----

    /** 毎月 20 日・次の平日の電気代（金額が変わる）。10 月から。10月20日は過ぎているので、ホームのお知らせに出る */
    private function power(): Recurring
    {
        return Recurring::factory()->create([
            'name' => '電気代', 'day' => 20, 'is_fixed' => false, 'amount' => null, 'estimated_amount' => 9000, 'start_month' => '2026-10-01',
        ]);
    }

    public function test_ホームのまとめて保存を2回呼んでも_1回目で記録された金額について_使っていませんと出さない(): void
    {
        $power = $this->power();
        $key = PendingAmounts::key($power, CarbonImmutable::create(2026, 10, 1));
        $component = Livewire::test(PendingAmounts::class);

        $component->set('amounts.'.$key, '9,200')->call('save')->assertRedirect(route('home'));
        $this->assertSame(1, Record::query()->where('recurring_id', $power->id)->count());

        $component->call('save')->assertSet('notice', '')->assertDontSee('入れた金額は使っていません');
        $this->assertSame(9200, Record::query()->where('recurring_id', $power->id)->sole()->amount);
    }

    public function test_ホームのまとめて保存で_ほかの画面でちがう金額で記録されていたら_今までどおり知らせる(): void
    {
        $power = $this->power();
        $key = PendingAmounts::key($power, CarbonImmutable::create(2026, 10, 1));
        $component = Livewire::test(PendingAmounts::class)->set('amounts.'.$key, '9,200');

        // 開いたあとに、ほかの画面で 9,000 円で記録された
        Record::factory()->create(['recurring_id' => $power->id, 'recurring_month' => '2026-10-01', 'amount' => 9000, 'date' => '2026-10-20']);

        $component->call('save');
        $this->assertStringContainsString('入れた金額は使っていません', $component->get('notice'));
    }

    // ---- 口座・分類・相手の追加：1 回目のあとに削除された 2 回目 ----

    public function test_口座の追加で_1回目のあとに削除されても_同じ合言葉の2回目は作り直さず_保存していないと知らせる(): void
    {
        $data = ['name' => 'みずほ銀行', 'type' => 'bank', 'initial_balance' => '0', 'is_active' => '1', 'submit_token' => 'token-a'];
        $this->post('/accounts', $data)->assertRedirect('/accounts');
        Account::query()->delete();

        $this->from('/accounts/create')->post('/accounts', $data)
            ->assertRedirect('/accounts/create')->assertSessionHas('warning')->assertSessionMissing('status')
            // 描き直した画面は新しい合言葉になるように、合言葉は引きつがない（打った内容は残す）
            ->assertSessionHasInput('name', 'みずほ銀行')->assertSessionMissing('_old_input.submit_token');
        $this->assertSame(0, Account::query()->count());
        $this->assertStringContainsString('口座は、ほかの画面で削除されていた', (string) session('warning'));
    }

    public function test_相手と分類の追加でも_1回目のあとに削除された2回目は作り直さない(): void
    {
        $this->post('/partners', ['name' => 'コンビニ', 'submit_token' => 'token-p'])->assertRedirect('/partners');
        Partner::query()->where('name', 'コンビニ')->delete();
        $this->post('/partners', ['name' => 'コンビニ', 'submit_token' => 'token-p'])->assertSessionHas('warning')->assertSessionMissing('status');
        $this->assertFalse(Partner::query()->where('name', 'コンビニ')->exists());
        $this->assertStringContainsString('相手は、ほかの画面で削除されたか', (string) session('warning'));

        $this->post('/categories', ['name' => 'ペット', 'kind' => 'expense', 'submit_token' => 'token-c']);
        Category::query()->where('name', 'ペット')->delete();
        $this->post('/categories', ['name' => 'ペット', 'kind' => 'expense', 'submit_token' => 'token-c'])->assertSessionHas('warning')->assertSessionMissing('status');
        $this->assertFalse(Category::query()->where('name', 'ペット')->exists());
        $this->assertStringContainsString('分類は、ほかの画面で削除されていた', (string) session('warning'));
    }

    // ---- 「使わない」大分類への小分類の追加 ----

    public function test_使わない大分類の小分類の追加の画面を開くと_一覧へ戻して知らせる_一覧に追加のリンクも出さない(): void
    {
        $food = $this->food();
        $food->update(['is_active' => false]);

        $this->get("/categories/create?parent={$food->id}")->assertRedirect('/categories')
            ->assertSessionHas('warning', Category::ADD_TO_INACTIVE_MESSAGE);
        // 「?parent=1」が「?parent=10」にふくまれないように、閉じる " までくらべる
        $this->get('/categories')->assertDontSee('href="'.route('categories.create', ['parent' => $food->id]).'"', false)
            ->assertSee('href="'.route('categories.create', ['parent' => $this->find('日用品')->id]).'"', false);
    }

    public function test_大分類を使わないにできない理由が2つのときは_両方出す(): void
    {
        $food = $this->food();
        $sub = Category::factory()->childOf($food)->create(['name' => '宅配']);
        Recurring::factory()->create(['category_id' => $sub->id, 'start_month' => '2026-11-01']);

        $this->put("/categories/{$food->id}", ['name' => '食費', 'is_active' => '0']);
        $this->get("/categories/{$food->id}/edit")
            ->assertSee(Category::HAS_ACTIVE_CHILD_MESSAGE)->assertSee(Recurring::IN_USE_MESSAGE);
    }

    // ---- 「その他」の変更の画面 ----

    public function test_その他の変更の画面では_名前は変えられず_使うと削除は押せず_まとめるの欄は出さない(): void
    {
        $other = Partner::query()->where('name', 'その他')->sole();
        Partner::factory()->create(['name' => 'コンビニ']);

        $html = (string) $this->get("/partners/{$other->id}/edit")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*id="name"[^>]*readonly/s', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="is_active"[^>]*disabled/s', $html);
        $this->assertMatchesRegularExpression('/data-bs-target="#delete-dialog"[^>]*disabled/s', $html);
        $this->assertStringNotContainsString('id="merge-form"', $html);
        // 押せない欄は送られないので、「使う」のまま送る
        $this->assertStringContainsString('<input type="hidden" name="is_active" value="1">', $html);
    }

    // ---- ためしのデータ ----

    public function test_ためしのデータで食費が無いときは_古い財布を0円にできないので使わないにしない(): void
    {
        // 「食費」は、古い財布を 0 円にするパン屋の記録・外食の小分類で使う
        Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', '食費')->delete();

        $this->artisan('db:seed', ['--class' => SampleSeeder::class])->assertSuccessful();

        $wallet = Account::query()->where('name', '古い財布')->sole();
        $this->assertSame(AccountType::Cash, $wallet->type);
        $this->assertTrue($wallet->is_active, '残高が 0 円でない口座は『使わない』にしない');
    }

    public function test_ためしのデータで食費が使わないときは_その下に使う外食を作らない(): void
    {
        $this->food()->update(['is_active' => false]);

        $this->artisan('db:seed', ['--class' => SampleSeeder::class])->expectsOutputToContain('外食')->assertSuccessful();

        $this->assertFalse(Category::query()->where('name', '外食')->exists());
    }
}
