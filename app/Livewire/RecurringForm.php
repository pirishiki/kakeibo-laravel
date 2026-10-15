<?php

namespace App\Livewire;

use App\Enums\RecordType;
use App\Enums\RecurringShift;
use App\Livewire\Concerns\ChoosesPartnerCategoryAccount;
use App\Models\Partner;
use App\Models\Recurring;
use App\Models\RecurringSkip;
use App\Support\FormInput;
use App\Support\RecurringDate;
use App\Support\RecurringRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 定期的な記録の登録・変更・削除（画面設計書 5.10「登録してある定期的な記録」）。Livewire の部品
 * 相手・分類・小分類・口座の欄は、記録の登録（RecordForm）と共通（ChoosesPartnerCategoryAccount）
 * 開くときに予定を渡したら変更、渡さなければ登録として動く
 *
 * @property-read Recurring|null $saved
 */
class RecurringForm extends Component
{
    use ChoosesPartnerCategoryAccount;

    /** 変更している予定の id。登録のときは null。画面の側から書きかえられないようにする */
    #[Locked]
    public ?int $recurringId = null;

    /** 変更の画面を開いたときの、予定の最後に変えた日時。保存のときにちがえば、ほかの画面で直されている */
    #[Locked]
    public ?string $openedAt = null;

    /** @var list<string> 確認の小窓で知らせた月（「今回はなし」にする月・日付が過ぎた月）。押したときに変わっていたら、小窓を出し直す */
    #[Locked]
    public array $noticedMonths = [];

    /** 種類：expense（支出）・income（収入）・transfer（移動） */
    public string $type = 'expense';

    public string $name = '';

    /** 金額が決まっているか：'1'＝決まっている、'0'＝毎月変わる */
    public string $isFixed = '1';

    /** 決まった金額。打ったままの文字で持ち、保存するときに数にする */
    public string $amount = '';

    /** 毎月変わるときの概算 */
    public string $estimatedAmount = '';

    /** 毎月の日付：'1'〜'28'、'31'＝月末 */
    public string $day = '27';

    public string $shift = 'next';

    /** どの月：'every'＝毎月、'selected'＝選んだ月だけ */
    public string $monthsMode = 'every';

    /** @var array<mixed> 選んだ月（'1'〜'12'）。画面から届く値なので、形は使うときに確かめる */
    public array $months = [];

    public string $startYear = '';

    public string $startMonth = '';

    /** 最後の月。空なら終わりなし */
    public string $endYear = '';

    public string $endMonth = '';

    public bool $isActive = true;

    /**
     * 開いたときの最初の月・最後の月の年。選ぶ欄の範囲の外でも（ずっと前に始めた予定・何十年先の続きなど）、その年は選べるようにする。
     * 画面の側から書きかえられないようにする（書きかえられると、選べる年の範囲をいくらでも広げられるため）
     */
    #[Locked]
    public ?int $openedStartYear = null;

    #[Locked]
    public ?int $openedEndYear = null;

    /**
     * 新しく登録するとき、最初の月を自分で選んだか。選んでいなければ、日付などを変えるたびに最初の値を出し直す
     */
    public bool $startTouched = false;

    /** 保存の前の確認の小窓に出す文（過ぎた月を「今回はなし」にするとき）。出さないときは空 */
    public string $skipNotice = '';

    /** 保存の前の確認の小窓に出す文（日付の変更で、今月の分の日付がもう過ぎた日になるとき）。出さないときは空 */
    public string $passedNotice = '';

    /** その小窓の「○月○日で記録する」ボタンの字 */
    public string $passedRecordLabel = '';

    /** @var array{accounts: list<int>, categories: list<int>, partner: Partner|null}|null */
    private ?array $originalCache = null;

    public function mount(?Recurring $recurring = null): void
    {
        if ($recurring !== null) {
            $this->fillFrom($recurring);
            $this->recurringId = $recurring->id;
            $this->openedAt = $recurring->updated_at?->toDateTimeString();
            $this->rememberOpenedYears();

            return;
        }

        // 「金額を変えて続きを作る」から開いたとき：もとの予定と同じ内容で、最初の月を最後の月の次の月にする
        $from = request()->query('from');
        $source = is_string($from) && ctype_digit($from) ? Recurring::query()->find((int) $from) : null;
        if ($source !== null && $source->end_month !== null) {
            $this->fillFrom($source);
            $this->isActive = true;
            $this->setStart($source->end_month->addMonth());
            $this->setEnd(null);
            $this->startTouched = true;
            $this->rememberOpenedYears();

            return;
        }

        $this->shift = RecurringShift::defaultFor(RecordType::Expense)->value;
        $this->fillDefaultStart();
        $this->rememberOpenedYears();
    }

