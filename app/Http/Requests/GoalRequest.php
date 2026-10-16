<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Goal;
use App\Support\FormInput;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 貯金の目標の追加・変更の入力チェック（画面設計書 5.13）
 */
class GoalRequest extends FormRequest
{
    /** 期限の年は、今年から何年先まで選べるか */
    public const YEARS_AHEAD = 40;

    /** 目標額は、記録の金額の欄と同じ形（文字の欄、欄を離れたらカンマ）なので、チェックの前に数だけの形にそろえる */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('target_amount'))) {
            $this->merge(['target_amount' => FormInput::normalizeAmount($this->input('target_amount'))]);
        }
    }

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'target_amount' => ['required', 'integer', 'between:1,'.FormInput::MAX_AMOUNT],
            'account_id' => ['required', 'integer'],
            // 期限は任意。年を選んだら月もいる（どちらも空なら期限なし）
            'deadline_year' => ['nullable', 'integer', 'required_with:deadline_month'],
            'deadline_month' => ['nullable', 'integer', 'between:1,12', 'required_with:deadline_year'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deadline_year.required_with' => '期限の年も選んでください',
            'deadline_month.required_with' => '期限の月も選んでください',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'target_amount' => '目標額',
            'account_id' => '口座',
            'deadline_year' => '期限の年',
            'deadline_month' => '期限の月',
        ];
    }

    /**
     * ほかの欄のチェックが通ったあとに、口座と期限を確かめる。
     * 保存のときにも、口座を押さえてから確かめ直す（GoalController。ここで通ったあと、保存までに口座が変わることがあるため）
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('account_id')) {
                    return;
                }
                $message = self::accountProblem(Account::query()->find($this->integer('account_id')), $this->goal()?->id);
                if ($message !== null) {
                    $validator->errors()->add('account_id', $message);
                }
            },
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['deadline_year', 'deadline_month'])) {
                    return;
                }
                $deadline = $this->deadline();
                // 期限を過ぎた目標を、期限を変えずにほかの欄だけ直すときは、そのまま保存できる（画面設計書 5.13）
                $unchanged = $deadline !== null && $this->goal()?->deadline_month?->equalTo($deadline);
                if ($deadline !== null && ! $unchanged && $deadline->lessThan(CarbonImmutable::today()->startOfMonth())) {
                    $validator->errors()->add('deadline_month', '期限は、今月より前にはできません');
                }
                if ($deadline !== null && ! $unchanged && $deadline->year > CarbonImmutable::today()->year + self::YEARS_AHEAD) {
                    $validator->errors()->add('deadline_year', '期限は、'.self::YEARS_AHEAD.' 年先までにしてください');
                }
            },
        ];
    }

    /**
     * 目標に使えない口座なら、その理由の文。使えるなら null（画面設計書 5.13）
     *
     * @param  int|null  $goalId  変更のときは、その目標の id（自分が使っている口座は選べる）
     */
    public static function accountProblem(?Account $account, ?int $goalId): ?string
    {
        if ($account === null) {
            return 'この口座は、ほかの画面で削除されました。口座を選び直してください';
        }
        if (! $account->is_active) {
            return '「'.$account->name.'」は『使わない』口座なので選べません。口座を選び直してください';
        }
        if ($account->type === AccountType::CreditCard) {
            return 'クレジットカードは、貯金の目標には選べません。口座を選び直してください';
        }
        $other = Goal::query()->where('account_id', $account->id)->when($goalId !== null, fn ($q) => $q->whereKeyNot($goalId))->first();
        if ($other !== null) {
            return '「'.$account->name.'」は、ほかの目標「'.$other->name.'」で使っているので選べません。1 つの口座は 1 つの目標だけに使えます';
        }

        return null;
    }

    /** 選んだ期限の月（その月の 1 日）。期限なしは null */
    public function deadline(): ?CarbonImmutable
    {
        if (! $this->filled('deadline_year') || ! $this->filled('deadline_month')) {
            return null;
        }

        return CarbonImmutable::create($this->integer('deadline_year'), $this->integer('deadline_month'), 1)->startOfDay();
    }

    /**
     * 保存する中身
     *
     * @return array{name: string, target_amount: int, account_id: int, deadline_month: string|null}
     */
    public function goalData(): array
    {
        return [
            'name' => (string) $this->input('name'),
            'target_amount' => $this->integer('target_amount'),
            'account_id' => $this->integer('account_id'),
            'deadline_month' => $this->deadline()?->toDateString(),
        ];
    }

    /** 変更のときは、その目標（ルートの {goal} の番号から探す。もうないときは null） */
    private function goal(): ?Goal
    {
        $id = $this->route('goal');

        return is_numeric($id) ? Goal::query()->find((int) $id) : null;
    }
}
