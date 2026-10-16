<?php

namespace Tests\Feature\Goals;

use App\Enums\AccountType;
use App\Enums\RecordType;
use App\Http\Requests\GoalRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\Record;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 貯金の目標の画面（画面設計書 5.13）と、目標が使っている口座の守り（4 章の 9）。
 * ほかの画面で直された・削除されたときなど、「まれでも困る」ことも確かめる
 */
class GoalScreenTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // 2026年10月16日（金）
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(12, 0));
        $this->bank = Account::factory()->create(['name' => '貯金用の口座 A', 'type' => AccountType::Bank, 'initial_balance' => 120_000]);
    }

    private function goal(array $values = []): Goal
    {
        return Goal::create([
            'name' => '旅行',
            'target_amount' => 300_000,
            'account_id' => $this->bank->id,
            'deadline_month' => '2027-08-01',
            ...$values,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(array $values = []): array
    {
        return [
            'name' => '旅行',
            'target_amount' => '300,000',
            'account_id' => $this->bank->id,
            'deadline_year' => '2027',
            'deadline_month' => '8',
            ...$values,
        ];
    }

    public function test_目標を追加すると一覧に進み具合と1か月あたりが出る(): void
    {
        $this->post(route('goals.store'), $this->form())
            ->assertRedirect(route('goals.index'))
            ->assertSessionHas('status', '保存しました');

        $this->assertDatabaseHas('goals', ['name' => '旅行', 'target_amount' => 300_000, 'account_id' => $this->bank->id, 'deadline_month' => '2027-08-01']);

        // 10 月〜来年 8 月は 11 か月。残り 180,000 ÷ 11 ＝ 16,363.6… → 切り上げて 16,364
        $this->get(route('goals.index'))
            ->assertOk()
            ->assertSeeInOrder(['旅行', '120,000', '300,000（40%）', '貯金用の口座 A・2027年8月まで', 'あと 11 か月、1 か月あたり 16,364']);
    }

    public function test_期限なしでも保存でき_年だけ選ぶとまちがいになる(): void
    {
        $this->post(route('goals.store'), $this->form(['deadline_year' => '', 'deadline_month' => '']))
            ->assertRedirect(route('goals.index'));
        $this->assertDatabaseHas('goals', ['name' => '旅行', 'deadline_month' => null]);

        Goal::query()->delete();
        $this->post(route('goals.store'), $this->form(['deadline_month' => '']))
            ->assertSessionHasErrors('deadline_month');
        $this->assertDatabaseCount('goals', 0);
    }

    public function test_変更の画面で直して保存できる(): void
    {
        $goal = $this->goal();

        $this->get(route('goals.edit', $goal))->assertOk()->assertSee('旅行');
        $this->put(route('goals.update', $goal), $this->form(['name' => '海外旅行', 'opened_at' => $goal->fingerprint()]))
            ->assertRedirect(route('goals.index'))
            ->assertSessionHas('status', '保存しました');

        $this->assertSame('海外旅行', $goal->fresh()->name);
    }

    public function test_開いたあとにほかの画面で直された目標は_古い画面の中身で上書きしない(): void
    {
        $goal = $this->goal();
        $opened = $goal->fingerprint();
        // 同じ秒のうちに、ほかの画面で目標額が直された（直した日時は同じ秒のまま）
        Goal::query()->whereKey($goal->id)->update(['target_amount' => 500_000]);

        $this->from(route('goals.edit', $goal))
            ->put(route('goals.update', $goal), $this->form(['name' => '海外旅行', 'opened_at' => $opened]))
            ->assertRedirect(route('goals.edit', $goal))
            ->assertSessionHasErrors('save');

        $this->assertSame('旅行', $goal->fresh()->name);
        $this->assertSame(500_000, $goal->fresh()->target_amount);
        // 打った内容は欄に残り、今の内容が文に出る
        $this->from(route('goals.edit', $goal))->followingRedirects()->put(route('goals.update', $goal), $this->form(['name' => '海外旅行', 'opened_at' => $opened]))->assertSee('海外旅行')->assertSee('500,000 円');
    }

    public function test_ほかの画面で削除された目標を開く_削除すると_エラーの画面にならずに知らせる(): void
    {
        $goal = $this->goal();
        $goal->delete();

        $this->get(route('goals.edit', $goal->id))
            ->assertRedirect(route('goals.index'))
            ->assertSessionHas('warning');
        // 削除を 2 回押したときも同じ道を通る
        $this->delete(route('goals.destroy', $goal->id))
            ->assertRedirect(route('goals.index'))
            ->assertSessionHas('status', 'この目標は、もう削除されています');
    }

    public function test_ほかの画面で削除された目標を保存すると_打った内容を追加の画面へ持っていく(): void
    {
        $goal = $this->goal();
        $goal->delete();

        $this->put(route('goals.update', $goal->id), $this->form(['name' => '海外旅行', 'opened_at' => 'x']))
            ->assertRedirect(route('goals.create'))
            ->assertSessionHas('warning')
            ->assertSessionHasInput('name', '海外旅行');
        $this->assertDatabaseCount('goals', 0);

        $this->get(route('goals.create'))->assertSee('value="海外旅行"', false)->assertSee('保存すると新しい目標として追加できます');
    }

    public function test_入力のまちがいで戻ったあとも_開いたあとに直されたことに気づく(): void
    {
        $goal = $this->goal();
        $opened = $goal->fingerprint();
        Goal::query()->whereKey($goal->id)->update(['target_amount' => 500_000]);

        // 期限の年だけを「なし」にして保存 → 入力のまちがいで戻る。戻った画面の印は、開いたときのまま
        $this->from(route('goals.edit', $goal))
            ->followingRedirects()
            ->put(route('goals.update', $goal), $this->form(['deadline_year' => '', 'opened_at' => $opened]))
            ->assertSee('value="'.$opened.'"', false);

        // 直して保存しても、ほかの画面の直しを上書きしない
        $this->from(route('goals.edit', $goal))
            ->put(route('goals.update', $goal), $this->form(['opened_at' => $opened]))
            ->assertSessionHasErrors('save');
        $this->assertSame(500_000, $goal->fresh()->target_amount);
    }

    public function test_保存を2回押して2回届いても_目標は1つで_保存しましたになる(): void
    {
        $this->post(route('goals.store'), $this->form())->assertSessionHas('status', '保存しました');
        $this->post(route('goals.store'), $this->form())->assertRedirect(route('goals.index'))->assertSessionHas('status', '保存しました');
        $this->assertDatabaseCount('goals', 1);

        $goal = Goal::query()->first();
        $opened = $goal->fingerprint();
        $this->put(route('goals.update', $goal), $this->form(['name' => '海外旅行', 'opened_at' => $opened]))->assertSessionHas('status', '保存しました');
        // 2 回目は印が古いが、中身が同じなので「保存しました」
        $this->put(route('goals.update', $goal), $this->form(['name' => '海外旅行', 'opened_at' => $opened]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '保存しました');
    }

    /**
     * 入力チェックが通ったあと、保存までのあいだに口座が変わったとき（ほかの画面で、カード・「使わない」・削除・ほかの目標に使われた）
     *
     * @return array<string, array{0: string}>
     */
    public static function accountChanges(): array
    {
        return [
            'クレジットカードになった' => ['card'],
            '使わないになった' => ['inactive'],
            '削除された' => ['deleted'],
            'ほかの目標に使われた' => ['taken'],
        ];
    }

    #[DataProvider('accountChanges')]
    public function test_チェックのあと保存までに口座が変わっても_決まりを破って保存しない(string $change): void
    {
        $free = Account::factory()->create(['name' => '貯金用の口座 B', 'type' => AccountType::Bank]);
        // 入力チェック（GoalRequest）が終わったところで、ほかの画面の変更を割りこませる
        $this->app->afterResolving(GoalRequest::class, function () use ($free, $change) {
            match ($change) {
                'card' => Account::query()->whereKey($free->id)->update(['type' => AccountType::CreditCard]),
                'inactive' => Account::query()->whereKey($free->id)->update(['is_active' => false]),
                'deleted' => Account::query()->whereKey($free->id)->delete(),
                'taken' => Goal::create(['name' => '車', 'target_amount' => 1_000, 'account_id' => $free->id]),
            };
        });

        $this->from(route('goals.create'))
            ->post(route('goals.store'), $this->form(['account_id' => $free->id]))
            ->assertRedirect(route('goals.create'))
            ->assertSessionHasErrors('account_id');
        $this->assertDatabaseMissing('goals', ['name' => '旅行']);
    }

    public function test_年をまたいで開いたままの画面から保存しても_打った年が欄から外れない(): void
    {
        $this->travelTo(now()->setDate(2027, 1, 1)->setTime(0, 1));

        $this->from(route('goals.create'))
            ->followingRedirects()
            ->post(route('goals.store'), $this->form(['deadline_year' => '2026', 'deadline_month' => '12']))
            ->assertSee('今月より前にはできません')
            ->assertSee('<option value="2026" selected', false);
    }

    public function test_期限は40年先まで(): void
    {
        $this->post(route('goals.store'), $this->form(['deadline_year' => '2066', 'deadline_month' => '12']))->assertRedirect(route('goals.index'));
        Goal::query()->delete();
        $this->post(route('goals.store'), $this->form(['deadline_year' => '2067', 'deadline_month' => '1']))->assertSessionHasErrors('deadline_year');
    }

    public function test_選べない口座の文(): void
    {
        $card = Account::factory()->create(['name' => 'カード', 'type' => AccountType::CreditCard]);
        $off = Account::factory()->create(['name' => '古い口座', 'type' => AccountType::Bank, 'is_active' => false]);

        $this->post(route('goals.store'), $this->form(['account_id' => $card->id]))->assertSessionHasErrors(['account_id' => 'クレジットカードは、貯金の目標には選べません。口座を選び直してください']);
        $this->post(route('goals.store'), $this->form(['account_id' => $off->id]))->assertSessionHasErrors(['account_id' => '「古い口座」は『使わない』口座なので選べません。口座を選び直してください']);
        $this->post(route('goals.store'), $this->form(['account_id' => 999_999]))->assertSessionHasErrors(['account_id' => 'この口座は、ほかの画面で削除されました。口座を選び直してください']);
    }

    public function test_削除しても口座と残高はそのまま(): void
    {
        $goal = $this->goal();

        $this->delete(route('goals.destroy', $goal))->assertRedirect(route('goals.index'))->assertSessionHas('status', '削除しました');

        $this->assertDatabaseCount('goals', 0);
        $this->assertSame(120_000, $this->bank->fresh()->balance());
    }

    public function test_ほかの目標で使っている口座・カード・使わない口座は選べない(): void
    {
        $this->goal();
        $card = Account::factory()->create(['name' => 'カード', 'type' => AccountType::CreditCard]);
        $off = Account::factory()->create(['name' => '古い口座', 'type' => AccountType::Bank, 'is_active' => false]);
        $free = Account::factory()->create(['name' => '貯金用の口座 B', 'type' => AccountType::Bank]);

        // 自分が使っている口座は、変更の画面で選べる
        $this->get(route('goals.edit', Goal::query()->first()))->assertSee('<option value="'.$this->bank->id.'" selected>貯金用の口座 A</option>', false);

        $this->get(route('goals.create'))
            ->assertOk()
            ->assertSee('貯金用の口座 B')
            ->assertDontSee('>貯金用の口座 A</option>', false)
            ->assertDontSee('カード</option>', false)
            ->assertDontSee('古い口座');

        foreach ([$this->bank, $card, $off] as $account) {
            $this->post(route('goals.store'), $this->form(['name' => '車', 'account_id' => $account->id]))
                ->assertSessionHasErrors('account_id');
        }
        $this->assertDatabaseCount('goals', 1);
        $this->post(route('goals.store'), $this->form(['name' => '車', 'account_id' => $free->id]))->assertRedirect(route('goals.index'));
    }

    public function test_期限を過ぎた目標は赤い字で出て_期限を変えずにほかの欄を直せる(): void
    {
        $goal = $this->goal(['deadline_month' => '2026-09-01']);

        $this->get(route('goals.index'))->assertSee('期限を過ぎました');
        // 変更の画面では、過ぎた年も選べるように残っている
        $this->get(route('goals.edit', $goal))->assertSee('<option value="2026" selected', false);

        $this->put(route('goals.update', $goal), $this->form(['name' => '旅行（延期）', 'deadline_year' => '2026', 'deadline_month' => '9']))
            ->assertRedirect(route('goals.index'));
        $this->assertSame('旅行（延期）', $goal->fresh()->name);

        // 期限をほかの過ぎた月に変えるのはできない
        $this->put(route('goals.update', $goal), $this->form(['deadline_year' => '2026', 'deadline_month' => '8']))
            ->assertSessionHasErrors('deadline_month');
        $this->assertSame('2026-09-01', $goal->fresh()->deadline_month->toDateString());
    }

    public function test_期限が今月なら_あと1か月で残りの額そのもの(): void
    {
        $this->goal(['deadline_month' => '2026-10-01']);

        $this->get(route('goals.index'))->assertSee('あと 1 か月、1 か月あたり 180,000');
    }

    public function test_達成した目標は下に出て_ホームには出ない(): void
    {
        $this->goal(['name' => '引っ越し', 'target_amount' => 120_000]);
        $other = Account::factory()->create(['name' => '貯金用の口座 B', 'type' => AccountType::Bank, 'initial_balance' => 10_000]);
        $this->goal(['name' => '車の買いかえ', 'target_amount' => 1_500_000, 'account_id' => $other->id, 'deadline_month' => null]);

        $this->get(route('goals.index'))->assertSeeInOrder(['車の買いかえ', '＋ 目標を追加', '達成した目標', '引っ越し', '✓ 達成'])
            // 達成した行は % を出さない（画面設計書 5.13 の図）
            ->assertSee('/ 120,000')->assertDontSee('（100%）');
        $this->get(route('home'))->assertSee('車の買いかえ')->assertDontSee('引っ越し');

        // 残高が目標額を下回ると、上に戻る
        Record::factory()->create(['account_id' => $this->bank->id, 'type' => RecordType::Expense, 'amount' => 1, 'date' => '2026-10-16']);
        $this->get(route('goals.index'))->assertSeeInOrder(['引っ越し', '車の買いかえ', '＋ 目標を追加'])->assertDontSee('達成した目標');
        $this->get(route('home'))->assertSee('引っ越し');
    }

    public function test_残高がマイナスなら0パーセントで棒は出ない(): void
    {
        $this->bank->update(['initial_balance' => -5_000]);
        $this->goal();

        $this->get(route('goals.index'))->assertSee('300,000（0%）')->assertDontSee('goal-bar');
    }

    public function test_目標が使っている口座は_削除も使わないもクレジットカードへの変更もできない(): void
    {
        $this->bank->update(['initial_balance' => 0]);
        $this->goal();

        $this->get(route('accounts.edit', $this->bank))->assertSee('貯金の目標「旅行」がこの口座を使っているので、削除できません')
            ->assertSee(route('goals.edit', Goal::query()->first()));

        $this->delete(route('accounts.destroy', $this->bank))->assertSessionHasErrors(['delete' => '貯金の目標「旅行」がこの口座を使っているので、削除できません。先に目標の口座を変えるか、目標を削除してください']);
        $this->patch(route('accounts.deactivate', $this->bank))->assertSessionHasErrors('is_active');

        $update = ['name' => $this->bank->name, 'type' => AccountType::Bank->value, 'initial_balance' => '0', 'reserve_type' => 'none', 'is_active' => '1'];
        $this->put(route('accounts.update', $this->bank), [...$update, 'is_active' => '0'])->assertSessionHasErrors('is_active');
        $this->put(route('accounts.update', $this->bank), [...$update, 'type' => AccountType::CreditCard->value])
            ->assertSessionHasErrors(['type' => '貯金の目標「旅行」がこの口座を使っているので、クレジットカードにできません。先に目標の口座を変えるか、目標を削除してください']);

        $account = $this->bank->fresh();
        $this->assertTrue($account->is_active);
        $this->assertSame(AccountType::Bank, $account->type);

        // 目標を削除すれば、口座も削除できる
        Goal::query()->delete();
        $this->delete(route('accounts.destroy', $this->bank))->assertRedirect(route('accounts.index'));
        $this->assertDatabaseMissing('accounts', ['id' => $this->bank->id]);
    }

    public function test_確かめたあと削除までに目標ができても_データベースが止めて目標の名前を出す(): void
    {
        $this->bank->update(['initial_balance' => 0]);
        // 削除の前の確かめ（目標を読む）が終わったところで、ほかの画面で目標ができた
        $inserted = false;
        DB::listen(function ($query) use (&$inserted) {
            if (! $inserted && str_contains($query->sql, 'from `goals`')) {
                $inserted = true;
                Goal::create(['name' => '旅行', 'target_amount' => 300_000, 'account_id' => $this->bank->id]);
            }
        });

        $this->delete(route('accounts.destroy', $this->bank))
            ->assertRedirect(route('accounts.edit', $this->bank))
            ->assertSessionHasErrors(['delete' => '貯金の目標「旅行」がこの口座を使っているので、削除できません。先に目標の口座を変えるか、目標を削除してください']);
        $this->assertDatabaseHas('accounts', ['id' => $this->bank->id]);
    }

    public function test_記録も目標もある口座の削除の小窓には_記録の理由と目標の文とリンクが出る(): void
    {
        $goal = $this->goal();
        Record::factory()->create(['account_id' => $this->bank->id, 'type' => RecordType::Expense, 'amount' => 1, 'date' => '2026-10-16']);

        $this->get(route('accounts.edit', $this->bank))
            ->assertSeeInOrder(['記録があるので削除できません。', '貯金の目標「旅行」がこの口座を使っているので、『使わない』にできません', route('goals.edit', $goal)])
            ->assertDontSee('「使わない」にする</button>', false);
    }

    public function test_同じ口座の目標はデータベースでも1つだけ(): void
    {
        $this->goal();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->goal(['name' => '車']);
    }

    public function test_ホームの目標のカードと_設定_メニューに貯金の目標が出る(): void
    {
        $this->get(route('home'))->assertSee('貯金の目標')->assertSee('まだ目標がありません。押すと追加できます');

        // 全部達成したとき
        $done = $this->goal(['target_amount' => 1]);
        $this->get(route('home'))->assertSee('全部の目標を達成しました');
        $done->delete();

        // ホームの並び：使える額と小さなカード（残高・収支・多く使った分類・貯金の目標）が、カレンダーより上
        $this->get(route('home'))->assertSeeInOrder(['使える額', '今の残高の合計', '今月の収支', '多く使った分類', '貯金の目標', 'のカレンダー', '最近の記録']);

        $this->goal();
        $this->get(route('home'))->assertSeeInOrder(['貯金の目標', '旅行', '40%']);
        $this->get(route('settings.index'))->assertSee('貯金の目標')->assertSee('（1 件）');
        $this->get(route('goals.index'))->assertSee('aria-current="page" >貯金の目標', false);
    }
}
