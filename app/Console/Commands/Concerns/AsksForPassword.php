<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * パスワードを、画面に出さずに 2 回聞く（打ちまちがいを防ぐ）。
 * コマンドの引数で渡すと、コマンドの履歴にパスワードが残るので、引数では受けとらない。
 */
trait AsksForPassword
{
    protected function askForPassword(): ?string
    {
        $password = (string) $this->secret('パスワード（12 文字以上。打った字は出ません）');
        $confirmation = (string) $this->secret('もう一度パスワード');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::min(12)]],
            [],
            ['password' => 'パスワード'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return $password;
    }
}
