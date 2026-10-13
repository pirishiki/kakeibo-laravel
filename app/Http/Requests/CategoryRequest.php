<?php

namespace App\Http\Requests;

use App\Enums\CategoryKind;
use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * 分類の追加・変更の入力チェック（画面設計書 5.8）
 * 大分類か小分類かは作るときに決まり、あとから変えられない。収入用・支出用も追加のときだけ選べる
 */
class CategoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|ValidationRule|Unique|Exists>>
     */
    public function rules(): array
    {
        /** @var Category|null $category 変更のときだけ入る */
        $category = $this->route('category');

        // 小分類か：変更のときはもとから小分類か、追加のときは親が送られてきたか
        $isChild = $category !== null ? ! $category->isParent() : $this->filled('parent_id');
        $kind = $category !== null ? $category->kind->value : $this->kindFromInput($isChild);

        $rules = [
            // 同じ段（同じ用の大分類どうし、同じ大分類の中の小分類どうし）で同じ名前はつけられない
            'name' => ['required', 'string', 'max:50', Rule::unique('categories', 'name')
                ->where('kind', $kind)
                ->where('parent_key', $isChild ? $this->integer('parent_id') : 0)
                ->ignore($category)],
        ];

        if ($isChild) {
            // 親は、同じ用の大分類から選ぶ。小分類の下に小分類は作れない
            $rules['parent_id'] = ['required', 'integer', Rule::exists('categories', 'id')
                ->whereNull('parent_id')
                ->where('kind', $kind)];
        } elseif ($category === null) {
            $rules['kind'] = ['required', Rule::enum(CategoryKind::class)];
        }

        // 「使う」の欄は変更の画面にだけある。追加した分類は、いつも「使う」から始まる
        if ($category !== null) {
            $rules['is_active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /** 追加のとき：小分類は親の用、大分類は選んだ用 */
    private function kindFromInput(bool $isChild): string
    {
        if ($isChild) {
            $parent = Category::query()->find($this->integer('parent_id'));

            return $parent?->kind->value ?? '';
        }

        return $this->string('kind')->toString();
    }
}
