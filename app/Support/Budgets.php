<?php

namespace App\Support;

use App\Enums\CategoryKind;
use App\Models\Budget;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 予算の計算と保存（要件定義書 No.19、画面設計書 5.11・5.5・5.2、データベース設計書 3.9）。
 * ある月の予算は「その月の特別な月の予算があればそれ、なければその月に使われるふだんの予算」。
 * 予算を決める画面・月のまとめ・ホームのお知らせで同じ数え方になるように、計算はここだけに書く
 */
class Budgets
{
    /** この割合（%）以上で、月のまとめの棒を赤にし、ホームのお知らせに出す（80% ちょうどもふくむ） */
    public const WARN_PERCENT = 80;

    /** 特別な月を選べるのは、今月から何か月先までか */
    public const SPECIAL_MONTHS_AHEAD = 12;

    /**
     * その月の、分類ごとの予算（分類の id → 金額）。
     * 今月から先の月は、「使わない」分類（大分類が「使わない」の小分類もふくむ）の予算を使わない。
     * 過ぎた月は、そのときの予算の状況をそのまま出す（過去の数字を変えない）
     *
     * @return array<int, int>
     */
    public static function amountsFor(CarbonImmutable $month, CarbonImmutable $today): array
    {
        $month = $month->startOfMonth();
        $amounts = Budget::query()->usualFor($month)->pluck('amount', 'category_id')->all();
        // 特別な月の予算があれば、ふだんの予算のかわりに使う（キーは分類の id のまま上書きする）
        foreach (Budget::query()->specialFor($month)->pluck('amount', 'category_id') as $id => $amount) {
            $amounts[$id] = $amount;
        }

        if ($month->greaterThanOrEqualTo($today->startOfMonth())) {
            $amounts = array_intersect_key($amounts, array_flip(self::usableIds(array_keys($amounts))));
        }

        // 予算は 1 円以上（データベースの CHECK でも止めている）。万一 0 以下があっても、% の計算で 0 で割らないように使わない
        return array_filter(array_map(fn ($amount) => (int) $amount, $amounts), fn (int $amount) => $amount > 0);
    }

    /**
     * 予算を使える分類（支出の分類で、「使う」、小分類なら大分類も「使う」）の id
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function usableIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Category::query()->whereKey($ids)
            ->where('kind', CategoryKind::Expense)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent', fn ($parent) => $parent->where('is_active', true)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * 使った額と予算から、予算の状況を出す。
     * % は 1 % 未満を切り捨てる。80% 以上かどうかは、切り捨てる前の値で決める（7,995 ÷ 10,000 は 79.95% なので当てはまらない）。
     * 小数を使わずに整数でくらべる（使った額 × 100 ≧ 予算 × 80）ので、計算のずれも起きない
     *
     * @return array{budget: int, spent: int, percent: int, warn: bool, over: bool, rest: int}
     */
    public static function status(int $spent, int $budget): array
    {
        return [
            'budget' => $budget,
            'spent' => $spent,
            'percent' => intdiv($spent * 100, $budget),
            'warn' => $spent * 100 >= $budget * self::WARN_PERCENT,
            // 「こえた」は、予算より多く使ったときだけ（ちょうど同じなら「100%（あと 0 円）」）
            'over' => $spent > $budget,
            'rest' => $budget - $spent,
        ];
    }

    /**
     * 月のまとめの支出の分類（MonthlySummary::categoryTotals）から、分類ごとの使った額を出す。
     * 大分類の使った額には、その小分類で使った額もふくまれる（月のまとめと同じ数え方）
     *
     * @param  Collection<int, array{category: Category, amount: int, children: Collection<int, array{category: Category|null, amount: non-negative-int}>}>  $rows
     * @return array<int, int> 分類の id → 使った額
     */
    public static function spentByCategory(Collection $rows): array
    {
        $spent = [];
        foreach ($rows as $row) {
            $spent[$row['category']->id] = $row['amount'];
            foreach ($row['children'] as $child) {
                if ($child['category'] !== null) {
                    $spent[$child['category']->id] = $child['amount'];
                }
            }
        }

        return $spent;
    }

    /**
     * ホームのお知らせ（画面設計書 5.2）：今月、予算の 80% 以上（80% ちょうどもふくむ）になった分類。
     * 大分類と小分類の両方が当てはまるときは両方出す。並びは分類の管理の順（大分類のすぐ下にその小分類）
     *
     * @param  Collection<int, array{category: Category, amount: int, children: Collection<int, array{category: Category|null, amount: non-negative-int}>}>  $expenseRows  今月の支出の分類
     * @return list<array{name: string, status: array{budget: int, spent: int, percent: int, warn: bool, over: bool, rest: int}}>
     */
    public static function alerts(CarbonImmutable $today, Collection $expenseRows): array
    {
        $amounts = self::amountsFor($today->startOfMonth(), $today);
        $spent = self::spentByCategory($expenseRows);

        return Category::query()->with('parent')->findMany(array_keys($amounts))
            ->map(fn (Category $category) => [
                'category' => $category,
                'status' => self::status($spent[$category->id] ?? 0, $amounts[$category->id]),
            ])
            ->filter(fn (array $item) => $item['status']['warn'])
            ->sortBy(fn (array $item) => self::orderKey($item['category']))
            ->map(fn (array $item) => ['name' => self::displayName($item['category']), 'status' => $item['status']])
            ->values()
            ->all();
    }

