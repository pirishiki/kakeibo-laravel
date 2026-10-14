<?php

namespace App\Http\Controllers;

use App\Models\Recurring;
use Illuminate\View\View;

/**
 * 定期的な記録（画面設計書 5.10）。一覧と、変更の画面を開くところ。入力欄と保存は Livewire の部品（app/Livewire/RecurringForm.php）
 */
class RecurringController extends Controller
{
    /**
     * 一覧：毎月の日付の早い順（同じ日付なら最初の月が早い順）。
     * 終わったもの（最後の月の次の月の 1 日から）と「使わない」ものは、いちばん下にまとめる
     */
    public function index(): View
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

        return view('recurrings.index', [
            'running' => $running->values(),
            'stopped' => $stopped->values(),
        ]);
    }

    public function edit(Recurring $recurring): View
    {
        return view('recurrings.edit', ['recurring' => $recurring]);
    }
}
