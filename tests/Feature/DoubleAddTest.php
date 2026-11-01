<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\User;
use App\Support\SubmitOnce;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 口座・分類・相手の追加で、保存が 2 回届いたときの 2 回目（画面設計書 5.6・5.8・5.9 の追加、5.4 の「保存が 2 回届いても」と同じ考え方）。
 * 1 回目は保存できているのに、2 回目に「その名前は使われています」と出て、失敗したように見えないこと。
 * 追加の画面を開くたびに「送信用の合言葉」（submit_token）を入れ、同じ合言葉・同じ中身の 2 回目は、1 回目と同じ流れで戻る
 */
class DoubleAddTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** @return array<string, string> */
    private function account(array $overrides = []): array
    {
        return array_merge(['name' => 'みずほ銀行', 'type' => 'bank', 'initial_balance' => '350000', 'is_active' => '1', 'submit_token' => 'token-a'], $overrides);
    }

    public function test_追加の画面には_送信用の合言葉が入っていて_開くたびに変わる(): void
    {
        foreach (['/accounts/create', '/partners/create', '/categories/create'] as $path) {
            $first = $this->get($path)->assertOk()->assertSee('name="submit_token"', false)->getContent();
            $second = $this->get($path)->getContent();

            preg_match('/name="submit_token" value="([^"]+)"/', (string) $first, $a);
            preg_match('/name="submit_token" value="([^"]+)"/', (string) $second, $b);
            $this->assertNotEmpty($a[1] ?? null, $path);
            $this->assertNotSame($a[1] ?? null, $b[1] ?? null, $path.'：開くたびに別の合言葉');
        }
    }

    public function test_口座の保存が2回届いても_1件だけで_2回目も追加したのと同じ流れで戻る(): void
    {
        $this->post('/accounts', $this->account())->assertRedirect('/accounts')->assertSessionHas('status', '保存しました');
        $this->post('/accounts', $this->account())
            ->assertRedirect('/accounts')->assertSessionHas('status', '保存しました')->assertSessionHasNoErrors();

        $this->assertSame(1, Account::query()->count());
    }

    public function test_相手の保存が2回届いても_1件だけで_2回目も追加したのと同じ流れで戻る(): void
    {
        $this->post('/partners', ['name' => 'コンビニ', 'submit_token' => 'token-p'])->assertRedirect('/partners');
        $this->post('/partners', ['name' => 'コンビニ', 'submit_token' => 'token-p'])
            ->assertRedirect('/partners')->assertSessionHas('status', '保存しました')->assertSessionHasNoErrors();

        $this->assertSame(1, Partner::query()->where('name', 'コンビニ')->count());
    }

    public function test_分類の保存が2回届いても_1件だけで_2回目も追加したのと同じ流れで戻る(): void
    {
        $data = ['name' => 'ペット', 'kind' => 'expense', 'submit_token' => 'token-c'];

        $first = $this->post('/categories', $data)->assertSessionHasNoErrors();
        $this->post('/categories', $data)
            ->assertRedirect($first->headers->get('Location'))->assertSessionHas('status', '保存しました')->assertSessionHasNoErrors();

        $this->assertSame(1, Category::query()->where('name', 'ペット')->count());
    }

    public function test_保存したあと同じ合言葉で別の内容を送ったときは_保存したと言わずに知らせる(): void
    {
        $this->post('/accounts', $this->account())->assertRedirect('/accounts');

        // ブラウザの「戻る」で保存済みの画面に戻り、別の口座を入れて保存した（同じ合言葉で届く）
        $this->post('/accounts', $this->account(['name' => 'ゆうちょ']))
            ->assertSessionHas('warning')->assertSessionMissing('status');

        $this->assertSame(1, Account::query()->count());
        $this->assertDatabaseMissing('accounts', ['name' => 'ゆうちょ']);
    }

    public function test_別の画面で開いたもの_つまり合言葉がちがう同じ名前は_今までどおり使われていると知らせる(): void
    {
        $this->post('/accounts', $this->account())->assertRedirect('/accounts');

        // 画面を開き直して、同じ名前をもう一度入れた。「追加しました」と言わない（2 件目は作れない）
        $this->post('/accounts', $this->account(['submit_token' => 'token-b']))
            ->assertSessionHasErrors('name')->assertSessionMissing('status');
        $this->assertSame(1, Account::query()->count());
    }

    public function test_合言葉のない古い画面からの保存は_今までどおり動く(): void
    {
        $data = $this->account();
        unset($data['submit_token']);

        $this->post('/accounts', $data)->assertRedirect('/accounts');
        $this->post('/accounts', $data)->assertSessionHasErrors('name');
        $this->assertSame(1, Account::query()->count());
    }

    public function test_入力のまちがいで保存できなかったあとは_直して同じ合言葉で保存し直せる(): void
    {
        $this->post('/accounts', $this->account(['name' => '']))->assertSessionHasErrors('name');

        // 合言葉は使い切っていない。直して保存し直せる
        $this->post('/accounts', $this->account())->assertRedirect('/accounts')->assertSessionHas('status', '保存しました');
        $this->assertSame(1, Account::query()->count());
    }

    public function test_別の内容の知らせでは_入れた内容を残し_文には保存済みのことと次の操作を書く(): void
    {
        $this->post('/accounts', $this->account())->assertRedirect('/accounts');

        $this->from('/accounts/create')->post('/accounts', $this->account(['name' => 'ゆうちょ']))
            ->assertSessionHasInput('name', 'ゆうちょ')
            ->assertSessionHas('warning', fn (string $w) => str_contains($w, 'もう保存しています'));
    }

    public function test_保存中に2回目が届いたときは_知らせたあとにもう一度押せば_1回目が保存済みなら保存したのと同じ流れで戻る(): void
    {
        // 1 回目がまだ終わっていない（保存中）ところへ 2 回目が届いた。同じ中身
        $hash = SubmitOnce::hash(collect($this->account())->except('submit_token')->all());
        Cache::put('submitted:token-a', ['state' => 'saving', 'hash' => $hash], now()->addDay());

        $this->from('/accounts/create')->post('/accounts', $this->account())
            ->assertSessionHas('warning', fn (string $w) => str_contains($w, 'いま保存しています'))
            ->assertSessionHasInput('submit_token', 'token-a');

        // 知らせのあと、1 回目が保存できた。案内どおりにもう一度押す（画面は同じ合言葉を引きついでいる）
        Account::factory()->create(['name' => 'みずほ銀行']);
        SubmitOnce::saved('token-a', $hash, ['to' => url('/accounts')]);

        $this->post('/accounts', $this->account())
            ->assertRedirect('/accounts')->assertSessionHas('status', '保存しました')->assertSessionHasNoErrors();
        $this->assertSame(1, Account::query()->count());
    }

    public function test_保存中の知らせで描き直した追加の画面は_同じ合言葉を引きつぐ(): void
    {
        $hash = SubmitOnce::hash(collect($this->account())->except('submit_token')->all());
        Cache::put('submitted:token-a', ['state' => 'saving', 'hash' => $hash], now()->addDay());
        $this->from('/accounts/create')->post('/accounts', $this->account());

        // 追加の画面を開き直すと（入れた内容と、保存中の合言葉が残っている）、同じ合言葉が入っている
        $this->get('/accounts/create')->assertSee('value="token-a"', false);
    }

    public function test_前のリクエストの保存しましたが残っていても_まちがいなしの失敗は保存できたと見なさず_合言葉を戻す(): void
    {
        // 別のタブで口座を保存した直後で、その移動先がまだ開かれていない（「保存しました」の知らせがセッションに残っている）
        $this->post('/accounts', $this->account(['submit_token' => 'token-x']))->assertRedirect('/accounts');

        // 相手の追加が、ほかの画面と押さえあって、やり直しを使い切った（まちがいの知らせなしの失敗。ConcurrencyError）
        $calls = 0;
        Partner::creating(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new QueryException('mysql', 'insert', [], new \PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001));
            }
        });
        $this->post('/partners', ['name' => 'コンビニ', 'submit_token' => 'token-p'])->assertSessionHas('warning');
        $this->assertSame(0, Partner::query()->where('name', 'コンビニ')->count());

        // 同じ合言葉・同じ中身で再送する。「保存しました」と言わずに、ちゃんと追加される
        $this->post('/partners', ['name' => 'コンビニ', 'submit_token' => 'token-p'])->assertRedirect('/partners');
        $this->assertSame(1, Partner::query()->where('name', 'コンビニ')->count());
    }

    public function test_小分類の保存が2回届いても_1件だけ(): void
    {
        $food = Category::query()->whereNull('parent_id')->where('name', '食費')->firstOrFail();
        $data = ['name' => '外食', 'parent_id' => (string) $food->id, 'submit_token' => 'token-s'];

        $this->post('/categories', $data)->assertSessionHasNoErrors();
        $this->post('/categories', $data)->assertSessionHas('status', '保存しました')->assertSessionHasNoErrors();

        $this->assertSame(1, Category::query()->where('name', '外食')->where('parent_id', $food->id)->count());
    }

    public function test_データベースのキャッシュでも_口座の2回目は1件だけにする(): void
    {
        config(['cache.default' => 'database']);
        $this->app['cache']->forgetDriver('database');

        $this->post('/accounts', $this->account())->assertRedirect('/accounts');
        $this->post('/accounts', $this->account())->assertRedirect('/accounts')->assertSessionHas('status', '保存しました');

        $this->assertSame(1, Account::query()->count());
    }

    public function test_中身の印は_正しくない文字コードの値が入っていても計算できる(): void
    {
        // json_encode だと失敗して例外になる値。印の計算で、エラーの画面にならない
        $this->assertSame(SubmitOnce::hash(['name' => "\xB1\x31"]), SubmitOnce::hash(['name' => "\xB1\x31"]));
        $this->assertNotSame(SubmitOnce::hash(['name' => "\xB1\x31"]), SubmitOnce::hash(['name' => "\xB1\x32"]));
    }
}