    /**
     * 種類を切りかえたとき：分類・小分類と、その種類に合わない欄を空にする。
     * 新しく登録するときは、土日・祝日のずらし方を、その種類の最初の値（収入は前の平日、ほかは次の平日）に戻す
     */
    public function updatedType(): void
    {
        if (! in_array($this->type, ['expense', 'income', 'transfer'], true)) {
            $this->type = 'expense';
        }

        $this->categoryId = null;
        $this->subcategoryId = null;
        $this->resetErrorBag();
        if ($this->recurringId === null) {
            $this->shift = RecurringShift::defaultFor(RecordType::from($this->type))->value;
            $this->fillDefaultStart();
        }
    }

    public function updatedDay(): void
    {
        $this->fillDefaultStart();
    }

    public function updatedShift(): void
    {
        $this->fillDefaultStart();
    }

    public function updatedMonthsMode(): void
    {
        $this->fillDefaultStart();
    }

    public function updatedMonths(): void
    {
        $this->fillDefaultStart();
    }

    public function updatedStartYear(): void
    {
        $this->startTouched = true;
    }

    public function updatedStartMonth(): void
    {
        $this->startTouched = true;
    }

    /** 最後の月の年を「なし」にしたら、月も空にする */
    public function updatedEndYear(): void
    {
        if ($this->endYear === '') {
            $this->endMonth = '';
        }
    }

    /** 候補から相手を選んだ。新しく登録するときは、その相手の前回の分類・小分類・口座を入れる（画面設計書 5.10） */
    public function selectPartner(int $id): void
    {
        $partner = Partner::query()->where('is_active', true)->find($id);
        if ($partner === null) {
            // 候補を出したあとに、ほかの画面で「使わない」にしたか削除した相手。黙って何もしないと、押しても選ばれない理由がわからないので知らせる
            $this->addError('partnerName', Partner::UNAVAILABLE_MESSAGE);

            return;
        }

        $this->partnerId = $partner->id;
        $this->partnerName = $partner->name;

        if ($this->recurringId === null) {
            $this->fillFromLatestRecord($partner);
        }
    }

