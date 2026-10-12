<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 要件定義書 10.1「ログイン（No.1、画面設計書 5.1）」の受け入れ基準を確かめる。
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-password-123';

    private function makeUser(): User
    {
        return User::factory()->create([
            'email' => 'me@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return array_merge(['email' => 'me@example.com', 'password' => self::PASSWORD], $overrides);
    }

    public function test_ログインしないで開くとログイン画面になる(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_ログイン画面にはメニューと登録やリセットへのリンクがなく最初からログインしたままにするにチェックがある(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('ログインしたままにする')
            ->assertDontSee('ログアウト')
            ->assertDontSee('/register')
            ->assertDontSee('forgot-password');
        $this->assertMatchesRegularExpression('/name="remember"[^>]*\schecked>/', $response->getContent());
    }

    public function test_ユーザー登録とパスワードリセットの画面はない(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'x'])->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/reset-password/token')->assertNotFound();
        // Fortify のパスワードの確認の URL も止めてある（このアプリには画面がない）
        $this->actingAs(User::factory()->create())->get('/user/confirm-password')->assertNotFound();
    }

    public function test_正しく入れるとホームが開く(): void
    {
        $user = $this->makeUser();

        $this->post('/login', $this->form())->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk()->assertSee('ログアウト');
    }

    public function test_ログイン前に開こうとした画面があればログイン後にその画面が開く(): void
    {
        Route::middleware(['web', 'auth'])->get('/test-intended', fn () => 'ok');
        $this->makeUser();

        $this->get('/test-intended')->assertRedirect('/login');
        $this->post('/login', $this->form())->assertRedirect('/test-intended');
    }

    public function test_パスワードがちがってもメールアドレスがちがっても同じ文が出る(): void
    {
        $this->makeUser();

        $this->from('/login')->post('/login', $this->form(['password' => 'wrong-password']))
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'メールアドレスかパスワードがちがいます。']);

        $this->from('/login')->post('/login', $this->form(['email' => 'other@example.com']))
            ->assertSessionHasErrors(['email' => 'メールアドレスかパスワードがちがいます。']);

        $this->assertGuest();
    }

    public function test_何も入れないときとメールアドレスの形がちがうときは欄ごとに文が出る(): void
    {
        $this->post('/login', ['email' => '', 'password' => ''])
            ->assertSessionHasErrors([
                'email' => 'メールアドレスを入れてください。',
                'password' => 'パスワードを入れてください。',
            ]);

        $this->post('/login', ['email' => 'me@', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => 'メールアドレスの形がちがいます。']);
    }

    public function test_1分に5回まちがえると6回目から60秒ログインできず60秒たつとまたためせる(): void
    {
        $user = $this->makeUser();

        // 5 回目までは、ふつうのまちがいの文（まだ止められていない）
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', $this->form(['password' => 'wrong-password']))
                ->assertSessionHasErrors(['email' => 'メールアドレスかパスワードがちがいます。']);
        }

        // 6 回目は、正しいパスワードでもログインできない
        $response = $this->post('/login', $this->form());
        $response->assertSessionHasErrors('email');
        $this->assertMatchesRegularExpression(
            '/^ログインの回数が多すぎます。\d+ 秒後にもう一度ためしてください。$/',
            session('errors')->first('email'),
        );
        $this->assertGuest();

        // 別のメールアドレスは、数えるものがちがうのでためせる
        $this->post('/login', $this->form(['email' => 'other@example.com']))
            ->assertSessionHasErrors(['email' => 'メールアドレスかパスワードがちがいます。']);

        // 60 秒たつと、またためせる
        $this->travel(61)->seconds();
        $this->post('/login', $this->form())->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_ログインしたままにするにチェックするとブラウザを閉じても続く印が残る(): void
    {
        $this->makeUser();

        $this->post('/login', $this->form(['remember' => '1']))
            ->assertCookie(Auth::guard()->getRecallerName());
    }

    public function test_チェックを外すとブラウザを閉じたらログアウトになる(): void
    {
        $this->makeUser();

        $response = $this->post('/login', $this->form())
            ->assertCookieMissing(Auth::guard()->getRecallerName());

        // ログインの印（セッションのクッキー）は、期限なし＝ブラウザを閉じると消える種類
        $sessionCookie = $response->getCookie(config('session.cookie'), false);
        $this->assertNotNull($sessionCookie);
        $this->assertSame(0, $sessionCookie->getExpiresTime());
    }

    public function test_ログアウトするとログイン画面に戻る(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }
}
