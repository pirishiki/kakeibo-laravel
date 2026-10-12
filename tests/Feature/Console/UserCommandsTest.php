<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ユーザーを作るコマンドと、パスワードを変えるコマンド（N-04、要件定義書 10.1）。
 */
class UserCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_QUESTION = 'パスワード（12 文字以上。打った字は出ません）';

    public function test_ユーザーを作れて作ったユーザーでログインできる(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('名前', '自分')
            ->expectsQuestion('メールアドレス', 'Me@Example.com')
            ->expectsQuestion(self::PASSWORD_QUESTION, 'long-password-1')
            ->expectsQuestion('もう一度パスワード', 'long-password-1')
            ->expectsOutput('ユーザーを作りました：Me@Example.com')
            ->assertSuccessful();

        $user = User::where('email', 'me@example.com')->firstOrFail();
        $this->assertSame('自分', $user->name);
        $this->assertTrue(Hash::check('long-password-1', $user->password));

        $this->post('/login', ['email' => 'me@example.com', 'password' => 'long-password-1'])->assertRedirect('/');
    }

    public function test_同じメールアドレスのユーザーは作れない(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        $this->artisan('user:create')
            ->expectsQuestion('名前', '自分')
            ->expectsQuestion('メールアドレス', 'me@example.com')
            ->expectsOutput('このメールアドレスのユーザーは、もういます。')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_パスワードが短いときと2回目が合わないときは作らない(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('名前', '自分')
            ->expectsQuestion('メールアドレス', 'me@example.com')
            ->expectsQuestion(self::PASSWORD_QUESTION, 'short')
            ->expectsQuestion('もう一度パスワード', 'short')
            ->expectsOutput('パスワードは 12 文字以上で入れてください。')
            ->assertFailed();

        $this->artisan('user:create')
            ->expectsQuestion('名前', '自分')
            ->expectsQuestion('メールアドレス', 'me@example.com')
            ->expectsQuestion(self::PASSWORD_QUESTION, 'long-password-1')
            ->expectsQuestion('もう一度パスワード', 'long-password-2')
            ->expectsOutput('パスワードが 2 回目と合っていません。')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_パスワードを変えると新しいパスワードでログインでき今のログインは終わる(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'password' => 'old-password-1']);
        $oldToken = $user->remember_token;
        DB::table('sessions')->insert([
            'id' => 'other-device', 'user_id' => $user->id, 'ip_address' => null,
            'user_agent' => null, 'payload' => '', 'last_activity' => time(),
        ]);

        $this->artisan('user:password', ['email' => 'me@example.com'])
            ->expectsQuestion(self::PASSWORD_QUESTION, 'new-password-1')
            ->expectsQuestion('もう一度パスワード', 'new-password-1')
            ->assertSuccessful();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-1', $user->password));
        $this->assertNotSame($oldToken, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_いないユーザーのパスワードは変えられない(): void
    {
        $this->artisan('user:password', ['email' => 'nobody@example.com'])
            ->expectsOutput('このメールアドレスのユーザーはいません：nobody@example.com')
            ->assertFailed();
    }
}
