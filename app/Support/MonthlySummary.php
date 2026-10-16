<?php

namespace App\Support;

use App\Enums\RecordType;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 1 か月の合計の計算（記録の一覧・月のまとめ・ホームで共通に使う。画面設計書 5.3・5.5）。
 * どの画面でも同じ数え方になるように、計算はここだけに書く。移動と調整は、収入にも支出にも入れない
 */
class MonthlySummary
{
    /**
     * ?month=2026-09 から、見る月の 1 日を出す。書いていないとき・形がちがうときは今月
     */
    public static function month(mixed $value, ?CarbonImmutable $today = null): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m', $value);
        }

        // 今日を先に決めてある画面（月のまとめ）は、その今日の月にする（0 時をまたいでも、見る月と今日がずれないように）
        return ($today ?? CarbonImmutable::today())->startOfMonth();
    }

    /**
     * その月の 1 日から末日までの記録
     *
     * @return Builder<Record>
     */
    public static function records(CarbonImmutable $month): Builder
    {
        return Record::query()->whereBetween('date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()]);
    }

    /**
     * その月の収入と支出の合計。データベースで種類ごとに足して、合計の 2 行だけを受けとる
     *
     * @return array{income: int, expense: int}
     */
    public static function incomeExpense(CarbonImmutable $month): array
    {
        return self::incomeExpenseOf(self::records($month));
    }

    /**
     * 記録の集まりの収入と支出の合計（記録の一覧で探したときも、この数え方。画面設計書 5.3）
     *
     * @param  Builder<Record>  $records
     * @return array{income: int, expense: int}
     */
    public static function incomeExpenseOf(Builder $records): array
    {
        $totals = (clone $records)->toBase()
            ->whereIn('type', [RecordType::Income->value, RecordType::Expense->value])
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) AS total')
            ->pluck('total', 'type');

        return [
            'income' => (int) ($totals[RecordType::Income->value] ?? 0),
            'expense' => (int) ($totals[RecordType::Expense->value] ?? 0),
        ];
    }

    /**
     * その月の、日ごとの収入と支出の合計（ホームのカレンダー。画面設計書 5.2）。収入も支出もない日は入れない
     * 日付と種類ごとに、データベースで足して受けとる（記録の件数ではなく、日数 × 2 行まで）
     *
     * @return array<string, array{income: int, expense: int}> 日付（2026-10-09）→ 収入と支出
     */
    public static function dailyIncomeExpense(CarbonImmutable $month): array
    {
        $days = [];

        $rows = self::records($month)->toBase()
            ->whereIn('type', [RecordType::Income->value, RecordType::Expense->value])
            ->groupBy('date', 'type')
            ->selectRaw('date, type, SUM(amount) AS total')
            ->get();

        foreach ($rows as $row) {
            $date = substr((string) $row->date, 0, 10);
            $days[$date] ??= ['income' => 0, 'expense' => 0];
            $days[$date][$row->type === RecordType::Income->value ? 'income' : 'expense'] = (int) $row->total;
        }

        return $days;
    }

    /**
     * その月の、大分類ごとの合計（収入か支出のどちらか）。金額の多い順で、同じ金額なら分類の並び順。0 円の分類は入れない
     * 大分類の金額 ＝ その小分類の合計 ＋ 小分類を選ばなかった記録の合計。children の category が null の行は「小分類なし」
     * 「使わない」分類も、その月に記録があれば入れる
     *
     * @return Collection<int, array{category: Category, amount: int, children: Collection<int, array{category: Category|null, amount: non-negative-int}>}>
     */
    public static function categoryTotals(CarbonImmutable $month, RecordType $type): Collection
    {
        // 分類ごとの合計を、データベースで足して受けとる（記録の件数ではなく、使った分類の数の行だけ）
        $totals = self::records($month)->toBase()
            ->where('type', $type->value)
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount) AS total')
            ->pluck('total', 'category_id')
            ->map(fn ($total) => (int) $total)
            ->filter(fn (int $total) => $total > 0);

        $categories = Category::query()->with('parent')->findMany($totals->keys());
        // 記録に入っている分類の大分類（小分類ならその親、大分類ならそのもの）。同じ大分類は 1 つにまとめる
        $parents = $categories->map(fn (Category $category) => $category->parent ?? $category)->keyBy('id');

        return $parents
            ->map(function (Category $parent) use ($categories, $totals) {
                $children = $categories
                    ->filter(fn (Category $category) => ($category->parent_id ?? $category->id) === $parent->id)
                    ->map(fn (Category $category) => [
                        'category' => $category->isParent() ? null : $category,
                        'amount' => $totals->get($category->id, 0),
                    ])
                    ->sort(fn (array $a, array $b) => $b['amount'] <=> $a['amount']
                        ?: self::childOrder($a['category']) <=> self::childOrder($b['category']))
                    ->values();

                return [
                    'category' => $parent,
                    'amount' => (int) $children->sum('amount'),
                    'children' => $children,
                ];
            })
            ->sort(fn (array $a, array $b) => $b['amount'] <=> $a['amount']
                ?: $a['category']->sort_order <=> $b['category']->sort_order)
            ->values();
    }

    /**
     * その月の、相手ごとの支出の合計。金額の多い順で、同じ金額なら追加した順。「使わない」相手も、その月に記録があれば入れる
     *
     * @return Collection<int, array{partner: Partner, amount: non-negative-int}>
     */
    public static function partnerExpenses(CarbonImmutable $month): Collection
    {
        $totals = self::records($month)->toBase()
            ->where('type', RecordType::Expense->value)
            ->whereNotNull('partner_id')
            ->groupBy('partner_id')
            ->selectRaw('partner_id, SUM(amount) AS total')
            ->pluck('total', 'partner_id')
            ->map(fn ($total) => (int) $total)
            ->filter(fn (int $total) => $total > 0);

        return Partner::query()->findMany($totals->keys())
            ->map(fn (Partner $partner) => ['partner' => $partner, 'amount' => $totals->get($partner->id, 0)])
            ->sort(fn (array $a, array $b) => $b['amount'] <=> $a['amount'] ?: $a['partner']->id <=> $b['partner']->id)
            ->values();
    }

    /** 金額が同じ小分類の並び：分類の管理の順。「小分類なし」はいちばん下 */
    private static function childOrder(?Category $category): int
    {
        return $category === null ? PHP_INT_MAX : $category->sort_order;
    }
}
