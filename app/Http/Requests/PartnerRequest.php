<?php

namespace App\Http\Requests;

use App\Models\Partner;
use App\Models\Recurring;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

/**
 * 相手の追加・変更の入力チェック（画面設計書 5.9）
 */
class PartnerRequest extends FormRequest
{
    /** 確かめる前に、名前の形をそろえる（Partner::normalizeName） */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Partner::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, array<int, string|Unique>>
     */
    public function rules(): array
    {
        /** @var Partner|null $partner 変更のときだけ入る */
        $partner = $this->route('partner');

        $rules = [
            'name' => ['required', 'string', 'max:50', Rule::unique('partners', 'name')->ignore($partner)],
        ];

        // 「使う」の欄は変更の画面にだけある。追加した相手は、いつも「使う」から始まる
        if ($partner !== null) {
            $rules['is_active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * ほかの欄のチェックが通ったあとに、「使わない」にしてよいかを確かめる。
     * 「使う」で終わっていない定期的な記録が使っている相手は「使わない」にできない（画面設計書 4 章の 9）
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Partner|null $partner */
                $partner = $this->route('partner');

                // 「その他」の名前を変える（空で送られたときもふくむ）・「使わない」にするときは、ほかのまちがいより先に、守りの文だけを出して終える
                // （画面設計書 5.9。名前の欄は変えられない形なので、ほかの文を出しても直しようがないため）。保存の直前にも、押さえて確かめ直す（PartnerController）
                if ($partner !== null && $partner->isProtected()
                    && ($this->input('name') !== $partner->name || ($partner->is_active && ! $this->boolean('is_active')))) {
                    // 欄ごとのまちがい（名前が空など）は消して、守りの文だけにする
                    foreach (array_keys($validator->errors()->messages()) as $key) {
                        $validator->errors()->forget($key);
                    }
                    $validator->errors()->add('protected', Partner::PROTECTED_MESSAGE);
                }
            },
            function (Validator $validator) {
                /** @var Partner|null $partner */
                $partner = $this->route('partner');

                // もう「使わない」の相手を、そのまま保存するときは止めない
                if ($partner === null || ! $partner->is_active || $this->boolean('is_active') || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (Recurring::usingPartner($partner->id)->running()->exists()) {
                    $validator->errors()->add('is_active', Recurring::IN_USE_MESSAGE);
                }
            },
        ];
    }
}
