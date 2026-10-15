<?php

namespace App\Support;

use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;

/**
 * 記録の一覧の「探す」の条件（画面設計書 5.3・要件定義書 No.18）。
 * 条件は URL のうしろ（?from=2026-10-01&category=3…）に書く。読みこみ直しても「戻る」でも、同じ条件で出すため
 */
class RecordSearch
{
    /** 条件の欄の名前（URL に書く名前） */
    public const FIELDS = ['from', 'to', 'type', 'account', 'category', 'sub', 'partner', 'item'];

    /** 「探す」の欄から送ったしるし。欄を全部空で送ったときも、黙って今月の一覧に戻らず「条件を入れてください」と出すため */
    public const MARK = 'search';

    /** 小分類の欄の「小分類なし」（大分類を選んで、小分類を選ばなかった記録） */
    public const NO_SUB = 'none';

    /** 種類の欄で選べるもの。月のまとめの「相手ごとの支出」から開いたときは「支出」をつけて開く */
    public const TYPES = ['expense' => '支出', 'income' => '収入'];

    private function __construct(
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly ?RecordType $type,
        public readonly ?Account $account,
        public readonly ?Category $category,
        public readonly bool $noSub,
        public readonly ?Category $sub,
        public readonly ?Partner $partner,
        public readonly ?string $item,
    ) {}

    /** 探しているか：URL に条件の欄か、「探す」の欄から送ったしるしがあるとき */
    public static function requested(Request $request): bool
    {
        return collect([...self::FIELDS, self::MARK])->contains(fn (string $key) => $request->query->has($key));
    }