    /**
     * 予算を保存する（予算を決める画面の「まとめて保存」。画面設計書 5.11）。
     * 開いたときから欄を変えた分類だけを保存する。ほかの分類は、ほかのタブで直していても上書きしない。
     * 開いたあとにほかの画面でその分類の予算が変わっていたときは、上書きせずに名前を返す（古い画面からの上書きを止める）
     *
     * @param  CarbonImmutable|null  $special  特別な月（その月の 1 日）。null ならふだんの予算
     * @param  array<int, string>  $amounts  分類の id → 打った金額（数だけの形、空なら「予算なし」「ふだんのまま」）
     * @param  array<int, string>  $originals  分類の id → 開いたときの金額（同じ形）
     * @return array{saved: int, conflicts: array<int, string>, unusable: list<string>, parentUnusable: list<string>, missing: int}
     *                                                                                                                              saved＝保存した分類の数、conflicts＝開いたあとに変わっていた分類（id → 名前）、
     *                                                                                                                              unusable＝開いたあとに「使わない」になった分類、parentUnusable＝開いたあとに大分類が「使わない」になった小分類、
     *                                                                                                                              missing＝開いたあとに削除された分類の数
     */
    public static function save(?CarbonImmutable $special, array $amounts, array $originals, CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();
        $changed = array_filter($amounts, fn (string $value, int $id) => $value !== ($originals[$id] ?? ''), ARRAY_FILTER_USE_BOTH);
        $result = ['saved' => 0, 'conflicts' => [], 'unusable' => [], 'parentUnusable' => [], 'missing' => 0];
        if ($changed === []) {
            return $result;
        }

        return DB::transaction(function () use ($special, $changed, $originals, $month, $result) {
            // 分類の行をロックしてから、今の予算を読む。同じ分類を 2 つのタブで同時に保存しても、ふだんの予算が 2 つ重ならないように
            // （id の順にロックして、2 つの保存がたがいを待ちつづけないようにする）
            $ids = array_keys($changed);
            sort($ids);
            $categories = Category::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->with('parent')->get()->keyBy('id');
            $usable = array_flip(self::usableIds($ids));

            foreach ($changed as $id => $value) {
                $category = $categories->get($id);
                if ($category === null) {
                    $result['missing']++;

                    continue;
                }
                if (! isset($usable[$id])) {
                    // 小分類そのものは「使う」のままで、大分類が「使わない」になったときは、そうわかるように分けて知らせる
                    if ($category->is_active && ! $category->isParent()) {
                        $result['parentUnusable'][] = self::displayName($category);
                    } else {
                        $result['unusable'][] = self::displayName($category);
                    }

                    continue;
                }

                $current = $special !== null
                    ? Budget::query()->specialFor($special)->where('category_id', $id)->first()
                    : Budget::query()->usualFor($month)->where('category_id', $id)->first();
                // もう打った金額になっている（2 回押しや、ほかのタブで同じ金額にした）なら、したかったことはできているので、そのまま
                if ((string) ($current->amount ?? '') === $value) {
                    continue;
                }
                if ((string) ($current->amount ?? '') !== ($originals[$id] ?? '')) {
                    $result['conflicts'][$id] = self::displayName($category);

                    continue;
                }

                if ($special !== null) {
                    self::saveSpecial($id, $special, $current, $value);
                } else {
                    self::saveUsual($id, $month, $current, $value);
                }
                $result['saved']++;
            }

            return $result;
            // 押さえあい（デッドロック）で止められたときは、3 回までやり直す（$result は値で渡しているので、やり直しても数がずれない）
        }, 3);
    }

    /**
     * ふだんの予算を、今月から新しい金額にする（データベース設計書 3.9）。過ぎた月の予算の状況は変えない。
     * 変えたとき：今の行の end_month に先月を入れ、今月からの行を足す（今月から始まった行なら、金額を書きかえるだけ）。
     * やめたとき：今の行の end_month に先月を入れる（今月から始まった行なら、その行を消す）
     */
    private static function saveUsual(int $categoryId, CarbonImmutable $month, ?Budget $current, string $value): void
    {
        $startsThisMonth = $current?->start_month?->equalTo($month) ?? false;

        if ($value === '') {
            if ($current !== null) {
                $startsThisMonth ? $current->delete() : $current->update(['end_month' => $month->subMonth()]);
            }

            return;
        }

        if ($current !== null && $startsThisMonth) {
            $current->update(['amount' => (int) $value]);

            return;
        }
        $current?->update(['end_month' => $month->subMonth()]);
        Budget::create(['category_id' => $categoryId, 'start_month' => $month, 'amount' => (int) $value]);
    }

    /** 特別な月の予算を入れる・やめる（空なら「ふだんのまま」に戻す） */
    private static function saveSpecial(int $categoryId, CarbonImmutable $special, ?Budget $current, string $value): void
    {
        if ($value === '') {
            $current?->delete();

            return;
        }

        $current !== null
            ? $current->update(['amount' => (int) $value])
            : Budget::create(['category_id' => $categoryId, 'month' => $special, 'amount' => (int) $value]);
    }

    /**
     * お知らせ・知らせに出す分類の名前。小分類は「食費 › 外食」のように大分類の名前をつける
     * （小分類の名前は大分類ごとに決めるので、食費の下と日用品の下に同じ「その他」があっても見分けられるように）
     */
    public static function displayName(Category $category): string
    {
        return $category->parent !== null ? $category->parent->name.' › '.$category->name : $category->name;
    }

    /**
     * 分類の管理の順に並べるための値：大分類の並び順 → 大分類 → その小分類の並び順
     *
     * @return list<int>
     */
    public static function orderKey(Category $category): array
    {
        $parent = $category->parent ?? $category;

        return [$parent->sort_order, $parent->id, $category->isParent() ? 0 : 1, $category->sort_order];
    }
}
