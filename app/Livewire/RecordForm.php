<?php

namespace App\Livewire;

use App\Enums\CategoryKind;
use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
 * @property-read bool $isOriginalPartner
 */
class RecordForm extends Component
{
    /** 金額の欄に入れられるいちばん大きい数（要件定義書 10.1） */
    public const MAX_AMOUNT = 99_999_999;

    /** 記録の日付に入れられる、いちばん前の日 */
    public const FIRST_DATE = '2000-01-01';

    /** 編集している記録の id。登録のときは null。画面の側から書きかえられないようにする（ほかの記録を書きかえられないように） */
    #[Locked]
    public ?int $recordId = null;

    /** 種類：expense（支出）・income（収入）・transfer（移動）。adjustment（調整）は、調整の記録を編集するときだけ */
    public string $type = 'expense';

    /** 金額。打ったままの文字で持ち、保存するときに数にする（「1,000」や全角の数字も受けとれるように） */
    public string $amount = '';

    public string $date = '';

    /** 口座（移動のときは「どこから」） */
    public ?int $accountId = null;

    /** 移動のときの「どこへ」 */
    public ?int $toAccountId = null;

    /** 分類（大分類） */
    public ?int $categoryId = null;

    public ?int $subcategoryId = null;

    /** 相手の欄に打った名前 */
    public string $partnerName = '';

    /** 候補から選んだ相手。打ち直すと空に戻る */
    public ?int $partnerId = null;

    public string $item = '';

    public string $memo = '';

    /**
     * 保存したあとに戻る画面。画面の側から書きかえられないようにする（#[Locked]）。
     * 書きかえられると、保存したときに、ほかのサイトや危ない URL へ移されてしまうため
     */
    #[Locked]
    public string $backUrl = '';

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
            $this->backUrl = $this->backUrlFrom(url()->previous());

