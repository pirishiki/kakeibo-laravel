<?php

namespace Tests\Feature\ReviewSweep88;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * イシュー #88：目標の追加に「送信用の合言葉」（submit_token）を入れる（画面設計書 5.13、2026-11-07 に決定。口座の追加と同じ）。
 * 書き方は tests/Feature/DoubleAddTest.php にそろえた
 */
class GoalDoubleAddTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(12, 0));
        $this->bank = Account::factory()->create(['name' => '貯金用の口座 A', 'type' => AccountType::Bank, 'initial_balance' => 120_000]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => '旅行', 'target_amount' => '300,000', 'account_id' => $this->bank->id,
            'deadline_year' => '', 'deadline_month' => '', 'submit_token' => 'token-g',
        ], $overrides);
    }

    public function test_目標の追加の画面には_送信用の合言葉が入っていて_開くたびに変わる(): void
    {
        $first = $this->get('/goals/create')->assertOk()->assertSee('name="submit_token"', false)->getContent();
        $second = $this->get('/goals/create')->getContent();

        preg_match('/name="submit_token" value="([^"]+)"/', (string) $first, $a);
        preg_match('/name="submit_token" value="([^"]+)"/', (string) $second, $b);
        $this->assertNotEmpty($a[1] ?? null);
        $this->assertNotSame($a[1] ?? null, $b[1] ?? null, '開くたびに別の合言葉');
    }

    public function test_同じ合言葉_同じ中身の2回目は作らず_保存しましたの流れで戻る(): void
    {
        $this->post('/goals', $this->form())->assertRedirect('/goals')->assertSessionHas('status', '保存しました');
        $this->post('/goals', $this->form())
            ->assertRedirect('/goals')->assertSessionHas('status', '保存しました')->assertSessionHasNoErrors();

        $this->assertSame(1, Goal::query()->count());
    }

    public function test_1回目のあとに目標が削除されても_同じ合言葉の2回目は作り直さず_保存していないと知らせる(): void
    {
        $this->post('/goals', $this->form())->assertRedirect('/goals');
        Goal::query()->delete();

        $response = $this->from('/goals/create')->post('/goals', $this->form());

        $this->assertSame(0, Goal::query()->count(), '作り直さない');
        // 「保存しました」と言わない（保存していないので、知らせる。文の置き場は設計書にないので、warning かまちがいのどちらでもよい）
        $this->assertNotSame('保存しました', session('status'));
        $this->assertTrue(session()->has('warning') || session()->has('errors'), '保存していないと知らせる');
        $response->assertSessionMissing('status');
    }

    public function test_1回目のあとに目標が直されていても_この口座はほかの目標で使っているとは出さず_保存しましたで戻る(): void
    {
        $this->post('/goals', $this->form())->assertRedirect('/goals');
        Goal::query()->where('account_id', $this->bank->id)->update(['target_amount' => 500_000, 'name' => '旅行（直した）']);

        $this->post('/goals', $this->form())
            ->assertRedirect('/goals')->assertSessionHas('status', '保存しました')->assertSessionHasNoErrors();

        $goal = Goal::query()->sole();
        $this->assertSame(500_000, $goal->target_amount, '直された中身を上書きしない');
        $this->assertSame('旅行（直した）', $goal->name);
    }

    public function test_同じ合言葉で別の中身を送ったときは_保存したと言わずに知らせる(): void
    {
        $this->post('/goals', $this->form())->assertRedirect('/goals');
        $other = Account::factory()->create(['name' => '貯金用の口座 B', 'type' => AccountType::Bank]);

        $this->post('/goals', $this->form(['name' => '車', 'account_id' => $other->id]))
            ->assertSessionHas('warning')->assertSessionMissing('status');

        $this->assertSame(1, Goal::query()->count());
    }

    public function test_別の画面で開いた追加_つまり合言葉がちがう別の中身は_今までどおり口座が使われていると知らせる(): void
    {
        // 同じ中身で合言葉だけがちがう場合は、設計書に決まりがない（中身での見分けを残すのか、合言葉だけにするのか）ので、ここでは確かめない
        $this->post('/goals', $this->form())->assertRedirect('/goals');

        $this->post('/goals', $this->form(['submit_token' => 'token-h', 'name' => '車']))
            ->assertSessionHasErrors('account_id')->assertSessionMissing('status');
        $this->assertSame(1, Goal::query()->count());
    }

    public function test_入力のまちがいで保存できなかったあとは_直して同じ合言葉で保存し直せる(): void
    {
        $this->post('/goals', $this->form(['name' => '']))->assertSessionHasErrors('name');

        $this->post('/goals', $this->form())->assertRedirect('/goals')->assertSessionHas('status', '保存しました');
        $this->assertSame(1, Goal::query()->count());
    }

    public function test_合言葉のない古い画面からの保存は_今までどおり動く(): void
    {
        $data = $this->form();
        unset($data['submit_token']);

        $this->post('/goals', $data)->assertRedirect('/goals');
        $this->assertSame(1, Goal::query()->count());
    }
}
