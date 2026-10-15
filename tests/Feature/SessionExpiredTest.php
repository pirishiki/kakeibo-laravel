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

    public function test_ログインも切れているときは_ログインの画面で知らせる(): void
    {
        $this->from('/accounts/create')
            ->post('/_expired', ['name' => 'みずほ銀行'])
            ->assertRedirect('/login')
            ->assertSessionHas('warning', 'ログインが切れていたので、操作できませんでした。ログインしてから、もう一度入れてください。');

        $this->get('/login')->assertSee('ログインが切れていたので');
    }
}