    /**
     * 保存する。過ぎた月が記録の対象に入るときは、先に確認の小窓を出し、「保存する」を押したら（$confirmed）その月を「今回はなし」にして保存する。
     * 日付の変更で今月の分の日付がもう過ぎた日になるときは、同じ小窓で「今回はなし」（$passed＝'skip'）か「○月○日で記録する」（'record'）かを選んでもらう。
     * 保存したら、日付が今日か過ぎていて金額がある月は、その場で記録する（データベース設計書 3.6）
     */
    public function save(bool $confirmed = false, string $passed = ''): mixed
    {
        $this->amount = FormInput::normalizeAmount($this->amount);
        $this->estimatedAmount = FormInput::normalizeAmount($this->estimatedAmount);
        $this->partnerName = Partner::normalizeName($this->partnerName);
        $data = $this->validate($this->rules(), $this->messages(), $this->fieldNames());

        $before = $this->recurringId === null ? null : $this->findOrNotice('save');
        if ($this->recurringId !== null && $before === null) {
            return null;
        }
        if ($before !== null && $this->openedAt !== null && $before->updated_at?->toDateTimeString() !== $this->openedAt) {
            $this->addError('save', 'この定期的な記録は、開いたあとにほかの画面で直されました。画面を読みこみ直して、いまの内容を見てから直してください');

            return null;
        }
        $after = $before === null ? new Recurring : $before->replicate();
        $after->fill($this->valuesToSave($data));

        $skips = $after->monthsToSkipOnSave($before);
        $passedMonths = $before === null ? [] : $after->monthsPassedOnSave($before);
        // 小窓を出したあとに日付が変わるなどして、知らせた月と、いま計算した月がちがうときは、小窓を出し直す（知らせていない月を黙って今回はなしにしない）
        $months = array_map(fn (CarbonImmutable $m) => $m->toDateString(), [...$skips, ...$passedMonths]);
        if ($confirmed && $months !== $this->noticedMonths) {
            $confirmed = false;
            $passed = '';
        }
        if (($skips !== [] && ! $confirmed) || ($passedMonths !== [] && ! in_array($passed, ['skip', 'record'], true))) {
            $this->noticedMonths = $months;
            $this->skipNotice = $skips === [] ? '' : self::monthsLabel($skips).'の分は記録せず、『今回はなし』にします';
            $this->passedNotice = implode('', array_map(
                fn (CarbonImmutable $month) => $month->month.'月の分（'.self::dateLabel($after->scheduledDate($month)).'）は、日付がもう過ぎています。',
                $passedMonths,
            ));
            $this->passedRecordLabel = count($passedMonths) === 1
                ? self::dateLabel($after->scheduledDate($passedMonths[0])).'で記録する'
                : 'それぞれの日付で記録する';
            $this->dispatch('open-modal', id: 'skip-dialog');

            return null;
        }
        if ($passed === 'skip') {
            $skips = [...$skips, ...$passedMonths];
        }

        $recorded = DB::transaction(function () use ($before, $after, $skips) {
            // 0 時 5 分の自動の記録と同じ順番（予定の行 → 相手・口座）で押さえる（逆の順番だと、押さえあいになることがあるため）
            if ($before !== null) {
                Recurring::query()->whereKey($before->id)->lockForUpdate()->first();
            }
            if ($this->hasCategory()) {
                $after->partner_id = $this->partnerIdToSave($before !== null);
            }

            if ($before === null) {
                $after->save();
                $recurring = $after;
            } else {
                $before->fill($after->only($after->getFillable()))->save();
                $recurring = $before;
                // 「毎月変わる」から「決まっている」に変えたら、まだ記録されていない月に入れておいた金額を消す（「今回はなし」は残す）
                if ($recurring->is_fixed && $recurring->wasChanged('is_fixed')) {
                    $recurring->amounts()
                        ->whereNotIn('month', $recurring->records()->whereNotNull('recurring_month')->select('recurring_month'))
                        ->delete();
                }
            }

            foreach ($skips as $month) {
                RecurringSkip::query()->firstOrCreate(['recurring_id' => $recurring->id, 'month' => $month->toDateString()]);
            }

            // 日付が今日か過ぎていて金額がある月は、次の 0 時 5 分を待たずに記録する（今日を最初の月にした・小窓で「記録する」を選んだ など）
            return RecurringRecorder::recordDue($recurring);
        }, 3);

        session()->flash('status', $recorded === 0 ? '保存しました' : '保存しました。日付が来ている分を記録しました');

        return $this->redirect(route('recurrings.index'));
    }

    /**
     * 「金額を変えて続きを作る」（画面設計書 5.10）。保存された最後の月があれば、最初の月がその次の月の登録の画面を開く
     */
    public function continueWithNewAmount(): mixed
    {
        $recurring = $this->findOrNotice('continue');
        if ($recurring === null) {
            return null;
        }

        if ($recurring->end_month === null) {
            $this->addError('continue', '先に最後の月を入れて保存してください');

            return null;
        }

        return $this->redirect(route('recurrings.create', ['from' => $recurring->id]));
    }

    /** 削除（確認の小窓で「削除する」を押したとき）。記録がある予定は削除できない（4 章の 9） */
    public function delete(): mixed
    {
        $recurring = Recurring::query()->find($this->recurringId);
        if ($recurring === null) {
            session()->flash('status', 'この定期的な記録は、もう削除されていました');

            return $this->redirect(route('recurrings.index'));
        }

        // 小窓を開いたあとに記録ができたときなど。データベースも止めるが（restrictOnDelete）、ここで理由を出す
        if ($recurring->records()->exists()) {
            $this->addError('delete', '記録があるので削除できません');

            return null;
        }

        // 月ごとの金額と今回はなしも、データベースがいっしょに消す（cascadeOnDelete）。
        // 確かめたあと、消すまでのあいだに 0 時 5 分の自動の記録ができると、データベースが止める。エラー画面にせず、理由を出す
        try {
            Recurring::query()->whereKey($recurring->id)->delete();
        } catch (QueryException $e) {
            // 23000 は、データベースの決まり（外部キーなど）で止まったとき。それ以外（つながらないなど）は、そのままエラーとして知らせる
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $this->addError('delete', '記録があるので削除できません');

            return null;
        }
        session()->flash('status', '削除しました');

        return $this->redirect(route('recurrings.index'));
    }

