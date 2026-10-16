<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Livewire\AdjustBalance;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 開いた・保存した口座や分類などが、ほかの画面で削除されていたとき（app/Support/DeletedElsewhere.php）。
 * エラーの画面（404）を出さず、一覧へ戻して知らせる。変更の画面から保存したときは、打った内容を追加の画面へ持っていく
 */
class DeletedElsewhereTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_削除された口座の変更の画面を開くと_一覧で知らせる(): void
    {
        $this->get('/accounts/999/edit')
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('warning', 'この口座は、ほかの画面で削除されていました。');
        $this->get(route('accounts.index'))->assertSee('この口座は、ほかの画面で削除されていました。');
    }

    public function test_削除された口座を保存すると_打った内容を追加の画面へ持っていく(): void
    {
        $this->put('/accounts/999', ['name' => '旅行の財布', 'type' => 'cash', 'initial_balance' => '3,000', 'is_active' => '1', '_token' => 'x'])
            ->assertRedirect(route('accounts.create'))
            ->assertSessionHas('warning', 'この口座は、ほかの画面で削除されていました。保存していません。打った内容を入れておいたので、保存すると新しい口座として追加できます。')
            ->assertSessionHasInput('name', '旅行の財布');

        $this->get(route('accounts.create'))->assertSee('value="旅行の財布"', false);
        $this->assertSame(0, Account::query()->count());
    }

    public function test_削除された口座を使わないにする_削除する_合わせる画面を開くと_一覧で知らせる(): void
    {
        $this->patch('/accounts/999/deactivate')
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('warning', 'この口座は、ほかの画面で削除されていました。何もしていません。');
        // 削除を 2 回押して 2 回届いたときも同じ
        $this->delete('/accounts/999')
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('status', 'この口座は、もう削除されています');
        $this->get('/accounts/999/adjust')->assertRedirect(route('accounts.index'));
    }

    public function test_削除された分類を保存すると_同じ用の追加の画面へ持っていく(): void
    {
        $this->put('/categories/999', ['name' => 'ボーナス', 'kind' => 'income', 'is_active' => '1'])
            ->assertRedirect(route('categories.create', ['kind' => 'income']))
            ->assertSessionHasInput('name', 'ボーナス');
    }

    public function test_削除された小分類を保存すると_元の大分類の下に追加する画面へ持っていく(): void
    {
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);

        $this->put('/categories/999', ['name' => '外食', 'parent_id' => (string) $food->id, 'is_active' => '1'])
            ->assertRedirect(route('categories.create', ['parent' => $food->id]))
            ->assertSessionHasInput('name', '外食');
    }

    public function test_小分類も大分類も削除されていたら_打った名前を文で知らせる(): void
    {
        $this->put('/categories/999', ['name' => '外食', 'parent_id' => '998', 'is_active' => '1'])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('warning', 'この分類は、ほかの画面で削除されていました。何もしていません。');

        // 大分類の下に追加する画面で、その大分類が削除されていた（保存して戻ってきたときは、打った名前を出す）
        $this->withSession(['_old_input' => ['name' => 'ランチ']])
            ->get('/categories/create?parent=998')
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('warning', 'この大分類は、ほかの画面で削除されていました。打った名前「ランチ」は保存していません。');
    }

    public function test_分類の変更の画面は_削除のときのために収入用か支出用かを隠して送る(): void
    {
        $salary = Category::factory()->create(['name' => '臨時の収入', 'kind' => CategoryKind::Income]);

        $this->get(route('categories.edit', $salary))->assertSee('<input type="hidden" name="kind" value="income">', false);
    }

    public function test_削除された相手を保存する_使わないにする_まとめると_一覧で知らせる(): void
    {
        $this->put('/partners/999', ['name' => 'パン屋', 'is_active' => '1'])
            ->assertRedirect(route('partners.create'))
            ->assertSessionHasInput('name', 'パン屋');
        $this->patch('/partners/999/deactivate')->assertRedirect(route('partners.index'));
        $this->post('/partners/999/merge', ['merge_target' => 'スーパー'])->assertRedirect(route('partners.index'));
    }

    public function test_まとめるを2回押して2回届いても_エラーの画面にしない(): void
    {
        $from = Partner::factory()->create(['name' => 'コンビニA']);
        Partner::factory()->create(['name' => 'コンビニ']);

        $this->post(route('partners.merge', $from), ['merge_target' => 'コンビニ'])->assertRedirect(route('partners.index'));
        // 2 回目は、もう相手がないので、URL の番号で探せない
        $this->post('/partners/'.$from->id.'/merge', ['merge_target' => 'コンビニ'])
            ->assertRedirect(route('partners.index'));
    }

    public function test_まとめ先が保存までに削除されていたら_選び直してもらう(): void
    {
        $from = Partner::factory()->create(['name' => 'コンビニA']);
        $to = Partner::factory()->create(['name' => 'コンビニ']);
        // 入力のチェック（まとめ先があるかを数える）のすぐあと、まとめ先を探すまでのあいだに、ほかの画面で削除された
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($to, &$done) {
            if (! $done && str_contains($query->sql, 'count(*)') && str_contains($query->sql, '`partners`')) {
                $done = true;
                Partner::query()->whereKey($to->id)->delete();
            }
        });

        $this->from(route('partners.edit', $from))
            ->post(route('partners.merge', $from), ['merge_target' => 'コンビニ'])
            ->assertRedirect(route('partners.edit', $from))
            ->assertSessionHas('warning', '選んだ相手は、ほかの画面で削除されていたので、保存していません。選び直してください。')
            ->assertSessionHasInput('merge_target', 'コンビニ');
        $this->assertTrue($done);
        $this->assertTrue(Partner::query()->where('name', 'コンビニA')->exists());
        $this->assertFalse(Partner::query()->where('name', 'コンビニ')->exists());
    }

    public function test_削除された記録と定期的な記録を開くと_一覧で知らせる(): void
    {
        $this->get('/recurrings/999/edit')
            ->assertRedirect(route('recurrings.index'))
            ->assertSessionHas('warning', 'この定期的な記録は、ほかの画面で削除されていました。');
        $this->get('/records/999/edit')->assertRedirect(route('records.index'));
    }

    /**
     * URL の番号で行を読んだすぐあとに、ほかの画面で削除されたことにする
     *
     * @param  class-string<Category|Partner>  $model
     */
    private function deleteRightAfterRouteLoads(string $model, int $id): void
    {
        $done = false;
        $model::retrieved(function () use ($model, $id, &$done) {
            if (! $done) {
                $done = true;
                $model::query()->whereKey($id)->delete();
            }
        });
    }

    public function test_開いたあと保存までに削除された分類は_保存しましたと出さない(): void
    {
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);
        $this->deleteRightAfterRouteLoads(Category::class, $food->id);

        $this->put(route('categories.update', $food), ['name' => '食べもの', 'is_active' => '1', 'kind' => 'expense'])
            ->assertRedirect(route('categories.create', ['kind' => 'expense']))
            ->assertSessionHasInput('name', '食べもの')
            ->assertSessionMissing('status');
        $this->assertFalse(Category::query()->whereIn('name', ['趣味のもの', '食べもの'])->exists());
    }

    public function test_開いたあと保存までに削除された相手は_保存しましたと出さない(): void
    {
        $shop = Partner::factory()->create(['name' => 'パン屋']);
        $this->deleteRightAfterRouteLoads(Partner::class, $shop->id);

        $this->put(route('partners.update', $shop), ['name' => 'パン屋さん', 'is_active' => '1'])
            ->assertRedirect(route('partners.create'))
            ->assertSessionHasInput('name', 'パン屋さん')
            ->assertSessionMissing('status');
        $this->assertFalse(Partner::query()->where('name', 'like', 'パン屋%')->exists());
    }

    public function test_開いたあと使わないにするまでに削除された分類と相手は_使わないにしましたと出さない(): void
    {
        $food = Category::factory()->create(['name' => '趣味のもの']);
        $this->deleteRightAfterRouteLoads(Category::class, $food->id);
        $this->patch(route('categories.deactivate', $food))
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('warning', 'この分類は、ほかの画面で削除されていました。何もしていません。');

        $shop = Partner::factory()->create(['name' => 'パン屋']);
        $this->deleteRightAfterRouteLoads(Partner::class, $shop->id);
        $this->patch(route('partners.deactivate', $shop))
            ->assertRedirect(route('partners.index'))
            ->assertSessionMissing('status');
    }

    public function test_残高を合わせる画面を開いたまま口座が削除されたら_一覧へ戻して知らせる(): void
    {
        $account = Account::factory()->create(['name' => '財布']);
        $component = Livewire::test(AdjustBalance::class, ['account' => $account]);

        Account::query()->whereKey($account->id)->delete();

        $component->set('actual', '1000')
            ->assertRedirect(route('accounts.index'));
        $this->assertSame('この口座は、ほかの画面で削除されていたので、合わせていません。', session('warning'));
        $this->assertSame(0, Record::query()->count());
    }

    public function test_残高を合わせる保存の前に口座が削除されたら_記録を作らない(): void
    {
        $account = Account::factory()->create(['name' => '財布']);
        $component = Livewire::test(AdjustBalance::class, ['account' => $account])->set('actual', '1000');

        Account::query()->whereKey($account->id)->delete();

        $component->call('save')->assertRedirect(route('accounts.index'));
        $this->assertSame(0, Record::query()->count());
    }

    public function test_決まっていない種類の行は_今までどおり404(): void
    {
        // 記録・口座などの行の番号ではない、ないページ
        $this->get('/no-such-page')->assertNotFound();
        $this->assertSame(0, Recurring::query()->count());
    }
}
