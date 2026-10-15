<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 画面を開いたまま時間がたって、フォームの合言葉（CSRF トークン）が古くなったとき（419）。
 * 英語のエラーの画面で入力を消さず、前の画面に戻して入れた内容を残し、知らせる（bootstrap/app.php）
 */
class SessionExpiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // テストでは合言葉の確かめが止まっているので、古くなったときと同じ例外を出す送り先を作る
        Route::middleware('web')->post('/_expired', fn () => throw new TokenMismatchException);
    }

    public function test_ログインしているときは_前の画面に戻り_入れた内容が残る(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/accounts/create')
            ->post('/_expired', ['name' => 'みずほ銀行', 'initial_balance' => '350,000', 'password' => 'secret'])
            ->assertRedirect('/accounts/create')
            ->assertSessionHas('warning', '画面を開いてから時間がたっていたので、操作できませんでした。入れた内容は残してあるので、もう一度押してください。')
            ->assertSessionHasInput('name', 'みずほ銀行')
            ->assertSessionHasInput('initial_balance', '350,000')
            ->assertSessionMissing('_old_input.password');

        $this->get('/accounts/create')->assertSee('入れた内容は残してあるので')->assertSee('value="みずほ銀行"', false);
    }

    public function test_ログインも切れているときは_ログインし直すと同じ画面に戻り_入れた内容が欄に戻る(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'password' => 'kakeibo-test-1234']);

        $this->from('/accounts/create')
            ->post('/_expired', ['name' => 'みずほ銀行', 'initial_balance' => '350,000', 'password' => 'secret'])
            ->assertRedirect('/login')
            ->assertSessionHas('warning', 'ログインが切れていたので、操作できませんでした。ログインし直すと、入れた内容を欄に戻します。');
        $this->get('/login')->assertSee('ログインし直すと、入れた内容を欄に戻します');

        // ログインすると、開いていた画面に戻る
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'kakeibo-test-1234'])
            ->assertRedirect('/accounts/create');
        $this->assertAuthenticatedAs($user);

        // 欄に戻り、知らせが出る。パスワードは取っておかない。戻すのは 1 回だけ
        $this->get('/accounts/create')
            ->assertSee('value="みずほ銀行"', false)
            ->assertSee('ログインする前に入れた内容を、欄に戻しました');
        $this->assertNull(session('expired_input'));
        $this->get('/accounts/create')->assertDontSee('value="みずほ銀行"', false);
    }

    public function test_ほかのサイトから来たときは_そのサイトへは戻さない(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('https://example.com/attack')
            ->post('/_expired', ['name' => 'x'])
            ->assertRedirect(route('home'));
    }

    public function test_ログインの画面の合言葉が古くても_ほかのタブでログイン済みならホームで知らせる(): void
    {
        Route::middleware('web')->post('/login', fn () => throw new TokenMismatchException);
        $this->actingAs(User::factory()->create());

        $this->post('/login', ['email' => 'test@example.com'])
            ->assertRedirect(route('home'))
            ->assertSessionHas('warning', 'もうログインしています。');
    }

    public function test_取っておいた入力は_1日たつと戻さない(): void
    {
        $this->actingAs(User::factory()->create());
        session()->put('expired_input', ['url' => url('/accounts/create'), 'input' => ['name' => 'みずほ銀行'], 'at' => now()->subDays(2)->timestamp]);

        $this->get('/accounts/create')->assertDontSee('value="みずほ銀行"', false);
        $this->assertNull(session('expired_input'));
    }
}
