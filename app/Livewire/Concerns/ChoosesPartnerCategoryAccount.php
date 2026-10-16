<?php

namespace App\Livewire\Concerns;

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * 相手・分類・小分類・口座の欄（記録の登録 5.4 と、定期的な記録の登録 5.10 で同じ決まり）
 * 欄の値・選べるもの・入力チェック・相手を選んだら前回の分類と口座を入れる、を 1 か所にまとめる
 * （2 つの画面で決まりがずれないようにするため。要件定義書 10.2「記録の登録と同じチェック」）
 *
 * 使う側は、種類（$type）と、もともと入っているもの（original()）を持つ
 *
 * @property-read bool $isOriginalPartner
 */
trait ChoosesPartnerCategoryAccount
{
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

    /**
     * 変更しているものに、もともと入っている口座・分類（大分類と小分類）・相手。登録のときは空
     * 「使わない」になっていても、これらはそのまま出して保存できるようにするため
     *
     * @return array{accounts: list<int>, categories: list<int>, partner: Partner|null}
     */
    abstract protected function original(): array;

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
     * 口座の選ぶ欄に出す口座（「使う」口座を、口座の管理と同じ順に）。
     * 変更のときは、もともと入っている「使わない」口座も出す（要件定義書 5.4）
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
     * 変更のときは、もともと入っている「使わない」大分類も出す
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        $ids = $this->original()['categories'];

