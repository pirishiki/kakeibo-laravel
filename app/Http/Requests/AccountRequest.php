<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\ReserveType;
use App\Models\Account;
use App\Support\FormInput;
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
    public const MAX_AMOUNT = FormInput::MAX_AMOUNT;

    /**
     * 最初の残高は、記録の金額の欄と同じ形（文字の欄、欄を離れたらカンマ）なので、
     * 「1,000」や全角の数字・マイナスを、チェックの前に数だけの形にそろえる
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('initial_balance'))) {
            $this->merge(['initial_balance' => FormInput::normalizeAmount($this->input('initial_balance'))]);
        }
        if (is_string($this->input('reserve_amount'))) {
            $this->merge(['reserve_amount' => FormInput::normalizeAmount($this->input('reserve_amount'))]);
        }
    }

    /**
     * 保存する中身。取っておく額は、クレジットカードと「金額」以外では金額を持たない（画面設計書 5.6）。
     * クレジットカードは取っておく額を持たないので、種類をカードに変えたら「なし」に戻す
     *
     * @return array<string, mixed>
     */
    public function accountData(): array
    {
        $data = $this->validated();
        $isCard = $data['type'] === AccountType::CreditCard->value;
        if (! $isCard && ! array_key_exists('reserve_type', $data)) {
            unset($data['reserve_amount']);

            return $data;
        }
        $type = $isCard ? ReserveType::None->value : $data['reserve_type'];
        $data['reserve_type'] = $type;
        $data['reserve_amount'] = $type === ReserveType::Amount->value ? (int) $data['reserve_amount'] : null;

        return $data;
    }

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
            // 欄がない画面（この欄を足す前に開いたままの画面など）から送られたときは、今の値のまま変えない（accountData）
            'reserve_type' => ['sometimes', 'required', Rule::enum(ReserveType::class)],
            // 「金額」を選んだときだけ、1 円以上の整数がいる（クレジットカードのときは、欄が隠れていて使わないので見ない）
            'reserve_amount' => $this->input('type') === AccountType::CreditCard->value
                ? ['nullable']
                : ['exclude_unless:reserve_type,'.ReserveType::Amount->value, 'nullable', 'required_if:reserve_type,'.ReserveType::Amount->value, 'integer', 'between:1,'.self::MAX_AMOUNT],
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

                // 画面を開いたあとに記録ができた口座で、最初の残高を変えて送ってきたとき。黙って捨てずに知らせる（画面設計書 5.6）
                $sent = $this->input('initial_balance');
                if ($account !== null && $this->has('initial_balance') && $account->hasRecords()
                    && (! is_string($sent) || FormInput::normalizeAmount($sent) !== (string) $account->initial_balance)) {
                    $validator->errors()->add('initial_balance', '開いたあとにこの口座の記録ができたので、最初の残高は変えられません。今の残高を直すときは「残高を合わせる」を使ってください。');
                }
            },
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
