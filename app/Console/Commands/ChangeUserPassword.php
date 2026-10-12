<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AsksForPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ユーザーのパスワードを変える。パスワードリセットの画面は作らないため（N-04）。
 * 使い方：php artisan user:password メールアドレス
 */
class ChangeUserPassword extends Command
{
    use AsksForPassword;

    protected $signature = 'user:password {email : パスワードを変えるユーザーのメールアドレス}';

    protected $description = 'ユーザーのパスワードを変える（ほかの機械でのログインも終わらせる）';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("このメールアドレスのユーザーはいません：{$email}");

            return self::FAILURE;
        }

        $password = $this->askForPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        // パスワードを変えたら、「ログインしたまま」の印と、今のログインをすべて終わらせる
        // （パスワードを知られたかもしれないときに変えるので、ほかの機械のログインも残さない）
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->info("パスワードを変えました：{$email}（ほかの機械でのログインも終わりました）");

        return self::SUCCESS;
    }
}
