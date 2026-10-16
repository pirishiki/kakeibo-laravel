<?php

namespace App\Livewire;

use App\Enums\RecordType;
use App\Livewire\Concerns\ChoosesPartnerCategoryAccount;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use App\Models\Recurring;
use App\Models\RecurringAmount;
use App\Models\RecurringSkip;
use App\Support\FormInput;
use App\Support\RecordSearch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * 記録の登録・編集・削除（画面設計書 5.4）。Livewire の部品（コンポーネント）
 *
 * 画面の一部だけを書きかえる動き（種類の切りかえ・相手の候補・相手を選んだら分類と口座を入れる）を、
 * JavaScript を書かずに PHP で書ける。欄の値は、下の public の変数とつながっている（wire:model）
 * 開くときに記録を渡したら編集、渡さなければ登録として動く（欄とチェックの決まりを 1 か所にまとめるため）
 *
 * @property-read string|null $deleteBlocker
 * @property-read string|null $skipOnDeleteNotice
 */
class RecordForm extends Component
{
    // 口座・分類・小分類・相手の欄（定期的な記録の登録と共通）
    use ChoosesPartnerCategoryAccount;

    /** 金額の欄に入れられるいちばん大きい数（要件定義書 10.1） */
    public const MAX_AMOUNT = FormInput::MAX_AMOUNT;

    /** 記録の日付に入れられる、いちばん前の日 */
    public const FIRST_DATE = FormInput::FIRST_DATE;

    /** 編集している記録の id。登録のときは null。画面の側から書きかえられないようにする（ほかの記録を書きかえられないように） */
    #[Locked]
    public ?int $recordId = null;

    /** 編集の画面を開いたときの、記録の最後に変えた日時。保存のときにちがえば、ほかの画面で直されている */
    #[Locked]
    public ?string $openedAt = null;

    /** ほかの画面で消された記録を、保存しようとしたときの文 */
    public const GONE_MESSAGE = 'この記録は、ほかの画面で削除されたので、保存できません。記録の一覧から開き直してください';

    /** 種類：expense（支出）・income（収入）・transfer（移動）。adjustment（調整）は、調整の記録を編集するときだけ */
    public string $type = 'expense';

    /** 金額。打ったままの文字で持ち、保存するときに数にする（「1,000」や全角の数字も受けとれるように） */
    public string $amount = '';

    public string $date = '';

    public string $item = '';

    public string $memo = '';

    /**
     * 保存したあとに戻る画面。画面の側から書きかえられないようにする（#[Locked]）。
     * 書きかえられると、保存したときに、ほかのサイトや危ない URL へ移されてしまうため
     */
    #[Locked]
    public string $backUrl = '';

    /**
     * 口座を変えずに記録するとき（残高を合わせる画面の「＋ つけ忘れを記録する」から開いたとき）。
     * 相手を選んでも、口座はその口座のまま入れかえない（画面設計書 5.4・5.7）
     */
    #[Locked]
    public bool $keepAccount = false;

    /**
     * original() で読んだものを、1 回の通信の中だけとっておく（private なので画面には送らない）
     *
     * @var array{type: RecordType|null, accounts: list<int>, categories: list<int>, partner: Partner|null}|null
     */
    private ?array $originalCache = null;

    /**
     * 画面を開いたときに 1 回だけ動く。「保存して続けて入力」のあとは、種類・日付・口座と戻る先を URL から受けとる
     * URL の値は、だれでも書きかえられるので、形を確かめてから使う
     */
    public function mount(?Record $record = null): void
    {
        // URL の値は、文字か数のときだけ使う（?date[]=x のように並びで届いても、エラーにしないため）
        $query = fn (string $key): string => is_scalar($value = request()->query($key)) ? (string) $value : '';

        if ($record !== null) {
            $this->fillFrom($record);
            // 残高を合わせる画面の最近の記録から開いたときは、URL の back（その画面の戻る先もふくむ）を先に使う
            $this->backUrl = $this->backUrlFrom($query('back') !== '' ? $query('back') : url()->previous());

            return;
        }

        $this->type = in_array($query('type'), ['expense', 'income', 'transfer'], true) ? $query('type') : 'expense';
        $this->date = FormInput::isDate($query('date')) ? $query('date') : today()->toDateString();
        $this->backUrl = $this->backUrlFrom($query('back') !== '' ? $query('back') : url()->previous());

        if (ctype_digit($query('account'))) {
            $this->keepAccount = $query('keep_account') === '1';
            $this->accountId = $this->activeAccountId((int) $query('account'));
            $this->toAccountId = ctype_digit($query('to_account')) ? $this->activeAccountId((int) $query('to_account')) : null;
        } else {
            $this->fillLastAccounts();
        }
    }

