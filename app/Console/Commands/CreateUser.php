<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AsksForPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * ログインするユーザーを作る。ユーザー登録の画面は作らないため（N-04）。
 * 使い方：php artisan user:create
 */
class CreateUser extends Command
{
    use AsksForPassword;

    protected $signature = 'user:create';

    protected $description = 'ログインするユーザーを作る（名前・メールアドレス・パスワードを聞く）';

    public function handle(): int
    {
        $name = (string) $this->ask('名前');
        $email = (string) $this->ask('メールアドレス');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ],
            ['email.unique' => 'このメールアドレスのユーザーは、もういます。'],
            ['name' => '名前'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = $this->askForPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        // パスワードは User モデルの設定（casts の hashed）で、ハッシュにしてから保存される
        User::create([
            'name' => $name,
            'email' => strtolower($email),
            'password' => $password,
        ]);

        $this->info("ユーザーを作りました：{$email}");

        return self::SUCCESS;
    }
}
