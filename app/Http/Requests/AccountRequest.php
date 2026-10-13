<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * 口座の追加・変更の入力チェック（画面設計書 5.6）
 */
class AccountRequest extends FormRequest
{
    /** 金額の欄に入れられるいちばん大きい数（要件定義書 10.1）。最初の残高はマイナスも同じ大きさまで */
    public const MAX_AMOUNT = 99_999_999;

    /**
     * @return array<string, array<int, string|ValidationRule|Unique>>
     */
    public function rules(): array
    {
        /** @var Account|null $account 変更のときだけ入る */
        $account = $this->route('account');

        $rules = [
            'name' => ['required', 'string', 'max:50', Rule::unique('accounts', 'name')->ignore($account)],
            'type' => ['required', Rule::enum(AccountType::class)],
        ];

        // 最初の残高は、記録がまだない口座だけ直せる。記録があるときは、送られてきても受けとらない
        if ($account === null || ! $account->hasRecords()) {
            $rules['initial_balance'] = ['required', 'integer', 'between:'.-self::MAX_AMOUNT.','.self::MAX_AMOUNT];
        }

        // 「使う」の欄は変更の画面にだけある。追加した口座は、いつも「使う」から始まる
        if ($account !== null) {
            $rules['is_active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * ほかの欄のチェックが通ったあとに、「使わない」にしてよいかを確かめる
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Account|null $account */
                $account = $this->route('account');

                // 「使わない」のまま最初の残高を直すときも確かめる（「使わない」口座は、いつも 0 円にしておくため）
                if ($account === null || $this->boolean('is_active') || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $initial = $this->has('initial_balance') && ! $account->hasRecords() ? $this->integer('initial_balance') : null;
                $blocker = $account->deactivationBlocker($initial);

                if ($blocker !== null) {
                    $validator->errors()->add('is_active', $blocker);
                }
            },
        ];
    }
}