            return;
        }

        $this->type = in_array($query('type'), ['expense', 'income', 'transfer'], true) ? $query('type') : 'expense';
        $this->date = $this->isDate($query('date')) ? $query('date') : today()->toDateString();
        $this->backUrl = $this->backUrlFrom($query('back') !== '' ? $query('back') : url()->previous());

        if (ctype_digit($query('account'))) {
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
        if ($this->recordId === null) {
            $this->fillLastAccounts();
        }
    }

    /** 分類を変えたら、小分類を空に戻す */
    public function updatedCategoryId(): void
    {
        $this->subcategoryId = null;
    }

    /** 相手の欄を打ち直したら、選んでいた相手を外す（候補をまた出す） */
    public function updatedPartnerName(): void
    {
        $this->partnerId = null;
    }

    /**
     * 候補から相手を選んだ。その相手・同じ種類のいちばん新しい記録（今日まで）から、分類・小分類・口座を入れる（画面設計書 5.4）
     */
    public function selectPartner(int $id): void
    {
        $partner = Partner::query()->where('is_active', true)->find($id);
        if ($partner === null) {
            return;
        }

        $this->partnerId = $partner->id;
        $this->partnerName = $partner->name;

        // 編集のときは、相手を選び直しても、ほかの欄は変えない（直したい欄だけを直せるようにするため）
        if ($this->recordId !== null) {
            return;
        }

        $latest = Record::query()
            ->where('partner_id', $partner->id)
            ->where('type', $this->type)
            ->where('date', '<=', today()->toDateString())
            ->with('category')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        // その相手の記録がまだないときは、何も変えない
        if ($latest === null) {
            return;
        }

        // 入れようとした分類・口座が「使わない」になっているときは、その欄は空にする
        // 支出・収入の記録には分類があるが、列は空も入れられるので、空のときも考える
        $category = $latest->category_id !== null ? $latest->category : null;
        $parent = $category === null || $category->isParent() ? $category : $category->parent;
        $parentActive = $parent !== null && $parent->is_active;
        $this->categoryId = $parentActive ? $parent->id : null;
        $this->subcategoryId = $parentActive && $category !== $parent && $category->is_active ? $category->id : null;
        $this->accountId = $this->activeAccountId($latest->account_id);
    }

    public function save(bool $continue = false): mixed
    {
        $this->amount = $this->normalizeAmount($this->amount);
        $this->partnerName = Partner::normalizeName($this->partnerName);
        $data = $this->validate($this->rules(), $this->messages(), $this->fieldNames());

        $record = $this->recordId === null ? null : Record::query()->findOrFail($this->recordId);
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
            $values['partner_id'] = $hasCategory ? $this->partnerIdToSave($record) : null;
            $record === null ? Record::create($values) : $record->update($values);
        });

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
        $record = Record::query()->findOrFail($this->recordId);

        // 「使わない」口座の記録を消すと、その口座の残高が 0 円でなくなるので止める
        if ($this->deleteBlocker !== null) {
            return null;
        }

        $record->delete();
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

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $isTransfer = $this->type === 'transfer';
        $isAdjustment = $this->isAdjustment();
        $hasCategory = ! $isTransfer && ! $isAdjustment;
        $kind = $this->type === 'income' ? CategoryKind::Income : CategoryKind::Expense;
        $original = $this->original();

        // 「使わない」口座・分類は選べない。ただし編集のとき、その記録にもともと入っているものは、そのまま保存できる
        $usable = fn (array $ids) => fn ($query) => $query->where('is_active', true)->orWhereIn('id', $ids);

        return [
            // 調整の記録は調整のまま。ほかの記録は支出・収入・移動のどれか（種類の欄を書きかえられても、ここで止める）
            'type' => ['required', Rule::in($isAdjustment ? ['adjustment'] : ['expense', 'income', 'transfer'])],
            // 調整は、増やすときプラス・減らすときマイナスの、0 以外の整数（データベース設計書 3.5）
            'amount' => $isAdjustment
                ? ['required', 'integer', 'not_in:0', 'min:-'.self::MAX_AMOUNT, 'max:'.self::MAX_AMOUNT]
                : ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            // 年の打ちまちがい（0026 年・2062 年など）に気づけるように、2000年1月1日から、今日の 1 年先までにする。
            // 上の区切りは今日から数えるので、何年たっても止まらない（決まった年で区切ると、その年をすぎたら保存できなくなるため）
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::FIRST_DATE, 'before_or_equal:'.$this->lastDate()->toDateString()],
            'accountId' => ['required', Rule::exists('accounts', 'id')->where($usable($original['accounts']))],
            'toAccountId' => $isTransfer
                ? ['required', 'different:accountId', Rule::exists('accounts', 'id')->where($usable($original['accounts']))]
                : ['nullable'],
            // 分類は、その種類の「使う」大分類から。小分類は、選んだ大分類の「使う」小分類から
            'categoryId' => $hasCategory ? ['required', Rule::exists('categories', 'id')
                ->whereNull('parent_id')->where('kind', $kind->value)->where($usable($original['categories']))] : ['nullable'],
            'subcategoryId' => $hasCategory ? ['nullable', Rule::exists('categories', 'id')
                ->where('parent_id', $this->categoryId ?? 0)->where($usable($original['categories']))] : ['nullable'],
            'partnerName' => $hasCategory ? ['required', 'string', 'max:50'] : ['nullable'],
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
        return [
            'amount.required' => '金額を入れてください。',
            'amount.integer' => '金額は、数字だけで入れてください。',
            'amount.min' => $this->isAdjustment() ? '金額は -99,999,999 円から 99,999,999 円までで入れてください。' : '金額は 1 円以上で入れてください。',
            'amount.max' => $this->isAdjustment() ? '金額は -99,999,999 円から 99,999,999 円までで入れてください。' : '金額は 99,999,999 円までで入れてください。',
            'amount.not_in' => '金額は 0 以外で入れてください。',
            'date.required' => '日付を入れてください。',
            'date.date_format' => '日付の形がちがいます。',
            'date.after_or_equal' => $this->dateRangeMessage(),
            'date.before_or_equal' => $this->dateRangeMessage(),
            'accountId.required' => ($this->type === 'transfer' ? 'どこから' : '口座').'を選んでください。',
            'accountId.exists' => ($this->type === 'transfer' ? 'どこから' : '口座').'は、出ている中から選んでください。',
            'toAccountId.required' => 'どこへを選んでください。',
            'toAccountId.different' => 'どこから と どこへ に同じ口座は選べません',
            'toAccountId.exists' => 'どこへは、出ている中から選んでください。',
            'categoryId.required' => '分類を選んでください。',
            'categoryId.exists' => '分類は、出ている中から選んでください。',
            'subcategoryId.exists' => '小分類は、出ている中から選んでください。',
            'partnerName.required' => '相手を入れてください。',
            'partnerName.max' => '相手は 50 文字以内で入れてください。',
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
     * 口座の選ぶ欄に出す口座（「使う」口座を、口座の管理と同じ順に）。
     * 編集のときは、その記録にもともと入っている「使わない」口座も出す（要件定義書 5.4）
     *
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        $ids = $this->original()['accounts'];

        return Account::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $ids))->ordered()->get();
    }

    /**
     * 分類の選ぶ欄に出す大分類（その種類の「使う」大分類を、分類の管理と同じ順に）。
     * 編集のときは、その記録にもともと入っている「使わない」大分類も出す
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        $ids = $this->original()['categories'];

        return Category::query()
            ->whereNull('parent_id')
            ->where('kind', $this->type === 'income' ? CategoryKind::Income : CategoryKind::Expense)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $ids))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * 小分類の選ぶ欄に出す小分類（選んだ大分類の「使う」小分類）。
     * 編集のときは、その記録にもともと入っている「使わない」小分類も出す
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function subcategories(): Collection
    {
        $ids = $this->original()['categories'];

        return $this->categoryId === null
            ? new Collection
            : Category::query()
                ->where('parent_id', $this->categoryId)
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $ids))
                ->orderBy('sort_order')
                ->get();
    }

    /**
     * 相手の候補：打った文字をふくむ「使う」相手を、最近使った順に 8 つまで
     *
     * @return \Illuminate\Support\Collection<int, Partner>
     */
    #[Computed]
    public function partnerSuggestions(): \Illuminate\Support\Collection
    {
        $word = Partner::normalizeName($this->partnerName);
        if ($word === '' || $this->partnerId !== null) {
            return collect();
        }

        $groups = Partner::listGroups(Partner::query()->where('name', 'like', '%'.addcslashes($word, '%_\\').'%'));

        return $groups['used']->concat($groups['unused'])->take(8);
    }

    /**
     * 打った名前とまったく同じ名前の相手（「使わない」相手もふくむ）。候補にないときの文に使う
     */
    #[Computed]
    public function exactPartner(): ?Partner
    {
        $name = Partner::normalizeName($this->partnerName);

        return $name === '' ? null : Partner::query()->where('name', $name)->first();
    }

    /**
     * 打った名前が、編集している記録にもともと入っている相手の名前か（そのときは「使わない」相手でも「使う」に戻さない）
     */
    #[Computed]
    public function isOriginalPartner(): bool
    {
        $original = $this->original()['partner'];

        return $original !== null && $original->name === Partner::normalizeName($this->partnerName);
    }

    /**
     * 日付の欄の下に出す文：「10月20日（火）・あと 7 日」。曜日と、今日から何日かを出して、1 年以内の打ちまちがい（10月と11月など）にも気づけるようにする
     */
    #[Computed]
    public function dateHint(): string
    {
        if (! $this->isDate($this->date)) {
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
    private function original(): array
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
     * 保存する相手。編集で、もともとの相手と同じ名前のときは、その相手をそのまま使う（「使わない」相手でも「使う」に戻さない）。
     * それ以外は、打った名前の相手（ないときは作る）
     */
    private function partnerIdToSave(?Record $record): int
    {
        $original = $this->original()['partner'];
        if ($record !== null && $original !== null && $original->name === $this->partnerName) {
            return $original->id;
        }

        return $this->resolvePartner()->id;
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

    /** 編集・削除のあとに戻る先。記録の一覧から開いたときは、その記録の月の一覧に戻る（画面設計書 5.4） */
    private function backUrlAfter(Record $record): string
    {
        return parse_url($this->backUrl, PHP_URL_PATH) === '/records'
            ? route('records.index', ['month' => $record->date->format('Y-m')])
            : $this->backUrl;
    }

    /** 打った名前の相手を返す。ないときは新しく作り、「使わない」相手なら「使う」に戻す（画面設計書 5.4 の相手の欄） */
    private function resolvePartner(): Partner
    {
        $partner = Partner::query()->where('name', $this->partnerName)->lockForUpdate()->first()
            ?? Partner::create(['name' => $this->partnerName]);

        if (! $partner->is_active) {
            $partner->update(['is_active' => true]);
        }

        return $partner;
    }

    /** 口座の最初の値：その種類で、最後に保存した記録と同じ口座（「使わない」口座なら空） */
    private function fillLastAccounts(): void
    {
        $last = Record::query()->where('type', $this->type)->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->accountId = $last !== null ? $this->activeAccountId($last->account_id) : null;
        $this->toAccountId = $last !== null && $this->type === 'transfer' ? $this->activeAccountId($last->to_account_id) : null;
    }

    private function activeAccountId(?int $id): ?int
    {
        return $id !== null && Account::query()->whereKey($id)->where('is_active', true)->exists() ? $id : null;
    }

    /** 金額の文字を、数だけの形にそろえる：全角の数字を半角に、カンマと空白を取り、頭の 0 を取る（0100 → 100） */
    private function normalizeAmount(string $amount): string
    {
        $digits = str_replace([',', '，', ' '], '', mb_convert_kana(trim($amount), 'ns'));

        return preg_match('/^\d+$/', $digits) === 1 ? (ltrim($digits, '0') ?: '0') : $digits;
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

    /** 「2026-10-13」の形の、ほんとうにある日付か */
    private function isDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * 戻る先：このアプリの中の画面で、記録の登録の画面でないときだけ。それ以外はホーム。
     * URL の形（http・https）と場所（このアプリ）を確かめ、場所の部分だけを取り出して、このアプリの URL として組み立て直す
     * （「javascript:」で始まる URL などで、保存したときに危ない動きをさせないため）
     */
    private function backUrlFrom(string $previous): string
    {
        $parts = parse_url($previous);
        $path = $parts['path'] ?? '/';
        $isApp = $parts !== false
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ($parts['host'] ?? '') === request()->getHost()
            && str_starts_with($path, '/')
            // 「//よそのサイト」は、ブラウザがよそのサイトへの URL として読むので通さない
            && ! str_starts_with($path, '//')
            && ! str_starts_with($path, '/records/create')
            && preg_match('#^/records/\d+/edit#', $path) !== 1;

        return $isApp ? url($path).(isset($parts['query']) ? '?'.$parts['query'] : '') : route('home');
    }
}
