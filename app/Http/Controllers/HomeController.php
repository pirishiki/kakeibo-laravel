<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Record;
use App\Support\HomeCalendar;
use App\Support\MonthlySummary;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

/**
 * ホーム（画面設計書 5.2）。第 1 段階の分：今月のカレンダー・今の残高の合計・今月の収支・多く使った分類・最近の記録。
 * お知らせ・使える額（第 2 段階）と貯金の目標（第 3 段階）は、その段階で足す
 */
class HomeController extends Controller
{
    public function index(): View
    {
        $month = CarbonImmutable::today()->startOfMonth();

        // 今の残高：今日までの日付の記録で計算する（先の日付の記録は、その日になったら入る）。「使わない」口座は出さない
        $accounts = Account::query()->where('is_active', true)->ordered()->get();
        $balances = Account::balances(ids: $accounts->modelKeys());

        return view('home', [
            'month' => $month,
            'weeks' => HomeCalendar::weeks($month),
            // 今月の先の日付の記録も、カレンダー・収支・多く使った分類には、その日の分として入る（記録の一覧・月のまとめと同じ数え方）
            'days' => MonthlySummary::dailyIncomeExpense($month),
            // カレンダーの日を押したときに動いていく先を決めるための、記録がある日（移動・調整だけの日もふくむ。記録の一覧に見出しが出るため）
            'recordDates' => MonthlySummary::records($month)->toBase()->distinct()->orderBy('date')->pluck('date')
                ->map(fn ($date) => substr((string) $date, 0, 10))->all(),
            'accounts' => $accounts,
            'balances' => $balances,
            ...MonthlySummary::incomeExpense($month),
            'topCategories' => MonthlySummary::categoryTotals($month, RecordType::Expense)->take(3),
            // 最後に入力した 5 件。記録の日付（date）ではなく、入力した日時（created_at）の新しい順。同じ日時なら、あとで保存したもの（id が大きいもの）が上
            'recentRecords' => Record::query()
                ->with(['account', 'toAccount', 'category.parent', 'partner'])
                ->latest()
                ->orderByDesc('id')
                ->take(5)
                ->get(),
        ]);
    }
}
