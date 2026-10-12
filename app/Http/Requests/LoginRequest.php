<?php

namespace App\Http\Requests;

use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

/**
 * ログインの入力チェック（画面設計書 5.1）。
 * Fortify の最初のチェックは「必須」だけなので、メールアドレスの形のチェックを足す。
 */
class LoginRequest extends FortifyLoginRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
