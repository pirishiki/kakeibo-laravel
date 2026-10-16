<?php

namespace App\Support;

use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 月のまとめのグラフの数字（要件定義書 No.20、画面設計書 5.5）。
 * 数字はここ（サーバー）で計算して画面に渡し、ブラウザのグラフの部品（Chart.js）は絵を描くだけにする。表とグラフの金額がずれないように
 */
class MonthlyCharts
{
    /** 円グラフに分けて出す大分類の数。残りは「ほか」にまとめる（色が多いと見分けにくいため） */
    public const PIE_SLICES = 5;

    /**
     * 円グラフの色：多い順に、基本の青緑の濃い色からうすい色へ。「ほか」は灰色（ひかえめ）。
     * 色の役目を増やさない決まり（画面設計書 6.3）のため、新しい色は足さず、青緑の濃さで分ける
     */
    public const PIE_COLORS = ['#075955', '#0b7a75', '#3d9a95', '#79bdb8', '#b5dcd8'];

    public const PIE_REST_COLOR = '#6b7166';

    /** 棒グラフ・折れ線グラフに出す月の数（選んだ月までの 6 か月） */
    public const MONTHS = 6;

    /**
     * 円グラフ：支出の大分類ごとの割合。多い順に 5 つと、残りをまとめた「ほか」。合計は支出の分類の表の合計と同じ
     *
     * @param  Collection<int, array{category: Category, amount: int, children: Collection<int, array{category: Category|null, amount: non-negative-int}>}>  $expenseRows  MonthlySummary::categoryTotals の支出
     * @return list<array{name: string, amount: int, color: string}>
     */
    public static function pie(Collection $expenseRows): array
    {
        $slices = $expenseRows->take(self::PIE_SLICES)
            ->map(fn (array $row, int $i) => ['name' => $row['category']->name, 'amount' => $row['amount'], 'color' => self::PIE_COLORS[$i]])
            ->values()->all();
        $rest = (int) $expenseRows->slice(self::PIE_SLICES)->sum('amount');
        if ($rest > 0) {
            $slices[] = ['name' => 'ほか', 'amount' => $rest, 'color' => self::PIE_REST_COLOR];
        }

        return $slices;
    }

    /**
     * 使い始めた月：いちばん古い記録の月と、最初に口座を作った月の早いほう。何もないときは null
     */
    public static function firstMonth(): ?CarbonImmutable
    {
        $dates = array_filter([Record::query()->min('date'), Account::query()->min('created_at')]);
        if ($dates === []) {
            return null;
        }

        return CarbonImmutable::parse(min(array_map(fn ($date) => substr((string) $date, 0, 10), $dates)))->startOfMonth();
    }

    /**
     * グラフに出す月：選んだ月までの 6 か月。使い始めた月より前の月は出さない（0 円の月が並んで、残高が急に増えたように見えないため）
     *
     * @return list<CarbonImmutable>
     */
    public static function months(CarbonImmutable $month, ?CarbonImmutable $first): array
    {
        $months = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $m = $month->startOfMonth()->subMonths($i);
            if ($first !== null && $m->greaterThanOrEqualTo($first)) {
                $months[] = $m;
            }
        }

        return $months;
    }

    /**
     * 棒グラフ：月ごとの収入・支出。どの月も、その月の月のまとめの収支と同じ数え方（移動と調整は入れない）
     *
     * @param  list<CarbonImmutable>  $months
     * @return list<array{income: int, expense: int}>
     */
    public static function incomeExpense(array $months): array
    {
        return array_map(fn (CarbonImmutable $month) => MonthlySummary::incomeExpense($month), $months);
    }

    /**
     * 折れ線グラフ：各月末の、全部の口座の合計残高。今月は今日までの残高（ホームの今の残高の合計と同じ）。
     * 最初の残高は、使い始めた月の初めからあったものとして、どの月にも入れる。「使わない」口座も入れる（その月には使っていたため）。
     * 移動は、ある口座から減って別の口座に増えるので、合計は変わらない。来月から先の月は、まだ残高がないので null
     *
     * @param  list<CarbonImmutable>  $months
     * @return list<int|null>
     */
    public static function balances(array $months, CarbonImmutable $today): array
    {
        $initial = (int) Account::query()->sum('initial_balance');

        return array_map(function (CarbonImmutable $month) use ($initial, $today) {
            if ($month->greaterThan($today->startOfMonth())) {
                return null;
            }
            $until = $month->isSameMonth($today) ? $today : $month->endOfMonth();
            // 収入と調整は足し、支出は引く（調整は、へらすときはマイナスの金額で入っている）。移動は合計を変えないので 0
            $change = Record::query()->toBase()
                ->where('date', '<=', $until->toDateString())
                ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN -amount WHEN type IN (?, ?) THEN amount ELSE 0 END), 0) AS total', [
                    RecordType::Expense->value,
                    RecordType::Income->value,
                    RecordType::Adjustment->value,
                ])
                ->value('total');

            return $initial + (int) $change;
        }, $months);
    }
}
