<?php

namespace App\Http\Controllers;

use App\Models\Recurring;
use App\Support\MonthlySummary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 定期的な記録（画面設計書 5.10）。一覧と、変更の画面を開くところ。入力欄と保存は Livewire の部品（app/Livewire/RecurringForm.php）
 */
class RecurringController extends Controller
{
    /**
     * 上は月ごとの金額（app/Livewire/MonthlyAmounts.php）。開いたときは今月で、URL の ?month=2026-11 で月を切りかえる。
     * 前は登録した予定のいちばん早い最初の月まで、先は 12 か月先まで（画面設計書 5.10）。
     *
     * 下は一覧：毎月の日付の早い順（同じ日付なら最初の月が早い順）。
     * 終わったもの（最後の月の次の月の 1 日から）と「使わない」ものは、いちばん下にまとめる
     */
    public function index(Request $request): View
    {
        $recurrings = Recurring::query()
            ->with(['account', 'toAccount'])
            // 概算に使う前回の金額を、一覧といっしょに読む（行ごとに聞きに行かない）
            ->withLatestAmount()
            ->orderBy('day')
            ->orderBy('start_month')
            ->orderBy('id')
            ->get();

        [$running, $stopped] = $recurrings->partition(fn (Recurring $r) => $r->is_active && ! $r->isEnded());

        $thisMonth = CarbonImmutable::today()->startOfMonth();
        $last = $thisMonth->addMonths(12);
        $first = $recurrings->min('start_month');
        $first = $first instanceof CarbonImmutable && $first->lt($thisMonth) ? $first->startOfMonth() : $thisMonth;
        $month = MonthlySummary::month($request->query('month'));
        $month = $month->lt($first) ? $first : ($month->gt($last) ? $last : $month);

        return view('recurrings.index', [
            'month' => $month,
            'hasPrevious' => $month->gt($first),
            'hasNext' => $month->lt($last),
            'running' => $running->values(),
            'stopped' => $stopped->values(),
        ]);
    }

    public function edit(Recurring $recurring): View
    {
        return view('recurrings.edit', ['recurring' => $recurring]);
    }
}