    /**
     * URL の条件を確かめて読む。まちがいがあれば、欄ごとの知らせを返す（まちがった条件を黙って外して探さないように）
     */
    public static function fromRequest(Request $request): self|MessageBag
    {
        // TrimStrings で前後の空白を取り、空の欄は null になっている
        $values = collect(self::FIELDS)->mapWithKeys(fn (string $key) => [$key => $request->query($key)])->all();

        $wrongShape = '条件の形がちがいます。選び直してください。';
        // id は、数字だけの形（「+1」「01」は通さない）。通すと、探すときは 1 として探すのに、「探す」の欄では選べるものと合わず「すべて」になり、
        // 「条件を変える」で送り直したときに、その条件が黙って外れるため
        $id = ['nullable', 'string', 'regex:/^[1-9][0-9]{0,17}$/'];
        $validator = Validator::make($values, [
            'from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'to' => ['nullable', 'string', 'date_format:Y-m-d'],
            'type' => ['nullable', 'string', Rule::in(array_keys(self::TYPES))],
            'account' => $id,
            'category' => $id,
            'sub' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]{0,17})$/'],
            'partner' => $id,
            'item' => ['nullable', 'string', 'max:255'],
        ], [
            '*.regex' => $wrongShape,
            'from.date_format' => '日付は 2026-10-01 の形の、ある日付で入れてください。',
            'to.date_format' => '日付は 2026-10-01 の形の、ある日付で入れてください。',
            'type.in' => '種類は「支出」か「収入」から選んでください。',
            'item.max' => '品物は 255 文字までで入れてください。',
            '*.string' => $wrongShape,
            '*.integer' => $wrongShape,
        ]);

        if ($validator->fails()) {
            return $validator->errors();
        }

        $errors = new MessageBag;
        if (collect($values)->every(fn ($value) => $value === null)) {
            $errors->add(self::MARK, '探す条件を 1 つ以上入れてください。');

            return $errors;
        }

        if ($values['from'] !== null && $values['to'] !== null && $values['to'] < $values['from']) {
            $errors->add('to', '終わりの日は、始めの日と同じ日か、あとの日にしてください。');
        }

        // 口座・分類・相手は、ここで 1 回だけ読み、見つからなければ知らせる
        // （古い URL で、相手をほかの相手にまとめた・分類を消したときなど。確かめたあとに読み直して、なくなっていたらエラーの画面になる、ということがないように）
        $find = fn (string $class, string $key) => $values[$key] === null ? null : $class::query()->find((int) $values[$key]);

        $account = $find(Account::class, 'account');
        if ($values['account'] !== null && $account === null) {
            $errors->add('account', 'その口座は見つかりません。選び直してください。');
        }

        $category = $find(Category::class, 'category');
        if ($values['category'] !== null && ($category === null || ! $category->isParent())) {
            $errors->add('category', 'その大分類は見つかりません（小分類にした・削除したなど）。選び直してください。');
            $category = null;
        }

        $partner = $find(Partner::class, 'partner');
        if ($values['partner'] !== null && $partner === null) {
            $errors->add('partner', 'その相手は見つかりません（ほかの相手にまとめたなど）。選び直してください。');
        }

        $subValue = $values['sub'];
        $sub = null;
        if ($subValue !== null && $values['category'] === null) {
            $errors->add('sub', '小分類で探すときは、大分類も選んでください。');
        } elseif ($subValue !== null && $subValue !== self::NO_SUB && $category !== null) {
            $sub = filter_var($subValue, FILTER_VALIDATE_INT) === false ? null : Category::query()->find((int) $subValue);
            if ($sub === null || $sub->parent_id !== $category->id) {
                // 古い URL で、その小分類をほかの大分類へ移した・削除したときなど
                $errors->add('sub', 'その小分類は、選んだ大分類の中にありません。選び直してください。');
            }
        }

        if ($errors->isNotEmpty()) {
            return $errors;
        }

        $date = fn (string $key) => $values[$key] === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $values[$key]);

        return new self(
            from: $date('from'),
            to: $date('to'),
            type: $values['type'] === null ? null : RecordType::from($values['type']),
            account: $account,
            category: $category,
            noSub: $subValue === self::NO_SUB,
            sub: $sub,
            partner: $partner,
            item: $values['item'],
        );
    }

    /**
     * 条件に合う記録。条件はどれも「かつ」でつなぐ（全部に合うものだけ）
     *
     * @return Builder<Record>
     */
    public function query(): Builder
    {
        return Record::query()
            ->when($this->from, fn (Builder $q, CarbonImmutable $from) => $q->where('date', '>=', $from->toDateString()))
            ->when($this->to, fn (Builder $q, CarbonImmutable $to) => $q->where('date', '<=', $to->toDateString()))
            ->when($this->type, fn (Builder $q, RecordType $type) => $q->where('type', $type))
            // 口座：その口座から出た記録・入った記録（移動の「どこへ」）・調整（残高を合わせるの最近の記録と同じ。画面設計書 5.7）
            ->when($this->account, fn (Builder $q, Account $account) => $q->where(
                fn (Builder $q) => $q->where('account_id', $account->id)->orWhere('to_account_id', $account->id)
            ))
            ->when($this->category, fn (Builder $q, Category $category) => match (true) {
                // 小分類を選ばなかった記録は、大分類がそのまま入っている（データベース設計書 3.5）
                $this->noSub => $q->where('category_id', $category->id),
                $this->sub !== null => $q->where('category_id', $this->sub->id),
                // 大分類だけ：小分類の記録もふくめる（月のまとめの大分類の金額と同じ数え方）
                default => $q->whereIn('category_id', Category::query()->select('id')
                    ->where(fn (Builder $q) => $q->whereKey($category->id)->orWhere('parent_id', $category->id))),
            })
            ->when($this->partner, fn (Builder $q, Partner $partner) => $q->where('partner_id', $partner->id))
            // 品物：打った言葉をふくむもの。メモは探さない（画面設計書 5.3）
            // 保存した品物は半角カナを全角カナにそろえてあるので（Record::normalizeItem）、打った言葉も同じ形にそろえて探す
            // 大文字・小文字を同じにするため、品物も打った言葉も、データベースの同じやり方（LOWER）で小文字にしてくらべる
            // （PHP で小文字にすると、ギリシャ文字の Σ などで MySQL とちがう文字になり、入れたとおりの言葉で探しても見つからないため）
            ->when($this->item, fn (Builder $q, string $item) => $q->whereRaw(self::ITEM_LIKE, [
                self::likePattern((string) Record::normalizeItem($item)),
            ]));
    }

    /**
     * 品物のくらべ方。データベースの名前のくらべ方（utf8mb4_unicode_ci）のままだと「パン」で「ハン」「バン」も、「cafe」で「café」も見つかり、
     * ちがう品物が合計に入ってしまう。utf8mb4_0900_as_ci でも、小さいカナと大きいカナ（「ヨ」で「ショコラ」、「キヤ」で「キャベツ」）が同じになる。
     * そこで、日本語の文字を細かく区別するくらべ方（utf8mb4_ja_0900_as_cs）にして、大文字・小文字だけは LOWER() でそろえる。
     * 同じとして探すもの：大文字・小文字、ひらがな・カタカナ、全角・半角の英数字。ちがうものとして探すもの：濁点・半濁点、小さいカナ、アクセント
     */
    private const ITEM_LIKE = "LOWER(item COLLATE utf8mb4_ja_0900_as_cs) LIKE LOWER(? COLLATE utf8mb4_ja_0900_as_cs) ESCAPE '!'";

    /** 打った言葉をふくむものの形（%言葉%）。% と _ は「何文字でもよい」などの印なので、! をつけてふつうの文字として探す */
    private static function likePattern(string $word): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
    }

    /**
     * 画面に出す条件（「期間 2026年10月1日〜10月31日」など）
     *
     * @return list<array{label: string, value: string}>
     */
    public function conditions(): array
    {
        $date = fn (CarbonImmutable $date) => $date->format('Y年n月j日');
        $period = match (true) {
            $this->from !== null && $this->to !== null => $date($this->from).'〜'.$date($this->to),
            $this->from !== null => $date($this->from).'から',
            $this->to !== null => $date($this->to).'まで',
            default => null,
        };

        $category = match (true) {
            $this->category === null => null,
            $this->noSub => $this->category->name.' > 小分類なし',
            $this->sub !== null => $this->category->name.' > '.$this->sub->name,
            default => $this->category->name.'（小分類もふくむ）',
        };

        return array_values(array_filter([
            ['label' => '期間', 'value' => $period],
            ['label' => '種類', 'value' => $this->type === null ? null : self::TYPES[$this->type->value]],
            ['label' => '口座', 'value' => $this->account?->name],
            ['label' => '分類', 'value' => $category],
            ['label' => '相手', 'value' => $this->partner?->name],
            ['label' => '品物', 'value' => $this->item === null ? null : '「'.$this->item.'」をふくむ'],
        ], fn (array $condition) => $condition['value'] !== null));
    }

    /**
     * 月のまとめの行から開く URL の条件：その月の 1 日〜末日（画面設計書 5.5）
     *
     * @param  array<string, int|string>  $conditions
     * @return array<string, int|string>
     */
    public static function monthParams(CarbonImmutable $month, array $conditions): array
    {
        return ['from' => $month->startOfMonth()->toDateString(), 'to' => $month->endOfMonth()->toDateString(), ...$conditions];
    }
}
