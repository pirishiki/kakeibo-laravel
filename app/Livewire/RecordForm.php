<?php

namespace App\Livewire;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * 記録の登録（画面設計書 5.4）。Livewire の部品（コンポーネント）
 *
 * 画面の一部だけを書きかえる動き（種類の切りかえ・相手の候補・相手を選んだら分類と口座を入れる）を、
 * JavaScript を書かずに PHP で書ける。欄の値は、下の public の変数とつながっている（wire:model）
 */
class RecordForm extends Component
{
    /** 金額の欄に入れられるいちばん大きい数（要件定義書 10.1） */
    public const MAX_AMOUNT = 99_999_999;

    /** 種類：expense（支出）・income（収入）・transfer（移動） */
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

    /** 保存したあとに戻る画面 */
    public string $backUrl = '';

    /**
     * 画面を開いたときに 1 回だけ動く。「保存して続けて入力」のあとは、種類・日付・口座と戻る先を URL から受けとる
     */
    public function mount(): void
    {
        $query = request()->query();
        $this->type = in_array($query['type'] ?? null, ['expense', 'income', 'transfer'], true) ? $query['type'] : 'expense';
        $this->date = isset($query['date']) && strtotime((string) $query['date']) ? (string) $query['date'] : today()->toDateString();
        $this->backUrl = $this->backUrlFrom((string) ($query['back'] ?? url()->previous()));

        if (isset($query['account'])) {
            $this->accountId = $this->activeAccountId((int) $query['account']);
            $this->toAccountId = isset($query['to_account']) ? $this->activeAccountId((int) $query['to_account']) : null;
        } else {
            $this->fillLastAccounts();
        }
    }

    /** 種類を切りかえたとき：金額・日付・メモは残し、分類・小分類と、その種類に合わない欄を空にする。口座は、その種類で前回と同じ口座にする */
    public function updatedType(): void
    {
        if (! in_array($this->type, ['expense', 'income', 'transfer'], true)) {
            $this->type = 'expense';
        }

        $this->categoryId = null;
        $this->subcategoryId = null;
        $this->resetErrorBag();
        $this->fillLastAccounts();
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
        $this->partnerName = trim(mb_convert_kana($this->partnerName, 's'));
        $data = $this->validate($this->rules(), $this->messages(), $this->fieldNames());

        DB::transaction(function () use ($data) {
            Record::create([
                'type' => $this->type,
                'amount' => (int) $data['amount'],
                'date' => $data['date'],
                'account_id' => $data['accountId'],
                'to_account_id' => $this->type === 'transfer' ? $data['toAccountId'] : null,
                'category_id' => $this->type === 'transfer' ? null : ($data['subcategoryId'] ?? $data['categoryId']),
                'partner_id' => $this->type === 'transfer' ? null : $this->resolvePartner()->id,
                'item' => $this->type === 'expense' && $data['item'] !== '' ? $data['item'] : null,
                'memo' => $data['memo'] !== '' ? $data['memo'] : null,
            ]);
        });

        session()->flash('status', '保存しました');

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
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $isTransfer = $this->type === 'transfer';
        $kind = $this->type === 'income' ? CategoryKind::Income : CategoryKind::Expense;

        return [
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'date' => ['required', 'date_format:Y-m-d'],
            // 「使わない」口座は選べない
            'accountId' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
            'toAccountId' => $isTransfer
                ? ['required', 'different:accountId', Rule::exists('accounts', 'id')->where('is_active', true)]
                : ['nullable'],
            // 分類は、その種類の「使う」大分類から。小分類は、選んだ大分類の「使う」小分類から
            'categoryId' => $isTransfer ? ['nullable'] : ['required', Rule::exists('categories', 'id')
                ->whereNull('parent_id')->where('kind', $kind->value)->where('is_active', true)],
            'subcategoryId' => $isTransfer ? ['nullable'] : ['nullable', Rule::exists('categories', 'id')
                ->where('parent_id', $this->categoryId ?? 0)->where('is_active', true)],
            'partnerName' => $isTransfer ? ['nullable'] : ['required', 'string', 'max:50'],
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
            'amount.min' => '金額は 1 円以上で入れてください。',
            'amount.max' => '金額は 99,999,999 円までで入れてください。',
            'date.required' => '日付を入れてください。',
            'date.date_format' => '日付の形がちがいます。',
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
     * 口座の選ぶ欄に出す口座（「使う」口座を、口座の管理と同じ順に）
     *
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->where('is_active', true)->ordered()->get();
    }

    /**
     * 分類の選ぶ欄に出す大分類（その種類の「使う」大分類を、分類の管理と同じ順に）
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('kind', $this->type === 'income' ? CategoryKind::Income : CategoryKind::Expense)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * 小分類の選ぶ欄に出す小分類（選んだ大分類の「使う」小分類）
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function subcategories(): Collection
    {
        return $this->categoryId === null
            ? new Collection
            : Category::query()->where('parent_id', $this->categoryId)->where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * 相手の候補：打った文字をふくむ「使う」相手を、最近使った順に 8 つまで
     *
     * @return \Illuminate\Support\Collection<int, Partner>
     */
    #[Computed]
    public function partnerSuggestions(): \Illuminate\Support\Collection
    {
        $word = trim($this->partnerName);
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
        $name = trim($this->partnerName);

        return $name === '' ? null : Partner::query()->where('name', $name)->first();
    }

    public function render(): View
    {
        return view('livewire.record-form');
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

    /** 金額の文字を、数だけの形にそろえる：全角の数字を半角に、カンマと空白を取る */
    private function normalizeAmount(string $amount): string
    {
        return str_replace([',', '，', ' '], '', mb_convert_kana(trim($amount), 'ns'));
    }

    /** 戻る先：このアプリの中の画面で、記録の登録の画面でないときだけ。それ以外はホーム */
    private function backUrlFrom(string $previous): string
    {
        $path = parse_url($previous, PHP_URL_PATH) ?? '/';
        $sameHost = parse_url($previous, PHP_URL_HOST) === request()->getHost();

        return $sameHost && ! str_starts_with($path, '/records/create') ? $previous : route('home');
    }
}