        return Category::query()
            ->whereNull('parent_id')
            ->where('kind', $this->categoryKind())
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $ids))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * 小分類の選ぶ欄に出す小分類（選んだ大分類の「使う」小分類）。
     * 変更のときは、もともと入っている「使わない」小分類も出す
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
     * 打った名前が、変更しているものにもともと入っている相手の名前か（そのときは「使わない」相手でも「使う」に戻さない）
     */
    #[Computed]
    public function isOriginalPartner(): bool
    {
        $original = $this->original()['partner'];

        return $original !== null && $original->name === Partner::normalizeName($this->partnerName);
    }

    /** 収入なら収入用、ほかは支出用の分類 */
    protected function categoryKind(): CategoryKind
    {
        return $this->type === 'income' ? CategoryKind::Income : CategoryKind::Expense;
    }

    /** 支出・収入のときだけ、相手・分類・小分類の欄がある（移動・調整にはない） */
    protected function hasCategory(): bool
    {
        return in_array($this->type, ['expense', 'income'], true);
    }

    /**
     * 相手・分類・小分類・口座の入力チェック。「使わない」ものは選べない。ただし、もともと入っているものは、そのまま保存できる
     *
     * @return array<string, array<int, mixed>>
     */
    protected function choiceRules(): array
    {
        $isTransfer = $this->type === 'transfer';
        $hasCategory = $this->hasCategory();
        $original = $this->original();
        $usable = fn (array $ids) => fn ($query) => $query->where('is_active', true)->orWhereIn('id', $ids);

        return [
            'accountId' => ['required', Rule::exists('accounts', 'id')->where($usable($original['accounts']))],
            'toAccountId' => $isTransfer
                ? ['required', 'different:accountId', Rule::exists('accounts', 'id')->where($usable($original['accounts']))]
                : ['nullable'],
            // 分類は、その種類の「使う」大分類から。小分類は、選んだ大分類の「使う」小分類から
            'categoryId' => $hasCategory ? ['required', Rule::exists('categories', 'id')
                ->whereNull('parent_id')->where('kind', $this->categoryKind()->value)->where($usable($original['categories']))] : ['nullable'],
            'subcategoryId' => $hasCategory ? ['nullable', Rule::exists('categories', 'id')
                ->where('parent_id', $this->categoryId ?? 0)->where($usable($original['categories']))] : ['nullable'],
            'partnerName' => $hasCategory ? ['required', 'string', 'max:50'] : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function choiceMessages(): array
    {
        return [
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
        ];
    }

    /**
     * 相手を選んだとき、その相手・同じ種類のいちばん新しい記録（今日まで）から、分類・小分類と口座を入れる（画面設計書 5.4・5.10）
     *
     * @return bool 入れたら true。その相手の記録がまだないときは、何も変えずに false
     */
    protected function fillFromLatestRecord(Partner $partner, bool $fillAccount = true): bool
    {
        $latest = Record::query()
            ->where('partner_id', $partner->id)
            ->where('type', $this->type)
            ->where('date', '<=', today()->toDateString())
            ->with('category')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return false;
        }

        // 入れようとした分類・口座が「使わない」になっているときは、その欄は空にする
        // 支出・収入の記録には分類があるが、列は空も入れられるので、空のときも考える
        $category = $latest->category_id !== null ? $latest->category : null;
        $parent = $category === null || $category->isParent() ? $category : $category->parent;
        $parentActive = $parent !== null && $parent->is_active;
        $this->categoryId = $parentActive ? $parent->id : null;
        $this->subcategoryId = $parentActive && $category !== $parent && $category->is_active ? $category->id : null;
        if ($fillAccount) {
            $this->accountId = $this->activeAccountId($latest->account_id);
        }

        return true;
    }

    /**
     * 保存する相手。変更で、もともとの相手と同じ名前のときは、その相手をそのまま使う（「使わない」相手でも「使う」に戻さない）。
     * それ以外は、打った名前の相手（ないときは作る）
     */
    protected function partnerIdToSave(bool $editing): int
    {
        // 候補から選んだ・もともと入っていた相手（名前を打ち直していない）は、名前ではなく id で決める。
        // 開いたあとに、ほかの画面でその相手の名前を変えたり、ほかの相手にまとめたりしても、元の名前の相手を作り直さないため
        if ($this->partnerId !== null) {
            $partner = Partner::query()->find($this->partnerId);
            if ($partner !== null) {
                $original = $this->original()['partner'];
                if (! $partner->is_active && $original?->id !== $partner->id) {
                    $partner->update(['is_active' => true]);
                }

                return $partner->id;
            }
            // ほかの相手にまとめられて消えた：変更なら、いまの記録・予定の相手（まとめ先）のまま
            $saved = $this->savedPartnerId();
            if ($editing && $saved !== null) {
                return $saved;
            }

            throw ValidationException::withMessages(['partnerName' => Partner::UNAVAILABLE_MESSAGE]);
        }

        $original = $this->original()['partner'];
        if ($editing && $original !== null && $original->name === $this->partnerName) {
            return $original->id;
        }

        return $this->resolvePartner()->id;
    }

    /** いま保存してある記録・予定の相手（変更のとき）。登録のときは null */
    abstract protected function savedPartnerId(): ?int;

    /** 打った名前の相手を返す。ないときは新しく作り、「使わない」相手なら「使う」に戻す（画面設計書 5.4 の相手の欄） */
    protected function resolvePartner(): Partner
    {
        // 2 つの画面から同じ新しい名前をほぼ同時に保存しても、エラーにならないようにする
        // （ない名前を押さえて探すと、同時に 2 つ作るときに押さえあいになるので、押さえずに探す）
        $partner = Partner::query()->where('name', $this->partnerName)->first();
        for ($attempt = 1; $partner === null; $attempt++) {
            try {
                $partner = Partner::create(['name' => $this->partnerName]);
            } catch (UniqueConstraintViolationException $e) {
                // ほかの画面が、いま同じ名前で作った。押さえて読むと、いちばん新しい中身（できたばかりの相手）が見える。
                // 読むまでに、その相手がまた削除・まとめられていたら（null）、もう一度作る
                throw_if($attempt >= 3, $e);
                $partner = Partner::query()->where('name', $this->partnerName)->sharedLock()->first();
            }
        }

        if (! $partner->is_active) {
            $partner->update(['is_active' => true]);
        }

        return $partner;
    }

    protected function activeAccountId(?int $id): ?int
    {
        return $id !== null && Account::query()->whereKey($id)->where('is_active', true)->exists() ? $id : null;
    }
}
