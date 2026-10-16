<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Models\Account;
use App\Models\Record;
use App\Support\AvailableAmount;
use App\Support\Budgets;
use App\Support\HomeCalendar;
use App\Support\MonthlySummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * ホーム（画面設計書 5.2）：お知らせ（予算もふくむ）・使える額・今月のカレンダー（予定もふくむ）・今の残高の合計・今月の収支・多く使った分類・最近の記録。
 * 貯金の目標（第 3 段階）は、その段階で足す
 */
class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        // 今日は 1 回だけ決めて、全部の計算に使う（0 時をまたいで読んでも、カード・カレンダー・お知らせの日がずれないように）。
        // 読むのは 1 つのトランザクションの中でする。MySQL は、その中で最初に読んだ時点のデータを見せつづけるので、
        // 読んでいるあいだに 0 時 5 分の自動の記録や別のタブの保存があっても、残高と予定の両方に入る・どちらにも入らない、が起きない
        $today = CarbonImmutable::today();
        $draft = $request->query('draft');

        return response(DB::transaction(fn () => $this->render($today, is_string($draft) ? $draft : null)));
    }

    /** ホームの中身を読んで、画面（お知らせの Livewire の部品もふくむ）を作る */
    private function render(CarbonImmutable $today, ?string $draft): string
    {
        $month = $today->startOfMonth();

        // 今の残高：今日までの日付の記録で計算する（先の日付の記録は、その日になったら入る）。「使わない」口座は出さない
        $accounts = Account::query()->where('is_active', true)->ordered()->get();
        $balances = Account::balances($today, $accounts->modelKeys());
        $available = AvailableAmount::calculate($today);
        $expenseCategories = MonthlySummary::categoryTotals($month, RecordType::Expense);

        return view('home', [
            'today' => $today,
            // 「今回はなし」のあとに出し直したときの、打ちかけの金額の札（PendingAmounts::reloadHome）
            'draft' => $draft,
            'month' => $month,
            'weeks' => HomeCalendar::weeks($month),
            // 使える額と内訳（第 2 段階）。口座ごとの取っておく額もここから出す
            'available' => $available,
            // カレンダーの灰色の予定：まだ記録されていない定期的な記録（お金が動く日のマス）
            'plans' => AvailableAmount::calendarPlans($month),
            // 取っておく額を下回っている口座（お知らせ）：id → 名前
            'shortages' => $accounts->filter(fn (Account $a) => $available['reserves'][$a->id]['short'] ?? false)
                ->mapWithKeys(fn (Account $a) => [$a->id => $a->name])->all(),
            // 今月、予算の 80% 以上になった分類（お知らせ。第 3 段階）
            'budgetAlerts' => Budgets::alerts($today, $expenseCategories),
            // 今月の先の日付の記録も、カレンダー・収支・多く使った分類には、その日の分として入る（記録の一覧・月のまとめと同じ数え方）
            'days' => MonthlySummary::dailyIncomeExpense($month),
            // カレンダーの日を押したときに動いていく先を決めるための、記録がある日（移動・調整だけの日もふくむ。記録の一覧に見出しが出るため）
            'recordDates' => MonthlySummary::records($month)->toBase()->distinct()->orderBy('date')->pluck('date')
                ->map(fn ($date) => substr((string) $date, 0, 10))->all(),
            'accounts' => $accounts,
            'balances' => $balances,
            ...MonthlySummary::incomeExpense($month),
            'topCategories' => $expenseCategories->take(3),
            // 最後に入力した 5 件。記録の日付（date）ではなく、入力した日時（created_at）の新しい順。同じ日時なら、あとで保存したもの（id が大きいもの）が上
            'recentRecords' => Record::query()
                ->with(['account', 'toAccount', 'category.parent', 'partner'])
                ->latest()
                ->orderByDesc('id')
                ->take(5)
                ->get(),
        ])->render();
    }
}
