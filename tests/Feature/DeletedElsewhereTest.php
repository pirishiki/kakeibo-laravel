<?php

namespace Tests\Feature;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Livewire\AdjustBalance;
use App\Livewire\RecordForm;
use App\Livewire\RecurringForm;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\User;
use App\Support\ConcurrencyError;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PDOException;
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
            ->assertSessionHas('warning', 'この分類は、ほかの画面で削除されていました。保存していません。打った名前は「外食」でした。');

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

    /**
     * Livewire の画面を開いたあと、本物のブラウザと同じ通り道（/livewire/update）で、打った・押したことを送る。
     * Livewire::test はこの通り道を通らないので、URL の番号から行を探す処理がもう一度動くかは、これで確かめる
     *
     * @param  array<string, mixed>  $updates
     * @param  list<array<string, mixed>>  $calls
     */
    private function livewireUpdate(string $html, array $updates, array $calls = []): TestResponse
    {
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);

        return $this->withHeaders(['X-Livewire' => '1'])->postJson(route('default-livewire.update'), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => html_entity_decode($m[1], ENT_QUOTES), 'updates' => $updates, 'calls' => $calls]],
        ]);
    }

    public function test_livewireの画面を開いたまま行が削除されても_打つ_押すで404の小窓を出さない(): void
    {
        $account = Account::factory()->create(['name' => '財布']);
        $html = $this->get(route('accounts.adjust', $account))->assertOk()->getContent();
        Account::query()->whereKey($account->id)->delete();
        $this->livewireUpdate((string) $html, ['actual' => '1000'])->assertOk()->assertJsonPath('components.0.effects.redirect', route('accounts.index'));

        $record = Record::factory()->create();
        $html = $this->get(route('records.edit', $record))->assertOk()->getContent();
        $record->delete();
        $this->livewireUpdate((string) $html, ['amount' => '2,000'], [['path' => '', 'method' => 'save', 'params' => []]])
            ->assertOk();

        $rent = Recurring::factory()->create();
        $html = $this->get(route('recurrings.edit', $rent))->assertOk()->getContent();
        $rent->delete();
        $this->livewireUpdate((string) $html, ['name' => '家賃（直した）'])->assertOk();
    }

    public function test_打った内容を持って変更の画面へ戻る途中で削除されていたら_追加の画面へ持っていく(): void
    {
        // 入力のまちがい・時間切れで戻ってきた（old() がある）
        $this->withSession(['_old_input' => ['name' => '旅行の財布', 'type' => 'cash', 'is_active' => '0']])
            ->get('/accounts/999/edit')
            ->assertRedirect(route('accounts.create'))
            ->assertSessionHasInput('name', '旅行の財布')
            ->assertSessionHas('warning', 'この口座は、ほかの画面で削除されていました。打った内容を入れておいたので、保存すると新しい口座として追加できます。「使わない」は、追加したあとに変更の画面で選んでください。');
    }

    public function test_ログインし直して変更の画面へ戻る途中で削除されていたら_取っておいた入力を追加の画面へ持っていく(): void
    {
        $this->withSession(['expired_input' => ['url' => url('/partners/999/edit'), 'input' => ['name' => 'パン屋'], 'at' => now()->timestamp]])
            ->get('/partners/999/edit')
            ->assertRedirect(route('partners.create'))
            ->assertSessionHasInput('name', 'パン屋')
            ->assertSessionMissing('expired_input');
    }

    public function test_まとめるを2回押したときは_まとめられていると伝える(): void
    {
        $this->post('/partners/999/merge', ['merge_target' => 'コンビニ'])
            ->assertRedirect(route('partners.index'))
            ->assertSessionHas('status', 'この相手は、もうまとめられているか、削除されています');
    }

    /** 押さえて読む（共有ロック）問い合わせが、ある表に届いた瞬間に、一度だけ $then を動かす */
    private function onSharedLock(string $table, callable $then): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($table, $then, &$done) {
            if (! $done && str_contains($query->sql, '`'.$table.'`') && (str_contains($query->sql, 'lock in share mode') || str_contains($query->sql, 'for share'))) {
                $done = true;
                $then();
            }
        });
    }

    public function test_追加の保存の途中で大分類が削除されたら_エラーの画面にせずに選び直してもらう(): void
    {
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);
        Category::creating(fn () => Category::query()->whereKey($food->id)->delete());

        $this->from(route('categories.create', ['parent' => $food->id]))
            ->post('/categories', ['name' => 'ランチ', 'parent_id' => (string) $food->id])
            ->assertSessionHasErrors(['parent_id' => '選んだ大分類は、ほかの画面で削除されていたので、保存していません。選び直してください']);
    }

    public function test_使わないにする確かめのあと_動いている予定が使うようになっていたら_使わないにしない(): void
    {
        $shop = Partner::factory()->create(['name' => 'パン屋']);
        // 確かめ（押さえる前）のあと、押さえるまでに、ほかの画面で動いている予定がこの相手を使うようになった
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($shop, &$done) {
            if (! $done && str_contains($query->sql, 'for update') && str_contains($query->sql, '`partners`')) {
                $done = true;
                Recurring::factory()->create(['partner_id' => $shop->id]);
            }
        });

        $this->patch(route('partners.deactivate', $shop))->assertSessionHasErrors(['is_active' => Recurring::IN_USE_MESSAGE]);
        $this->assertTrue((bool) $shop->fresh()?->is_active);
    }

    public function test_記録の保存の途中で分類や相手が削除されたら_エラーの画面にしない(): void
    {
        $account = Account::factory()->create();
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);
        $form = Livewire::test(RecordForm::class)->set('amount', '500')->set('categoryId', $food->id)->set('accountId', $account->id)->set('partnerName', 'パン屋');

        // 口座を押さえた瞬間に、分類がほかの画面で削除された
        $this->onSharedLock('accounts', fn () => Category::query()->whereKey($food->id)->delete());
        $form->call('save')->assertHasErrors(['blocker' => '選んだ分類は、ほかの画面で削除されたので、選べません。分類を選び直してください']);
        $this->assertSame(0, Record::query()->count());
    }

    public function test_記録の保存の途中で打った名前の相手が削除されたら_作り直して保存する(): void
    {
        $account = Account::factory()->create();
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);
        $shop = Partner::factory()->create(['name' => 'パン屋']);
        $form = Livewire::test(RecordForm::class)->set('amount', '500')->set('categoryId', $food->id)->set('accountId', $account->id)->set('partnerName', 'パン屋');

        // 名前で見つけたすぐあと（押さえる前）に、ほかの画面で削除された
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($shop, &$done) {
            if (! $done && str_contains($query->sql, 'from `partners` where `name`') && ! str_contains($query->sql, 'share')) {
                $done = true;
                Partner::query()->whereKey($shop->id)->delete();
            }
        });

        $form->call('save')->assertHasNoErrors();
        $this->assertTrue($done);
        $this->assertSame('パン屋', Record::query()->sole()->partner?->name);
    }

    public function test_定期の保存の途中で分類が使わないにされたら_保存しない(): void
    {
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);
        $rent = Recurring::factory()->create(['type' => RecordType::Expense, 'to_account_id' => null, 'category_id' => $food->id,
            'partner_id' => Partner::factory()->create(['name' => '大家さん'])->id]);
        $form = Livewire::test(RecurringForm::class, ['recurring' => $rent])->set('name', '直した');
        $categoryId = $food->id;

        $this->onSharedLock('accounts', fn () => Category::query()->whereKey($categoryId)->update(['is_active' => false]));
        $form->call('save')->assertHasErrors(['save' => '分類「趣味のもの」は、ほかの画面で『使わない』にされたので、選べません。分類を選び直してください']);
        $this->assertNotSame('直した', $rent->fresh()?->name);
    }

    public function test_記録の保存の途中で打った名前の相手の名前が変わったら_打った名前の相手で保存する(): void
    {
        $account = Account::factory()->create();
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);
        $shop = Partner::factory()->create(['name' => 'スーパー']);
        $form = Livewire::test(RecordForm::class)->set('amount', '500')->set('categoryId', $food->id)->set('accountId', $account->id)->set('partnerName', 'スーパー');

        // 名前で見つけたすぐあと（押さえる前）に、ほかの画面でその相手の名前が変わった
        $done = false;
        DB::listen(function (QueryExecuted $query) use ($shop, &$done) {
            if (! $done && str_contains($query->sql, 'from `partners` where `name`') && ! str_contains($query->sql, 'share')) {
                $done = true;
                Partner::query()->whereKey($shop->id)->update(['name' => 'スーパーA']);
            }
        });

        $form->call('save')->assertHasNoErrors();
        $this->assertTrue($done);
        $this->assertSame('スーパー', Record::query()->sole()->partner?->name);
    }

    public function test_まとめる欄だけを持って戻る途中で相手が削除されていたら_追加の画面へは移らない(): void
    {
        $this->withSession(['_old_input' => ['merge_target' => 'コンビニ']])
            ->get('/partners/999/edit')
            ->assertRedirect(route('partners.index'))
            ->assertSessionHas('warning', 'この相手は、ほかの画面で削除されていました。');
    }

    /** ほかの画面の保存と押さえあって、データベースに止められたことにする */
    private static function deadlock(): QueryException
    {
        return new QueryException('mysql', 'update', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
    }

    public function test_押さえあいでやり直しを使い切ったら_エラーの画面でなく入力を残して知らせる(): void
    {
        $shop = Partner::factory()->create(['name' => 'パン屋']);
        $tries = 0;
        Partner::updating(function () use (&$tries) {
            $tries++;
            throw self::deadlock();
        });

        $this->from(route('partners.edit', $shop))
            ->put(route('partners.update', $shop), ['name' => 'パン屋さん', 'is_active' => '1'])
            ->assertRedirect(route('partners.edit', $shop))
            ->assertSessionHas('warning', ConcurrencyError::MESSAGE)
            ->assertSessionHasInput('name', 'パン屋さん');
        $this->assertGreaterThanOrEqual(1, $tries);
        $this->assertSame('パン屋', $shop->fresh()?->name);
    }

    public function test_livewireの画面で押さえあいでやり直しを使い切ったら_小窓でなく画面の上で知らせる(): void
    {
        $account = Account::factory()->create();
        $food = Category::factory()->create(['name' => '趣味のもの', 'kind' => CategoryKind::Expense]);
        Record::creating(fn () => throw self::deadlock());

        Livewire::test(RecordForm::class)->set('amount', '500')->set('categoryId', $food->id)->set('accountId', $account->id)->set('partnerName', 'パン屋')
            ->call('save')
            ->assertDispatched('page-alert', message: ConcurrencyError::MESSAGE)
            ->assertSet('amount', '500');
        $this->assertSame(0, Record::query()->count());
    }

    public function test_refererがなくても_入力のまちがいはフォームを送った画面へ戻す(): void
    {
        $shop = Partner::factory()->create(['name' => 'パン屋']);
        // ほかのタブで、最後に口座の画面を開いていた
        $this->get(route('accounts.create'));

        $this->put(route('partners.update', $shop), ['name' => '', 'is_active' => '1', '_from' => '/partners/'.$shop->id.'/edit'])
            ->assertRedirect(url('/partners/'.$shop->id.'/edit'))
            ->assertSessionHasErrors('name');
    }

    public function test_送った画面に改行やほかのサイトが入っていたら_使わない(): void
    {
        $shop = Partner::factory()->create(['name' => 'パン屋']);
        foreach (["/partners\r\nX-Evil: 1", '//evil.example', '/\\evil.example', '/a b'] as $from) {
            $response = $this->put(route('partners.update', $shop), ['name' => '', 'is_active' => '1', '_from' => $from]);
            $response->assertRedirect();
            $location = (string) $response->headers->get('Location');
            $this->assertStringStartsWith(url('/'), $location);
            $this->assertStringNotContainsString('evil', $location);
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $location);
        }
    }

    public function test_押さえあいで戻り先がわからないときは_入力を持たずにホームで知らせる(): void
    {
        $shop = Partner::factory()->create(['name' => 'パン屋']);
        Partner::updating(fn () => throw self::deadlock());
        $this->get(route('accounts.create'));

        $this->put(route('partners.update', $shop), ['name' => 'パン屋さん', 'is_active' => '1'])
            ->assertRedirect(route('home'))
            ->assertSessionHas('warning', 'ほかの画面の保存と重なったので、保存できませんでした。画面を開き直して、もう一度入れてください。')
            ->assertSessionMissing('_old_input');
    }

    public function test_決まっていない種類の行は_今までどおり404(): void
    {
        // 記録・口座などの行の番号ではない、ないページ
        $this->get('/no-such-page')->assertNotFound();
        $this->assertSame(0, Recurring::query()->count());
    }
}