    /** 削除の小窓から「使わない」にする（記録がある予定のとき。4 章の 9） */
    public function deactivate(): mixed
    {
        $recurring = $this->findOrNotice('delete');
        if ($recurring === null) {
            return null;
        }
        $recurring->update(['is_active' => false]);
        session()->flash('status', '「使わない」にしました');

        return $this->redirect(route('recurrings.index'));
    }

    /**
     * 保存してある予定（記録があるかもいっしょに読む）。登録のときは null。
     * 削除の小窓・「金額を変えて続きを作る」は、欄に打ちかけの値ではなく、保存してある内容で決める。
     * 欄を変えるたびに画面を描き直すので、1 回の通信で 1 回だけ読む（#[Computed]）
     */
    #[Computed]
    public function saved(): ?Recurring
    {
        return $this->recurringId === null ? null : Recurring::query()->withExists('records')->find($this->recurringId);
    }

    /**
     * 最初の月の年の選ぶ欄：今年の 3 年前から 2 年先まで（もともと入っている年もふくむ）
     *
     * @return list<int>
     */
    public function startYears(): array
    {
        return $this->yearsBetween(today()->year - 3, today()->year + 2, $this->openedStartYear);
    }

    /**
     * 最後の月の年の選ぶ欄：最初の月の年から、今年の 40 年先まで（ローンのように長いもののため）
     *
     * @return list<int>
     */
    public function endYears(): array
    {
        // 最初の月の年が、選ぶ欄にない値（書きかえられた値）のときは、今年から
        $from = in_array($this->startYear, array_map('strval', $this->startYears()), true) ? (int) $this->startYear : today()->year;

        return $this->yearsBetween($from, today()->year + 40, $this->openedEndYear);
    }

