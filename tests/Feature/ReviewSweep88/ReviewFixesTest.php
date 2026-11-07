<?php

namespace Tests\Feature\ReviewSweep88;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use App\Support\SubmitOnce;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * イシュー #88：PR の前のレビューで見つかったところ
 * - 分類の変更で保存できずに戻った画面で、親の欄が黙ってほかの大分類にならない（画面設計書 5.8）
 * - テスト用のデータ（TestDataSeeder）も、ためしのデータと同じく、見つからない分類の行は飛ばし、「使わない」大分類の下に小分類を作らない
 * - 相手が「その他」のときは、ほかの文より先に、守りの文を出す（画面設計書 5.9）
 */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
    }

    private function find(string $name): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', $name)->firstOrFail();
    }

    /** 変更の画面で、選ばれている親の id（選ばれていなければ null。ブラウザは、そのとき最初の選択肢を選んでしまう） */
    private function selectedParent(Category $child): ?int
    {
        $html = (string) $this->get("/categories/{$child->id}/edit")->assertOk()->getContent();
        preg_match('/<option value="(\d+)"\s+selected/', $html, $m);

        return isset($m[1]) ? (int) $m[1] : null;
    }

    // ---- 親の欄 ----

    public function test_使わない大分類へ移そうとして戻った画面では_親の欄はいまの親のまま(): void
    {
        $car = $this->find('自動車');
        $child = Category::factory()->childOf($car)->create(['name' => '洗車']);
        $old = Category::factory()->create(['name' => '旧分類', 'is_active' => false]);

        $this->from("/categories/{$child->id}/edit")
            ->put("/categories/{$child->id}", ['name' => '洗車', 'parent_id' => $old->id, 'is_active' => '1'])->assertSessionHasErrors('parent_id');

        $this->assertSame($car->id, $this->selectedParent($child), '選ぶ欄にない親のかわりに、ほかの大分類が黙って選ばれない');
    }

    public function test_選んだ大分類が削除されて戻った画面でも_親の欄はいまの親のまま(): void
    {
        $car = $this->find('自動車');
        $child = Category::factory()->childOf($car)->create(['name' => '洗車']);
        $gone = Category::factory()->create(['name' => '消える分類']);

        // 入力チェックのあと、保存までに、選んだ大分類がほかの画面で削除された
        $this->from("/categories/{$child->id}/edit")->withSession(['_old_input' => ['name' => '洗車', 'parent_id' => (string) $gone->id, 'is_active' => '1']]);
        $gone->delete();

        $this->assertSame($car->id, $this->selectedParent($child));
    }

    public function test_使う大分類へ移そうとして名前のまちがいで戻ったときは_選んだ親のまま(): void
    {
        $car = $this->find('自動車');
        $daily = $this->find('日用品');
        $child = Category::factory()->childOf($car)->create(['name' => '洗車']);

        $this->from("/categories/{$child->id}/edit")
            ->put("/categories/{$child->id}", ['name' => '', 'parent_id' => $daily->id, 'is_active' => '1'])->assertSessionHasErrors('name');

        $this->assertSame($daily->id, $this->selectedParent($child));
    }

    // ---- テスト用のデータ ----

    public function test_テスト用のデータで分類が無くても_止まらず_その分類の記録だけ飛ばして知らせる(): void
    {
        $this->find('交通費')->delete();

        $this->artisan('db:seed', ['--class' => TestDataSeeder::class])->expectsOutputToContain('交通費')->assertSuccessful();

        $this->assertGreaterThan(0, Record::query()->count());
        $this->assertLessThan(TestDataSeeder::RECORDS, Record::query()->count(), '電車代の行は飛ばした');
    }

    public function test_テスト用のデータでカードで払う分類が全部無くても_止まらず_引き落としの行だけ飛ばして知らせる(): void
    {
        // カードで払うふだんの記録は、食費（スーパー）・日用品・趣味・娯楽・衣服・美容だけ。全部無いと、カードの前の月の分が 0 円になる
        foreach (['食費', '日用品', '趣味・娯楽', '衣服・美容'] as $name) {
            $this->find($name)->delete();
        }
        $card = fn () => Record::query()->where('type', 'transfer')
            ->whereIn('to_account_id', Account::query()->where('name', 'クレジットカード')->select('id'));

        $this->artisan('db:seed', ['--class' => TestDataSeeder::class])->expectsOutputToContain('引き落とし')->assertSuccessful();

        $this->assertGreaterThan(0, Record::query()->count(), 'ほかの記録は入る');
        $this->assertSame(0, $card()->count(), '0 円の引き落としは入れない（金額を黙って 0 円にしない）');
        $this->assertSame(0, Record::query()->where('amount', '<', 1)->count());
    }

    public function test_テスト用のデータで食費が使わないときは_その下に使う外食を作らない(): void
    {
        $this->find('食費')->update(['is_active' => false]);

        $this->artisan('db:seed', ['--class' => TestDataSeeder::class])->expectsOutputToContain('外食')->assertSuccessful();

        $this->assertFalse(Category::query()->where('name', '外食')->exists());
    }

    // ---- 相手「その他」 ----

    public function test_その他を使わないにしようとしたら_動いている定期的な記録の文より先に守りの文を出す(): void
    {
        $other = Partner::query()->where('name', Partner::OTHER_NAME)->sole();
        Recurring::factory()->create(['partner_id' => $other->id, 'start_month' => '2026-11-01']);

        $this->put("/partners/{$other->id}", ['name' => Partner::OTHER_NAME, 'is_active' => '0'])
            ->assertSessionHasErrors(['protected' => Partner::PROTECTED_MESSAGE])->assertSessionDoesntHaveErrors('is_active');
        $this->assertTrue($other->refresh()->is_active);
    }

    public function test_その他の名前を空で送っても_守りの文を出す(): void
    {
        $other = Partner::query()->where('name', Partner::OTHER_NAME)->sole();

        $this->put("/partners/{$other->id}", ['name' => '', 'is_active' => '1'])
            ->assertSessionHasErrors(['protected' => Partner::PROTECTED_MESSAGE]);
        $this->assertSame(Partner::OTHER_NAME, $other->refresh()->name);
    }

    // ---- 知らせの文 ----

    public function test_相手が消えていたときの文は_まとめられたことも書く(): void
    {
        $message = SubmitOnce::goneMessage(['model' => Partner::class, 'id' => 1], '追加');

        $this->assertStringContainsString('相手は、ほかの画面で削除されたか、ほかの相手にまとめられていた', $message);
    }
}
