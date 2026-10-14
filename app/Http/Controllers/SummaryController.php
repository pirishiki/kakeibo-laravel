<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Support\MonthlySummary;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 月のまとめ（画面設計書 5.5）。収支・支出の分類・収入の分類・相手ごとの支出を、1 か月ずつ出す
 */
class SummaryController extends Controller
{
    public function index(Request $request): View
    {
        $month = MonthlySummary::month($request->query('month'));

        return view('summary.index', [
            'month' => $month,
            ...MonthlySummary::incomeExpense($month),
            // その月に記録が 1 件もないとき（移動・調整もない）は、「この月の記録はまだありません」を出す
            'hasRecords' => MonthlySummary::records($month)->exists(),
            'expenseCategories' => MonthlySummary::categoryTotals($month, RecordType::Expense),
            'incomeCategories' => MonthlySummary::categoryTotals($month, RecordType::Income),
            'partners' => MonthlySummary::partnerExpenses($month),
        ]);
    }
}