    public function render(): View
    {
        return view('livewire.recurring-form');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $isFixed = $this->isFixed === '1';
        $amountRule = ['required', 'integer', 'min:1', 'max:'.FormInput::MAX_AMOUNT];

        return [...$this->choiceRules(),
            'type' => ['required', Rule::in(['expense', 'income', 'transfer'])],
            'name' => ['required', 'string', 'max:50'],
            'isFixed' => ['required', Rule::in(['1', '0'])],
            'amount' => $isFixed ? $amountRule : ['nullable'],
            'estimatedAmount' => $isFixed ? ['nullable'] : $amountRule,
            // 1〜28 日か月末（31）だけ（要件定義書 No.11・12）
            'day' => ['required', Rule::in([...array_map('strval', range(1, 28)), (string) RecurringDate::END_OF_MONTH])],
            'shift' => ['required', Rule::enum(RecurringShift::class)],
            'monthsMode' => ['required', Rule::in(['every', 'selected'])],
            'months' => $this->monthsMode === 'selected' ? ['required', 'array', 'min:1'] : ['array'],
            'months.*' => [Rule::in(array_map('strval', range(1, 12)))],
            // 年は、選ぶ欄に出している年だけ（送る値を書きかえて、ずっと前の年から始めると、何百か月分の「今回はなし」ができてしまうため）
            'startYear' => ['required', Rule::in(array_map('strval', $this->startYears()))],
            'startMonth' => ['required', 'integer', 'between:1,12'],
            'endYear' => ['nullable', 'required_with:endMonth', Rule::in(array_map('strval', $this->endYears()))],
            'endMonth' => ['nullable', 'required_with:endYear', 'integer', 'between:1,12', function ($attribute, $value, $fail) {
                // 最後の月は、最初の月より前にできない
                if ($this->monthFrom($this->endYear, $this->endMonth)?->lt($this->monthFrom($this->startYear, $this->startMonth)) ?? false) {
                    $fail('最後の月は、最初の月より前にはできません。');
                }
            }],
            'isActive' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [...$this->choiceMessages(),
            'name.required' => '名前を入れてください。',
            'name.max' => '名前は 50 文字以内で入れてください。',
            'amount.required' => '金額を入れてください。',
            'amount.integer' => '金額は、数字だけで入れてください。',
            'amount.min' => '金額は 1 円以上で入れてください。',
            'amount.max' => '金額は 99,999,999 円までで入れてください。',
            'estimatedAmount.required' => '概算を入れてください。',
            'estimatedAmount.integer' => '概算は、数字だけで入れてください。',
            'estimatedAmount.min' => '概算は 1 円以上で入れてください。',
            'estimatedAmount.max' => '概算は 99,999,999 円までで入れてください。',
            'day.required' => '日付を選んでください。',
            'day.in' => '日付は、出ている中から選んでください。',
            'shift.*' => '土日・祝日のずらし方は、出ている中から選んでください。',
            'months.required' => '月を 1 つ以上選んでください。',
            'months.min' => '月を 1 つ以上選んでください。',
            'months.*' => '月は、出ている中から選んでください。',
            'startYear.*' => '最初の月を選んでください。',
            'startMonth.*' => '最初の月を選んでください。',
            'endYear.*' => '最後の月は、年と月の両方を選んでください。',
            'endMonth.required_with' => '最後の月は、年と月の両方を選んでください。',
            'endMonth.integer' => '最後の月を選び直してください。',
            'endMonth.between' => '最後の月を選び直してください。',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldNames(): array
    {
        return ['name' => '名前', 'amount' => '金額', 'estimatedAmount' => '概算'];
    }

    /**
     * 変更している予定に、もともと入っている口座・分類（大分類と小分類）・相手。登録のときは空
     *
     * @return array{accounts: list<int>, categories: list<int>, partner: Partner|null}
     */
    protected function original(): array
    {
        if ($this->originalCache !== null) {
            return $this->originalCache;
        }

        // 保存したあと「使う」で終わっていない予定になるときは、「使わない」の口座・分類・相手を残せない
        // （止めていた予定を「使う」に戻して、「使わない」口座を使う動いている予定ができないように。画面設計書 4 章の 9）
        $end = $this->monthFrom($this->endYear, $this->endMonth);
        $willRun = $this->isActive && ($end === null || $end->gte(today()->startOfMonth()));
        $recurring = $this->recurringId === null || $willRun ? null : Recurring::query()->with(['category', 'partner'])->find($this->recurringId);

        return $this->originalCache = [
            'accounts' => array_values(array_filter([$recurring?->account_id, $recurring?->to_account_id])),
            'categories' => array_values(array_filter([$recurring?->category_id, $recurring?->category?->parent_id])),
            'partner' => $recurring?->partner,
        ];
    }

    /**
     * 入力チェックをとおった値を、表の列の形にする
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function valuesToSave(array $data): array
    {
        $isFixed = $this->isFixed === '1';
        $hasCategory = $this->hasCategory();
        $months = $this->monthsMode === 'selected' ? $this->selectedMonths() : null;

        return [
            'name' => $data['name'],
            'type' => $this->type,
            'day' => (int) $data['day'],
            'shift' => $data['shift'],
            'months' => $months,
            'is_fixed' => $isFixed,
            'amount' => $isFixed ? (int) $data['amount'] : null,
            'estimated_amount' => $isFixed ? null : (int) $data['estimatedAmount'],
            'account_id' => $data['accountId'],
            'to_account_id' => $this->type === 'transfer' ? $data['toAccountId'] : null,
            'category_id' => $hasCategory ? ($data['subcategoryId'] ?? $data['categoryId']) : null,
            'partner_id' => null,
            'start_month' => $this->monthFrom($this->startYear, $this->startMonth),
            'end_month' => $this->monthFrom($this->endYear, $this->endMonth),
            'is_active' => $this->isActive,
        ];
    }

    /** 保存してある予定。ほかの画面で削除されていたら、その欄の下に理由を出して null */
    private function findOrNotice(string $field): ?Recurring
    {
        $recurring = Recurring::query()->find($this->recurringId);
        if ($recurring === null) {
            $this->addError($field, 'この定期的な記録は、ほかの画面で削除されました。一覧から開き直してください');
        }

        return $recurring;
    }

    protected function savedPartnerId(): ?int
    {
        return $this->recurringId === null ? null : Recurring::query()->whereKey($this->recurringId)->value('partner_id');
    }

    /** 予定の値を、欄に入れる */
    private function fillFrom(Recurring $recurring): void
    {
        $this->type = $recurring->type->value;
        $this->name = $recurring->name;
        $this->isFixed = $recurring->is_fixed ? '1' : '0';
        $this->amount = $recurring->amount !== null ? number_format($recurring->amount) : '';
        $this->estimatedAmount = $recurring->estimated_amount !== null ? number_format($recurring->estimated_amount) : '';
        $this->day = (string) $recurring->day;
        $this->shift = $recurring->shift->value;
        $this->monthsMode = $recurring->months === null ? 'every' : 'selected';
        $this->months = array_map('strval', $recurring->months ?? []);
        $this->accountId = $recurring->account_id;
        $this->toAccountId = $recurring->to_account_id;
        $this->setStart($recurring->start_month);
        $this->setEnd($recurring->end_month);
        $this->isActive = $recurring->is_active;

        $category = $recurring->category;
        $this->categoryId = $category === null ? null : ($category->parent_id ?? $category->id);
        $this->subcategoryId = $category?->parent_id === null ? null : $category->id;
        $this->partnerId = $recurring->partner_id;
        $this->partnerName = (string) $recurring->partner?->name;
    }

    /**
     * 新しく登録するときの最初の月：記録する日がまだ来ていない、いちばん早い月（画面設計書 5.10）。
     * 自分で最初の月を選んだあとと、変更の画面では、書きかえない
     */
    private function fillDefaultStart(): void
    {
        $day = (int) $this->day;
        $shift = RecurringShift::tryFrom($this->shift);
        if ($this->recurringId !== null || $this->startTouched || $shift === null
            || ! ($day >= 1 && $day <= 28 || $day === RecurringDate::END_OF_MONTH)) {
            return;
        }

        $months = $this->monthsMode === 'selected' ? $this->selectedMonths() : null;
        if ($months === []) {
            return;
        }

        $this->setStart(Recurring::firstUpcomingMonth($day, $shift, $months));
    }

    /**
     * 選んだ月を、数の並び（小さい順・重なりなし）にする
     *
     * @return list<int>
     */
    private function selectedMonths(): array
    {
        $digits = array_filter($this->months, fn ($m) => (is_string($m) || is_int($m)) && ctype_digit((string) $m));
        $months = array_filter(array_map('intval', $digits), fn (int $m) => $m >= 1 && $m <= 12);
        $months = array_values(array_unique($months));
        sort($months);

        return $months;
    }

    private function rememberOpenedYears(): void
    {
        $this->openedStartYear = ctype_digit($this->startYear) ? (int) $this->startYear : null;
        $this->openedEndYear = ctype_digit($this->endYear) ? (int) $this->endYear : null;
    }

    private function setStart(CarbonImmutable $month): void
    {
        $this->startYear = (string) $month->year;
        $this->startMonth = (string) $month->month;
    }

    private function setEnd(?CarbonImmutable $month): void
    {
        $this->endYear = $month === null ? '' : (string) $month->year;
        $this->endMonth = $month === null ? '' : (string) $month->month;
    }

    /** 年と月の欄から、その月の 1 日を作る。どちらかが空・数でないときは null */
    private function monthFrom(string $year, string $month): ?CarbonImmutable
    {
        if (! ctype_digit($year) || ! ctype_digit($month) || (int) $month < 1 || (int) $month > 12) {
            return null;
        }

        return CarbonImmutable::create((int) $year, (int) $month, 1);
    }

    /**
     * @return list<int>
     */
    private function yearsBetween(int $from, int $to, ?int $opened): array
    {
        $years = range($from, max($from, $to));
        if ($opened !== null && ! in_array($opened, $years, true)) {
            $years[] = $opened;
            sort($years);
        }

        return $years;
    }

    /** 小窓の日付の書き方：「10月5日」 */
    private static function dateLabel(CarbonImmutable $date): string
    {
        return $date->month.'月'.$date->day.'日';
    }

    /**
     * 月の並びを、続いているところはまとめて書く：「2026年4月〜9月」「2026年6月、12月」
     *
     * @param  list<CarbonImmutable>  $months  古い順
     */
    public static function monthsLabel(array $months): string
    {
        $ranges = [];
        foreach ($months as $month) {
            $lastIndex = array_key_last($ranges);
            if ($lastIndex !== null && $ranges[$lastIndex][1]->addMonth()->eq($month)) {
                $ranges[$lastIndex][1] = $month;
            } else {
                $ranges[] = [$month, $month];
            }
        }

        $previousYear = null;
        $parts = [];
        foreach ($ranges as [$from, $to]) {
            $text = ($from->year === $previousYear ? '' : $from->year.'年').$from->month.'月';
            if (! $from->eq($to)) {
                $text .= '〜'.($to->year === $from->year ? '' : $to->year.'年').$to->month.'月';
            }
            $parts[] = $text;
            $previousYear = $to->year;
        }

        return implode('、', $parts);
    }
}
