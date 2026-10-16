<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Support\Budgets;
use App\Support\MonthlyCharts;
use App\Support\MonthlySummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * 月のまとめ（画面設計書 5.5）。収支・支出の分類（円グラフと予算の状況）・収入の分類・相手ごとの支出・
 * 月ごとの収入と支出（棒グラフ）・全体の残高の移り変わり（折れ線グラフ）を、1 か月ずつ出す
 */
class SummaryController extends Controller
{
    public function index(Request $request): Response
    {
        // 今日は 1 回だけ決め（見る月を決めるのにも使う）、1 つのトランザクションの中で読む（ホームと同じ）。0 時をまたいだり、
        // 読んでいるあいだに記録が足されたりしても、表・グラフ・予算の状況が、同じ時点のデータで計算されるように
        $today = CarbonImmutable::today();
        $month = MonthlySummary::month($request->query('month'), $today);

        return response(DB::transaction(function () use ($month, $today) {
            $expenseCategories = MonthlySummary::categoryTotals($month, RecordType::Expense);
            $months = MonthlyCharts::months($month, MonthlyCharts::firstMonth());

            return view('summary.index', [
                'month' => $month,
                ...MonthlySummary::incomeExpense($month),
                // その月に記録が 1 件もないとき（移動・調整もない）は、「この月の記録はまだありません」を出す
                'hasRecords' => MonthlySummary::records($month)->exists(),
                'expenseCategories' => $expenseCategories,
                'incomeCategories' => MonthlySummary::categoryTotals($month, RecordType::Income),
                'partners' => MonthlySummary::partnerExpenses($month),
                // 予算の状況（第 3 段階）：分類の id → その月の予算。今月から先の月は、「使わない」分類の予算を使わない
                'budgets' => Budgets::amountsFor($month, $today),
                'pie' => MonthlyCharts::pie($expenseCategories),
                'chartMonths' => $months,
                'monthlyIncomeExpense' => MonthlyCharts::incomeExpense($months),
                'monthEndBalances' => MonthlyCharts::balances($months, $today),
            ])->render();
        }));
    }
}