    /**
     * 種類を切りかえたとき：金額・日付・メモは残し、分類・小分類と、その種類に合わない欄を空にする。
     * 口座は、登録のときはその種類で前回と同じ口座にする。編集のときは、もとの口座のまま（直したい欄だけを直せるように）
     */
    public function updatedType(): void
    {
        // 調整の記録は種類を変えられない。ほかの記録は、調整には変えられない（画面設計書 5.4）
        if ($this->isAdjustment()) {
            $this->type = 'adjustment';

            return;
        }
        if (! in_array($this->type, ['expense', 'income', 'transfer'], true)) {
            $this->type = 'expense';
        }

        $this->categoryId = null;
        $this->subcategoryId = null;
        $this->resetErrorBag();
        if ($this->recordId === null && ! $this->keepAccount) {
            $this->fillLastAccounts();
        }
    }

    /**
     * 候補から相手を選んだ。その相手・同じ種類のいちばん新しい記録（今日まで）から、分類・小分類・口座を入れる（画面設計書 5.4）
     */
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

        // 編集のときは、相手を選び直しても、ほかの欄は変えない（直したい欄だけを直せるようにするため）
        if ($this->recordId !== null) {
            return;
        }

        $this->fillFromLatestRecord($partner, ! $this->keepAccount);
    }

    public function save(bool $continue = false): mixed
    {
        $this->amount = FormInput::normalizeAmount($this->amount);
        $this->partnerName = Partner::normalizeName($this->partnerName);
        $data = $this->validate($this->rules(), $this->messages(), $this->fieldNames());

        $record = $this->recordId === null ? null : Record::query()->find($this->recordId);
        if ($this->recordId !== null && $record === null) {
            $this->addError('blocker', self::GONE_MESSAGE);

            return null;
        }
        // 開いたあとに、ほかの画面でこの記録が直されていたら、上書きせずに知らせる（あとから保存したほうが黙って勝たないように）
        if ($record !== null && $this->openedAt !== null && $record->updated_at?->toDateTimeString() !== $this->openedAt) {
            $this->addError('blocker', 'この記録は、開いたあとにほかの画面で直されました。画面を読みこみ直して、いまの内容を見てから直してください');

            return null;
        }
        $hasCategory = in_array($this->type, ['expense', 'income'], true);
        $values = [
            'type' => $this->type,
            'amount' => (int) $data['amount'],
            'date' => $data['date'],
            'account_id' => $data['accountId'],
            'to_account_id' => $this->type === 'transfer' ? $data['toAccountId'] : null,
            'category_id' => $hasCategory ? ($data['subcategoryId'] ?? $data['categoryId']) : null,
            'item' => $this->type === 'expense' && $data['item'] !== '' ? $data['item'] : null,
            'memo' => $data['memo'] !== '' ? $data['memo'] : null,
        ];

        if ($record !== null) {
            // 「使わない」口座の残高が 0 円でなくなる直しは止める
            $blocker = $this->inactiveAccountBlocker($record, $values);
            if ($blocker !== null) {
                $this->addError('blocker', $blocker);

                return null;
            }
        }

        DB::transaction(function () use ($record, $values, $hasCategory) {
            // 記録の行 → 口座（前と後の両方）の順に押さえてから、もう一度確かめる。
            // チェックのあと保存するまでに、ほかの画面で直された・「使わない」にされたときに、上書きや「使わない」口座の残高の変化を起こさないため
            $locked = null;
            if ($record !== null) {
                $locked = Record::query()->lockForUpdate()->find($record->id);
                if ($locked === null) {
                    throw ValidationException::withMessages(['blocker' => self::GONE_MESSAGE]);
                }
                if ($this->openedAt !== null && $locked->updated_at?->toDateTimeString() !== $this->openedAt) {
                    throw ValidationException::withMessages(['blocker' => 'この記録は、開いたあとにほかの画面で直されました。画面を読みこみ直して、いまの内容を見てから直してください']);
                }
            }
            $ids = array_values(array_unique(array_filter([$values['account_id'], $values['to_account_id'], $locked?->account_id, $locked?->to_account_id])));
            $accounts = Account::query()->whereIn('id', $ids)->sharedLock()->get();
            // 選んだ口座・分類が、ほかの画面で削除されていたら、エラーの画面にせずに選び直してもらう（押さえる順番は 記録→口座→分類→相手）
            if (! $accounts->contains('id', $values['account_id']) || ($values['to_account_id'] !== null && ! $accounts->contains('id', $values['to_account_id']))) {
                throw ValidationException::withMessages(['blocker' => '選んだ口座は、ほかの画面で削除されたので、選べません。口座を選び直してください']);
            }
            if ($values['category_id'] !== null && ! Category::query()->sharedLock()->whereKey($values['category_id'])->exists()) {
                throw ValidationException::withMessages(['blocker' => '選んだ分類は、ほかの画面で削除されたので、選べません。分類を選び直してください']);
            }
            $inactive = $accounts->first(fn (Account $a) => ! $a->is_active && ! in_array($a->id, $this->original()['accounts'], true)
                && in_array($a->id, [$values['account_id'], $values['to_account_id']], true));
            if ($inactive !== null) {
                throw ValidationException::withMessages(['blocker' => '口座「'.$inactive->name.'」は、ほかの画面で『使わない』にされたので、選べません。口座を選び直してください']);
            }
            $blocker = $locked === null ? null : $this->inactiveAccountBlocker($locked, $values);
            if ($blocker !== null) {
                throw ValidationException::withMessages(['blocker' => $blocker]);
            }
            $values['partner_id'] = $hasCategory ? $this->partnerIdToSave($record !== null) : null;
            $locked === null ? Record::create($values) : $locked->update($values);
        }, 3);

        session()->flash('status', '保存しました');

        if ($record !== null) {
            return $this->redirect($this->backUrlAfter($record->refresh()));
        }

        if ($continue) {
            // 種類・日付・口座は残し、ほかの欄を空にした、同じ画面を開き直す
            return $this->redirect(route('records.create', array_filter([
                'type' => $this->type,
                'date' => $this->date,
                'account' => $this->accountId,
                'to_account' => $this->type === 'transfer' ? $this->toAccountId : null,
                'keep_account' => $this->keepAccount ? 1 : null,
                'back' => $this->backUrl,
            ])));
        }

        return $this->redirect($this->backUrl);
    }

    /**
     * 編集している記録を削除する（確認の小窓で「削除する」を押したとき。画面設計書 5.4）
     */
    public function delete(): mixed
    {
        $record = Record::query()->find($this->recordId);
        if ($record === null) {
            // ほかの画面で、もう削除されていた
            session()->flash('status', 'この記録は、もう削除されていました');

            return $this->redirect($this->backUrl);
        }

        // 「使わない」口座の記録を消すと、その口座の残高が 0 円でなくなるので止める（小窓を開いたあとに「使わない」にされたときも、理由を出す）
        if ($this->deleteBlocker !== null) {
            $this->addError('blocker', $this->deleteBlocker);

            return null;
        }

        $error = DB::transaction(function () use ($record) {
            // 予定の行（自動の記録のとき）→ 記録の行 → 口座 の順に押さえてから、もう一度確かめる
            // （ほかの画面で「使わない」にした・残高を合わせたのと同時に消して、使わない口座の残高が変わったり、合わせた額がずれたりしないように）
            $recurring = $record->recurring_id === null ? null : Recurring::query()->lockForUpdate()->find($record->recurring_id);
            $locked = Record::query()->lockForUpdate()->find($record->id);
            if ($locked === null) {
                return null;
            }
            Account::query()->whereIn('id', array_filter([$locked->account_id, $locked->to_account_id]))->sharedLock()->get();
            $blocker = $this->inactiveAccountBlocker($locked, null);
            if ($blocker !== null) {
                return $blocker;
            }
            $record = $locked;

            // 自動で作られた記録なら、その月を「今回はなし」にする（そのままだと、次の 0 時 5 分にもう一度記録されるため。画面設計書 5.4・5.10）
            if ($record->recurring_id !== null && $record->recurring_month !== null) {
                $month = $record->recurring_month->toDateString();
                // 金額が変わる予定は、記録の今の金額を月ごとの金額に書き写す（「もどす」で、消す前と同じ金額で記録し直せるように。データベース設計書 3.7）
                if ($recurring !== null && ! $recurring->is_fixed) {
                    RecurringAmount::query()->updateOrCreate(['recurring_id' => $recurring->id, 'month' => $month], ['amount' => $record->amount]);
                }
                RecurringSkip::query()->firstOrCreate(['recurring_id' => $record->recurring_id, 'month' => $month]);
            }

            $record->delete();

            return null;
        }, 3);
        if ($error !== null) {
            $this->addError('blocker', $error);

            return null;
        }
        session()->flash('status', '削除しました');

        return $this->redirect($this->backUrlAfter($record));
    }

    /**
     * 削除できないときの理由の文。削除できるときは null。確認の小窓に、ボタンのかわりに出す
     */
    #[Computed]
    public function deleteBlocker(): ?string
    {
        $record = $this->recordId === null ? null : Record::query()->find($this->recordId);

        return $record === null ? null : $this->inactiveAccountBlocker($record, null);
    }

    protected function savedPartnerId(): ?int
    {
        return $this->recordId === null ? null : Record::query()->whereKey($this->recordId)->value('partner_id');
    }

    /**
     * 自動で作られた記録を削除するときに、確認の小窓に出す文（画面設計書 5.4）。手で入れた記録のときは null
     */
    #[Computed]
    public function skipOnDeleteNotice(): ?string
    {
        $record = $this->recordId === null ? null : Record::query()->with('recurring')->find($this->recordId);
        if ($record?->recurring === null || $record->recurring_month === null) {
            return null;
        }

        return $record->recurring_month->month.'月の'.$record->recurring->name.'は『今回はなし』になり、自動では記録されません';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $isAdjustment = $this->isAdjustment();

        // 口座・分類・小分類・相手のチェックは、定期的な記録の登録と共通（「使わない」ものは選べない）
        return [...$this->choiceRules(),
            // 調整の記録は調整のまま。ほかの記録は支出・収入・移動のどれか（種類の欄を書きかえられても、ここで止める）
            'type' => ['required', Rule::in($isAdjustment ? ['adjustment'] : ['expense', 'income', 'transfer'])],
            // 調整は、増やすときプラス・減らすときマイナスの、0 以外の整数（データベース設計書 3.5）
            'amount' => $isAdjustment
                ? ['required', 'integer', 'not_in:0', 'min:-'.self::MAX_AMOUNT, 'max:'.self::MAX_AMOUNT]
                : ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            // 年の打ちまちがい（0026 年・2062 年など）に気づけるように、2000年1月1日から、今日の 1 年先までにする。
            // 上の区切りは今日から数えるので、何年たっても止まらない（決まった年で区切ると、その年をすぎたら保存できなくなるため）
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::FIRST_DATE, 'before_or_equal:'.$this->lastDate()->toDateString()],
            'item' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * まちがいの文（画面設計書 4 章の 2・10）。選ぶ欄は「選んでください」、金額は円をつける
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [...$this->choiceMessages(),
            'amount.required' => '金額を入れてください。',
            'amount.integer' => '金額は、数字だけで入れてください。',
            'amount.min' => $this->isAdjustment() ? '金額は -99,999,999 円から 99,999,999 円までで入れてください。' : '金額は 1 円以上で入れてください。',
            'amount.max' => $this->isAdjustment() ? '金額は -99,999,999 円から 99,999,999 円までで入れてください。' : '金額は 99,999,999 円までで入れてください。',
            'amount.not_in' => '金額は 0 以外で入れてください。',
            'date.required' => '日付を入れてください。',
            'date.date_format' => '日付の形がちがいます。',
            'date.after_or_equal' => $this->dateRangeMessage(),
            'date.before_or_equal' => $this->dateRangeMessage(),
            'item.max' => '品物は 100 文字以内で入れてください。',
            'memo.max' => 'メモは 1,000 文字以内で入れてください。',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldNames(): array
    {
        return ['amount' => '金額', 'date' => '日付', 'item' => '品物', 'memo' => 'メモ'];
    }

    /**
     * 日付の欄の下に出す文：「10月20日（火）・あと 7 日」。曜日と、今日から何日かを出して、1 年以内の打ちまちがい（10月と11月など）にも気づけるようにする
     */
    #[Computed]
    public function dateHint(): string
    {
        if (! FormInput::isDate($this->date)) {
            return '';
        }

        $date = CarbonImmutable::parse($this->date);
        $days = (int) today()->diffInDays($date, false);
        $relative = match (true) {
            $days === 0 => '今日',
            $days === 1 => '明日',
            $days === -1 => '昨日',
            $days > 0 => 'あと '.number_format($days).' 日',
            default => number_format(-$days).' 日前',
        };

        return $date->locale('ja')->isoFormat($date->isCurrentYear() ? 'M月D日（ddd）' : 'YYYY年M月D日（ddd）').'・'.$relative;
    }

    public function render(): View
    {
        return view('livewire.record-form');
    }

    /** 編集する記録の値を、欄に入れる */
    private function fillFrom(Record $record): void
    {
        $this->recordId = $record->id;
        $this->openedAt = $record->updated_at?->toDateTimeString();
        $this->type = $record->type->value;
        $this->amount = number_format($record->amount);
        $this->date = $record->date->toDateString();
        $this->accountId = $record->account_id;
        $this->toAccountId = $record->to_account_id;
        $this->item = (string) $record->item;
        $this->memo = (string) $record->memo;

        // 小分類が入っているときは、分類の欄にその上の大分類、小分類の欄に小分類を入れる
        $category = $record->category;
        $this->categoryId = $category === null ? null : ($category->parent_id ?? $category->id);
        $this->subcategoryId = $category?->parent_id === null ? null : $category->id;

        // 相手は選んである状態にする（候補を出さない）
        $this->partnerId = $record->partner_id;
        $this->partnerName = (string) $record->partner?->name;
    }

    private function isAdjustment(): bool
    {
        return $this->original()['type'] === RecordType::Adjustment;
    }

    /**
     * 編集している記録に、もともと入っている種類・口座・分類（大分類と小分類）・相手。登録のときは空
     * 「使わない」になっていても、これらはそのまま出して保存できるようにするため
     *
     * @return array{type: RecordType|null, accounts: list<int>, categories: list<int>, partner: Partner|null}
     */
    protected function original(): array
    {
        // 1 回の通信の中で何度も使うので、最初に読んだものをとっておく（同じ記録を何度も読みに行かない）
        if ($this->originalCache !== null) {
            return $this->originalCache;
        }

        $record = $this->recordId === null ? null : Record::query()->with(['category', 'partner'])->find($this->recordId);

        return $this->originalCache = [
            'type' => $record?->type,
            'accounts' => array_values(array_filter([$record?->account_id, $record?->to_account_id])),
            'categories' => array_values(array_filter([$record?->category_id, $record?->category?->parent_id])),
            'partner' => $record?->partner,
        ];
    }

    /**
     * 「使わない」口座の記録で、その口座の残高が 0 円でなくなる直し・削除のときは、理由の文を返す（画面設計書 5.4）。
     * 「使わない」口座はいつも 0 円、という前提を守るため。メモ・分類・相手・品物だけの直しは止めない
     *
     * @param  array<string, mixed>|null  $values  直したあとの値。削除のときは null
     */
    private function inactiveAccountBlocker(Record $record, ?array $values): ?string
    {
        $inactive = Account::query()
            ->whereIn('id', array_filter([$record->account_id, $record->to_account_id]))
            ->where('is_active', false)
            ->get();

        foreach ($inactive as $account) {
            // 今は 0 円なので、直したあとの残高は「直したあとの増減 − 今の増減」
            $after = ($values === null ? 0 : $this->effectOn($account->id, $values)) - $this->effectOn($account->id, [
                'type' => $record->type->value, 'amount' => $record->amount,
                'account_id' => $record->account_id, 'to_account_id' => $record->to_account_id,
            ]);
            if ($after !== 0) {
                [$doing, $cannot, $then] = $values === null ? ['削除する', '削除', '削除して'] : ['直す', '保存', '直して'];

                return "この記録を{$doing}と、『使わない』口座の{$account->name}の残高が ".number_format($after)." 円になるので、{$cannot}できません。"
                    ."先に{$account->name}を『使う』に戻してから{$then}ください";
            }
            // 残高は変わらなくても、日付を変えると途中の日の残高が変わるので止める
            if ($values !== null && $values['date'] !== $record->date->toDateString()) {
                return 'この記録は『使わない』口座の'.$account->name.'の記録なので、日付は変えられません。先に'.$account->name.'を『使う』に戻してから直してください';
            }
        }

        return null;
    }

    /**
     * 記録 1 件が、ある口座の残高をいくら増減させるか（データベース設計書 3.5 の残高の計算と同じ）
     *
     * @param  array<string, mixed>  $values
     */
    private function effectOn(int $accountId, array $values): int
    {
        $amount = (int) $values['amount'];
        $effect = 0;
        if ((int) $values['account_id'] === $accountId) {
            $effect += in_array($values['type'], ['expense', 'transfer'], true) ? -$amount : $amount;
        }
        if ($values['type'] === 'transfer' && (int) $values['to_account_id'] === $accountId) {
            $effect += $amount;
        }

        return $effect;
    }

    /**
     * 編集・削除のあとに戻る先。記録の一覧から開いたときは、その記録の月の一覧に戻る（画面設計書 5.4）。
     * 「探す」で探した一覧から開いたときは、同じ条件の一覧に戻る（探し直さなくてよいように）
     */
    private function backUrlAfter(Record $record): string
    {
        parse_str((string) parse_url($this->backUrl, PHP_URL_QUERY), $query);
        $fromSearch = array_intersect(array_keys($query), [...RecordSearch::FIELDS, RecordSearch::MARK]) !== [];

        return parse_url($this->backUrl, PHP_URL_PATH) === '/records' && ! $fromSearch
            ? route('records.index', ['month' => $record->date->format('Y-m')])
            : $this->backUrl;
    }

    /** 口座の最初の値：その種類で、最後に保存した記録と同じ口座（「使わない」口座なら空） */
    private function fillLastAccounts(): void
    {
        $last = Record::query()->where('type', $this->type)->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->accountId = $last !== null ? $this->activeAccountId($last->account_id) : null;
        $this->toAccountId = $last !== null && $this->type === 'transfer' ? $this->activeAccountId($last->to_account_id) : null;
    }

    /** 記録の日付に入れられる、いちばん先の日（今日の 1 年先） */
    private function lastDate(): CarbonImmutable
    {
        return today()->toImmutable()->addYear();
    }

    private function dateRangeMessage(): string
    {
        return '日付は 2000年1月1日 から '.$this->lastDate()->isoFormat('YYYY年M月D日').'（今日の 1 年先）までで入れてください。';
    }

    /** 戻る先：このアプリの中の画面で、記録の登録・編集の画面でないとき（保存したあとに、同じ画面へ戻ってしまわないように） */
    private function backUrlFrom(string $previous): string
    {
        return FormInput::backUrl($previous, ['#^/records/create#', '#^/records/\d+/edit#']);
    }
}
