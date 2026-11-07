<?php

namespace Tests\Feature\ReviewSweep88;

use App\Enums\CategoryKind;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * イシュー #88：「使わない」大分類の下に、「使う」小分類は置けない（画面設計書 5.8、2026-11-07 に決定。要件定義書 5.4）。
 * 期待値は設計書の文だけから決めている（今の実装の動きには合わせていない）
 */
class CategoryActiveParentTest extends TestCase
{
    use RefreshDatabase;

    /** 「使う」小分類がある大分類を「使わない」にしようとしたときの文（画面設計書 5.8） */
    private const HAS_ACTIVE_CHILD = '大分類を『使う』のままにするか、小分類を別の大分類へ移して';

    /** 「使わない」大分類の下へ移す・追加するときの文（画面設計書 5.8） */
    private const PARENT_INACTIVE = '先に大分類を『使う』にするか、別の大分類を選んでください';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function find(string $name): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('name', $name)->firstOrFail();
    }

    /** 出ているまちがいの文を全部つなげる（欄の名前は設計書にないので、文で確かめる） */
    private function errorText(): string
    {
        $errors = session('errors');

        return $errors === null ? '' : implode("\n", $errors->getBag('default')->all());
    }

    /** 「使わない」大分類（小分類なし）を作る */
    private function inactiveParent(string $name = '旧分類'): Category
    {
        return Category::factory()->create(['name' => $name, 'is_active' => false]);
    }

    // ---- C-1 ----

    public function test_c1_使う小分類がある大分類は_変更の保存で使わないにできない(): void
    {
        $food = $this->find('食費');
        Category::factory()->childOf($food)->create(['name' => '外食']);

        $this->put("/categories/{$food->id}", ['name' => '食費', 'is_active' => '0'])->assertSessionHasErrors();

        $this->assertStringContainsString(self::HAS_ACTIVE_CHILD, $this->errorText());
        $this->assertTrue($food->refresh()->is_active, '保存されない');
    }

    public function test_c1_使う小分類がある大分類は_削除の小窓からも使わないにできない(): void
    {
        $food = $this->find('食費');
        Category::factory()->childOf($food)->create(['name' => '外食']);

        $this->patch("/categories/{$food->id}/deactivate")->assertSessionHasErrors()->assertSessionMissing('status');

        $this->assertStringContainsString(self::HAS_ACTIVE_CHILD, $this->errorText());
        $this->assertTrue($food->refresh()->is_active);
    }

    public function test_c1_小分類がすべて使わないなら_大分類は使わないにできる(): void
    {
        $food = $this->find('食費');
        Category::factory()->childOf($food)->create(['name' => '外食', 'is_active' => false]);

        $this->put("/categories/{$food->id}", ['name' => '食費', 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($food->refresh()->is_active);
    }

    // ---- C-2 ----

    public function test_c2_小分類を使わない大分類の下へは移せない(): void
    {
        $food = $this->find('食費');
        $child = Category::factory()->childOf($food)->create(['name' => '外食']);
        $old = $this->inactiveParent();

        $this->put("/categories/{$child->id}", ['name' => '外食', 'parent_id' => $old->id, 'is_active' => '1'])->assertSessionHasErrors();

        $this->assertStringContainsString(self::PARENT_INACTIVE, $this->errorText());
        $this->assertSame($food->id, $child->refresh()->parent_id, '移されない');
    }

    public function test_c2_小分類を使わない大分類の下へは追加できない(): void
    {
        $old = $this->inactiveParent();

        $this->post('/categories', ['name' => '新小分類', 'parent_id' => $old->id])->assertSessionHasErrors();

        $this->assertStringContainsString(self::PARENT_INACTIVE, $this->errorText());
        $this->assertDatabaseMissing('categories', ['name' => '新小分類']);
    }

    // ---- C-3 ----

    public function test_c3_使わない大分類の下の小分類は_使うに戻せない(): void
    {
        $old = $this->inactiveParent();
        $child = Category::factory()->childOf($old)->create(['name' => '旧小分類', 'is_active' => false]);

        $this->put("/categories/{$child->id}", ['name' => '旧小分類', 'parent_id' => $old->id, 'is_active' => '1'])->assertSessionHasErrors();

        $this->assertStringContainsString('大分類を『使う』', $this->errorText());
        $this->assertFalse($child->refresh()->is_active);
    }

    // ---- C-4 ----

    public function test_c4_小分類の追加の親の選択肢に_使わない大分類は出さない(): void
    {
        $food = $this->find('食費');
        $old = $this->inactiveParent();

        $this->get("/categories/create?parent={$food->id}")->assertOk()
            ->assertSee('<option value="'.$food->id.'"', false)
            ->assertDontSee('<option value="'.$old->id.'"', false);
    }

    public function test_c4_変更の画面の親の選択肢にも_使わない大分類は出さない_いまの親が使わないときだけ使わない付きで出す(): void
    {
        $food = $this->find('食費');
        $old = $this->inactiveParent();
        $child = Category::factory()->childOf($food)->create(['name' => '外食']);

        // いまの親が「使う」：「使わない」大分類は選択肢に出ない
        $this->get("/categories/{$child->id}/edit")->assertOk()
            ->assertDontSee('<option value="'.$old->id.'"', false);

        // いまの親が「使わない」（古いデータ）：いまの親だけを「（使わない）」つきで出す
        $other = $this->inactiveParent('別の旧分類');
        $legacy = Category::factory()->childOf($old)->create(['name' => '残っていた小分類']);
        $this->get("/categories/{$legacy->id}/edit")->assertOk()
            ->assertSee('<option value="'.$old->id.'"', false)
            ->assertSee('（使わない）')
            ->assertDontSee('<option value="'.$other->id.'"', false);
    }

    // ---- C-5 ----

    /**
     * 確かめたあと、保存の直前に、ほかの画面で移す先の大分類が「使わない」になったことにする。
     * 分類の行を押さえに行く最初のクエリの直前に、移す先を「使わない」に書きかえる
     */
    private function deactivateOnFirstLock(Category $parent): void
    {
        $done = false;
        DB::beforeExecuting(function (string $query) use (&$done, $parent) {
            if (! $done && preg_match('/from `categories`.*(for update|lock in share mode)/is', $query) === 1) {
                $done = true;
                DB::table('categories')->where('id', $parent->id)->update(['is_active' => false]);
            }
        });
    }

    public function test_c5_開いたあとに移す先が使わないになっていたら_保存の直前に押さえて止める_変更(): void
    {
        $food = $this->find('食費');
        $target = $this->find('日用品');
        $child = Category::factory()->childOf($food)->create(['name' => '外食']);

        $this->deactivateOnFirstLock($target);
        $this->put("/categories/{$child->id}", ['name' => '外食', 'parent_id' => $target->id, 'is_active' => '1'])->assertSessionHasErrors();

        $this->assertStringContainsString(self::PARENT_INACTIVE, $this->errorText());
        $this->assertSame($food->id, $child->refresh()->parent_id, '移されない');
    }

    public function test_c5_開いたあとに追加先が使わないになっていたら_保存の直前に押さえて止める_追加(): void
    {
        $food = $this->find('食費');

        $this->deactivateOnFirstLock($food);
        $this->post('/categories', ['name' => '新小分類', 'parent_id' => $food->id])->assertSessionHasErrors();

        $this->assertStringContainsString(self::PARENT_INACTIVE, $this->errorText());
        $this->assertDatabaseMissing('categories', ['name' => '新小分類']);
    }

    public function test_c5_確かめたあとに使う小分類が足された大分類は_使わないにできない(): void
    {
        // 確かめたあと保存までに、ほかの画面で「使う」小分類が足された
        $food = $this->find('食費');
        $done = false;
        DB::beforeExecuting(function (string $query) use (&$done, $food) {
            if (! $done && preg_match('/from `categories`.*for update/is', $query) === 1) {
                $done = true;
                DB::table('categories')->insert(['name' => '外食', 'kind' => 'expense', 'parent_id' => $food->id, 'is_active' => true, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->put("/categories/{$food->id}", ['name' => '食費', 'is_active' => '0'])->assertSessionHasErrors();

        $this->assertStringContainsString(self::HAS_ACTIVE_CHILD, $this->errorText());
        $this->assertTrue($food->refresh()->is_active);
    }

    // ---- そのままでよいもの ----

    public function test_使わない大分類の下の使わない小分類は_そのまま保存できる(): void
    {
        $old = $this->inactiveParent();
        $child = Category::factory()->childOf($old)->create(['name' => '旧小分類', 'is_active' => false]);

        $this->put("/categories/{$child->id}", ['name' => '旧小分類2', 'parent_id' => $old->id, 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('旧小分類2', $child->refresh()->name);
    }

    public function test_すでに使わない親の下にある使う小分類は_親と使うの欄を変えない保存を止めない(): void
    {
        // 古いデータ（決まりができる前から「使わない」大分類の下にある「使う」小分類）
        $old = $this->inactiveParent();
        $legacy = Category::factory()->childOf($old)->create(['name' => '残っていた小分類']);

        $this->put("/categories/{$legacy->id}", ['name' => '名前だけ直す', 'parent_id' => $old->id, 'is_active' => '1'])->assertSessionHasNoErrors();

        $legacy->refresh();
        $this->assertSame('名前だけ直す', $legacy->name);
        $this->assertTrue($legacy->is_active, '勝手に変えない');
    }

    public function test_すでに使わない親の下にある使う小分類の変更の画面には_大分類が使わないですと知らせる(): void
    {
        $old = $this->inactiveParent();
        $legacy = Category::factory()->childOf($old)->create(['name' => '残っていた小分類']);

        $this->get("/categories/{$legacy->id}/edit")->assertOk()->assertSee('大分類が『使わない』です');
    }
}
