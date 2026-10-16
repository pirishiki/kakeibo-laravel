<?php

namespace Tests\Feature\Categories;

use App\Enums\CategoryKind;
use App\Models\Category;
use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 要件定義書 10.1「分類の管理（No.6、画面設計書 5.8）」の受け入れ基準を確かめる。
 * 記録の登録で選ぶときの順番・「使わない」大分類の下の小分類が選べないことは、記録の画面を作るときに確かめる。
 */
class CategoryManageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function find(string $name, CategoryKind $kind = CategoryKind::Expense): Category
    {
        return Category::query()->whereNull('parent_id')->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function namesInOrder(CategoryKind $kind, ?Category $parent = null): array
    {
        return Category::query()
            ->where('kind', $kind)
            ->when($parent, fn ($q) => $q->where('parent_id', $parent->id), fn ($q) => $q->whereNull('parent_id'))
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();
    }

    public function test_ログインしないと分類の画面は開けない(): void
    {
        auth()->logout();

        $this->get('/categories')->assertRedirect('/login');
        $this->post('/categories', ['name' => 'ペット', 'kind' => 'expense'])->assertRedirect('/login');
    }

    public function test_使い始めたときに要件定義書の分類がその順番で入っている(): void
    {
        $this->assertSame(
            ['食費', '日用品', '交通費', '住まい', '水道・光熱費', '通信費', '自動車', 'ローン', '保険', '医療・健康', '衣服・美容', '趣味・娯楽', '交際費', '税金・社会保険', 'その他'],
            $this->namesInOrder(CategoryKind::Expense),
        );
        $this->assertSame(['給与', '賞与', '臨時収入', 'その他'], $this->namesInOrder(CategoryKind::Income));

        $this->get('/categories')
            ->assertOk()
            ->assertSeeInOrder(['支出の分類', '食費', '日用品', 'その他', '収入の分類', '給与', 'その他']);
    }

    public function test_大分類と小分類を追加でき_新しい分類は同じ段のいちばん下に入る(): void
    {
        $this->post('/categories', ['name' => 'ペット', 'kind' => 'expense'])->assertSessionHasNoErrors();
        $this->assertSame('ペット', last($this->namesInOrder(CategoryKind::Expense)));

        $food = $this->find('食費');
        $this->get("/categories/create?parent={$food->id}")->assertOk()->assertSee('小分類を追加');
        $this->post('/categories', ['name' => '食料品', 'parent_id' => $food->id])->assertSessionHasNoErrors();
        $this->post('/categories', ['name' => '外食', 'parent_id' => $food->id])->assertSessionHasNoErrors();

        $this->assertSame(['食料品', '外食'], $this->namesInOrder(CategoryKind::Expense, $food));
        $this->get('/categories')->assertSeeInOrder(['食費', '食料品', '外食', '＋ 小分類を追加', '日用品']);
    }

    public function test_小分類の下にさらに小分類は作れない(): void
    {
        $child = Category::factory()->childOf($this->find('食費'))->create(['name' => '外食']);

        $this->post('/categories', ['name' => 'ランチ', 'parent_id' => $child->id])
            ->assertSessionHasErrors(['parent_id' => '親の大分類は、出ている中から選んでください。']);
        $this->get("/categories/create?parent={$child->id}")->assertRedirect(route('categories.index'));
    }

    public function test_収入用と支出用は追加のときだけ選べて_あとから変えられない(): void
    {
        $this->post('/categories', ['name' => '副業', 'kind' => 'income'])->assertSessionHasNoErrors();
        $side = $this->find('副業', CategoryKind::Income);

        $this->get("/categories/{$side->id}/edit")->assertSee('収入用')->assertSee('あとから変えられません')->assertDontSee('type="radio" id="kind-', false);
        $this->put("/categories/{$side->id}", ['name' => '副業', 'kind' => 'expense', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(CategoryKind::Income, $side->refresh()->kind);
    }

    public function test_小分類の親は同じ用の大分類から選ぶ(): void
    {
        $food = $this->find('食費');
        $salary = $this->find('給与', CategoryKind::Income);

        $this->post('/categories', ['name' => '外食', 'parent_id' => $salary->id])->assertSessionHasNoErrors();
        $this->assertSame(CategoryKind::Income, Category::query()->where('name', '外食')->firstOrFail()->kind, '小分類の用は親と同じ');

        $child = Category::factory()->childOf($food)->create(['name' => '食料品']);
        $this->put("/categories/{$child->id}", ['name' => '食料品', 'parent_id' => $salary->id, 'is_active' => '1'])
            ->assertSessionHasErrors('parent_id');
        $this->get("/categories/{$child->id}/edit")->assertDontSee('>給与', false);
    }

    public function test_同じ段で同じ名前はつけられず_別の大分類の小分類なら同じ名前でもよい(): void
    {
        $food = $this->find('食費');
        $daily = $this->find('日用品');

        $this->post('/categories', ['name' => '食費', 'kind' => 'expense'])
            ->assertSessionHasErrors(['name' => 'この名前は、もう使われています。']);
        // 支出の「その他」と収入の「その他」は、別の段なので両方ある
        $this->assertSame(2, Category::query()->where('name', 'その他')->count());
        $this->post('/categories', ['name' => '食費', 'kind' => 'income'])->assertSessionHasNoErrors();

        $this->post('/categories', ['name' => 'その他', 'parent_id' => $food->id])->assertSessionHasNoErrors();
        $this->post('/categories', ['name' => 'その他', 'parent_id' => $food->id])->assertSessionHasErrors('name');
        $this->post('/categories', ['name' => 'その他', 'parent_id' => $daily->id])->assertSessionHasNoErrors();
    }

    public function test_名前は必須で50文字まで(): void
    {
        $this->post('/categories', ['name' => '', 'kind' => 'expense'])->assertSessionHasErrors(['name' => '名前を入れてください。']);
        $this->post('/categories', ['name' => str_repeat('あ', 51), 'kind' => 'expense'])
            ->assertSessionHasErrors(['name' => '名前は 50 文字以内で入れてください。']);
    }

    /**
     * @return list<int>
     */
    private function idsInOrder(CategoryKind $kind, ?Category $parent = null): array
    {
        return Category::query()
            ->where('kind', $kind)
            ->when($parent, fn ($q) => $q->where('parent_id', $parent->id), fn ($q) => $q->whereNull('parent_id'))
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();
    }

    public function test_ドラッグで並べた順番が保存される(): void
    {
        $food = $this->find('食費');
        $a = Category::factory()->childOf($food)->create(['name' => '食料品']);
        $b = Category::factory()->childOf($food)->create(['name' => '外食']);

        // いちばん下の「その他」を、1 回でいちばん上へ
        $ids = $this->idsInOrder(CategoryKind::Expense);
        $ids = [array_pop($ids), ...$ids];
        $this->patchJson('/categories/reorder', ['ids' => $ids])->assertOk()->assertJson(['message' => '順番を保存しました']);
        $this->assertSame(['その他', '食費', '日用品'], array_slice($this->namesInOrder(CategoryKind::Expense), 0, 3));

        // 小分類を並べかえても、大分類の順番は変わらない
        $this->patchJson('/categories/reorder', ['ids' => [$b->id, $a->id]])->assertOk();
        $this->assertSame(['外食', '食料品'], $this->namesInOrder(CategoryKind::Expense, $food));
        $this->assertSame('その他', $this->namesInOrder(CategoryKind::Expense)[0]);

        // 一覧の画面にも、その順番で出る
        $this->get('/categories')->assertSeeInOrder(['支出の分類', 'その他', '食費', '外食', '食料品', '日用品']);
    }

    public function test_ちがう段がまざった順番やそろっていない順番は保存しない(): void
    {
        $food = $this->find('食費');
        $child = Category::factory()->childOf($food)->create(['name' => '外食']);
        $before = $this->idsInOrder(CategoryKind::Expense);
        $message = '順番を保存できませんでした。画面を読みこみ直してから、もう一度動かしてください';

        // 大分類の中に小分類がまざっている
        $this->patchJson('/categories/reorder', ['ids' => [...$before, $child->id]])->assertStatus(422)->assertJson(['message' => $message]);
        // 支出の大分類の中に収入の大分類がまざっている
        $salary = $this->find('給与', CategoryKind::Income);
        $this->patchJson('/categories/reorder', ['ids' => [...array_slice($before, 1), $salary->id]])->assertStatus(422);
        // 同じ段の分類が 1 つ足りない（ほかの画面で分類を足したあと、古い一覧のまま動かしたときなど）
        $this->patchJson('/categories/reorder', ['ids' => array_slice($before, 1)])->assertStatus(422);
        // 同じ id が 2 回
        $this->patchJson('/categories/reorder', ['ids' => [$before[0], ...$before]])->assertStatus(422);

        $this->assertSame($before, $this->idsInOrder(CategoryKind::Expense));
    }

    public function test_ログインしないと並べかえは保存できない(): void
    {
        $before = $this->idsInOrder(CategoryKind::Expense);
        auth()->logout();

        $this->patchJson('/categories/reorder', ['ids' => array_reverse($before)])->assertUnauthorized();
        $this->assertSame($before, $this->idsInOrder(CategoryKind::Expense));
    }

    public function test_小分類の親を変えると記録もいっしょに移り_移した先のいちばん下に入る(): void
    {
        $car = $this->find('自動車');
        $loan = $this->find('ローン');
        Category::factory()->childOf($car)->create(['name' => 'ガソリン']);
        $carLoan = Category::factory()->childOf($loan)->create(['name' => '車']);
        Record::factory()->count(12)->create(['category_id' => $carLoan->id]);

        $this->get("/categories/{$carLoan->id}/edit")->assertSee('この小分類の記録 12 件もいっしょに移ります');

        $this->put("/categories/{$carLoan->id}", ['name' => '車', 'parent_id' => $car->id, 'is_active' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(['ガソリン', '車'], $this->namesInOrder(CategoryKind::Expense, $car));
        $this->assertSame(12, Record::query()->whereHas('category', fn ($q) => $q->where('parent_id', $car->id))->count());
    }

    public function test_記録がない分類は確認の小窓のあと削除できる(): void
    {
        $pet = Category::factory()->create(['name' => 'ペット']);

        $this->get("/categories/{$pet->id}/edit")->assertSee('「ペット」を削除しますか？');
        $this->delete("/categories/{$pet->id}")->assertRedirect('/categories')->assertSessionHas('status', '削除しました');
        $this->assertModelMissing($pet);
    }

    public function test_小分類がある大分類は削除できない(): void
    {
        $food = $this->find('食費');
        Category::factory()->childOf($food)->create(['name' => '外食']);

        $this->get("/categories/{$food->id}/edit")
            ->assertSee('先に小分類を削除するか、別の大分類へ移してください')
            ->assertDontSee('>削除する</button>', false);
        $this->delete("/categories/{$food->id}")
            ->assertSessionHasErrors(['delete' => '先に小分類を削除するか、別の大分類へ移してください']);
        $this->assertModelExists($food);
    }

    public function test_記録がある分類は削除できず_使わないにするかを聞かれる(): void
    {
        $food = $this->find('食費');
        Record::factory()->create(['category_id' => $food->id]);

        $this->get("/categories/{$food->id}/edit")->assertSee('記録があるので削除できません。『使わない』にしますか？');
        $this->delete("/categories/{$food->id}")->assertSessionHasErrors(['delete' => '記録があるので削除できません']);
        $this->assertModelExists($food);

        $this->patch("/categories/{$food->id}/deactivate")->assertSessionHas('status', '「使わない」にしました');
        $this->assertFalse($food->refresh()->is_active);
    }

    public function test_使わない大分類は小分類ごとその用のいちばん下に出て_使うに戻すと使う分類のいちばん下に入る(): void
    {
        $food = $this->find('食費');
        Category::factory()->childOf($food)->create(['name' => '外食']);

        $this->put("/categories/{$food->id}", ['name' => '食費', 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($food->refresh()->is_active);
        $this->get('/categories')->assertSeeInOrder(['支出の分類', '日用品', '税金・社会保険', 'その他', '食費', '（使わない）', '外食', '収入の分類']);

        $this->put("/categories/{$food->id}", ['name' => '食費', 'is_active' => '1']);
        $this->assertTrue($food->refresh()->is_active);
        $this->assertSame('食費', last($this->namesInOrder(CategoryKind::Expense)));
    }

    public function test_使わない小分類はその大分類の中のいちばん下に出る(): void
    {
        $car = $this->find('自動車');
        $wash = Category::factory()->childOf($car)->create(['name' => '洗車']);
        Category::factory()->childOf($car)->create(['name' => 'ガソリン']);

        $this->put("/categories/{$wash->id}", ['name' => '洗車', 'parent_id' => $car->id, 'is_active' => '0'])->assertSessionHasNoErrors();

        $this->get('/categories')->assertSeeInOrder(['自動車', 'ガソリン', '洗車', '（使わない）', 'ローン']);
    }

    public function test_使わない分類にはつまみが出ない(): void
    {
        $food = $this->find('食費');
        $food->update(['is_active' => false]);

        $daily = $this->find('日用品');

        $this->get('/categories')
            ->assertDontSee('id="handle-'.$food->id.'"', false)
            ->assertSee('id="handle-'.$daily->id.'"', false);
    }

    public function test_使わない小分類を使うに戻すと_その大分類の使う小分類のいちばん下に入る(): void
    {
        $car = $this->find('自動車');
        $wash = Category::factory()->childOf($car)->create(['name' => '洗車', 'is_active' => false]);
        Category::factory()->childOf($car)->create(['name' => 'ガソリン']);

        $this->put("/categories/{$wash->id}", ['name' => '洗車', 'parent_id' => $car->id, 'is_active' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(['ガソリン', '洗車'], $this->namesInOrder(CategoryKind::Expense, $car));
    }

    public function test_削除の小窓から使わないにできる(): void
    {
        $pet = Category::factory()->create(['name' => 'ペット']);

        $this->patch("/categories/{$pet->id}/deactivate")->assertRedirect('/categories#category-'.$pet->id);
        $this->assertFalse($pet->refresh()->is_active);
    }

    public function test_大分類を別の分類の下へは移せない(): void
    {
        $food = $this->find('食費');
        $daily = $this->find('日用品');

        $this->put("/categories/{$daily->id}", ['name' => '日用品', 'parent_id' => $food->id, 'is_active' => '1'])->assertSessionHasNoErrors();

        $this->assertNull($daily->refresh()->parent_id, '大分類は大分類のまま（送られてきた親は受けとらない）');
    }

    public function test_ない大分類の下には小分類を作れない(): void
    {
        $this->post('/categories', ['name' => '外食', 'parent_id' => 99999])->assertSessionHasErrors('parent_id');
        $this->assertDatabaseMissing('categories', ['name' => '外食']);
    }

    public function test_並べかえの順番が一覧の形でないときは受けとらない(): void
    {
        $before = $this->idsInOrder(CategoryKind::Expense);

        $this->patchJson('/categories/reorder', ['ids' => 'abc'])->assertStatus(422);
        $this->patchJson('/categories/reorder', ['ids' => ['a', 'b']])->assertStatus(422);
        $this->patchJson('/categories/reorder', [])->assertStatus(422);

        $this->assertSame($before, $this->idsInOrder(CategoryKind::Expense));
    }

    public function test_使わない分類をふくめた順番も保存できる(): void
    {
        $food = $this->find('食費');
        $food->update(['is_active' => false]);
        // 画面では、使う分類のあとに使わない分類が並んでいる。その順番のまま送られてくる
        $active = Category::query()->whereNull('parent_id')->where('kind', CategoryKind::Expense)->where('is_active', true)->orderBy('sort_order')->pluck('id')->all();

        $this->patchJson('/categories/reorder', ['ids' => [...array_reverse($active), $food->id]])->assertOk();

        $this->assertSame('食費', last($this->namesInOrder(CategoryKind::Expense)));
        $this->assertSame('その他', $this->namesInOrder(CategoryKind::Expense)[0]);
    }
}
