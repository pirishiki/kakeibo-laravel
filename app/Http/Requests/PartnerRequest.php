<?php

namespace App\Http\Requests;

use App\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

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
}
