<?php

namespace App\Http\Controllers;

use App\Enums\RecordType;
use App\Models\Record;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 記録の一覧（画面設計書 5.3）。登録は Livewire の部品（app/Livewire/RecordForm.php）
 */
class RecordController extends Controller
{
    public function index(Request $request): View
    {
        $month = $this->month($request->query('month'));

        // その月の 1 日から末日までの記録を、新しい日が上・同じ日の中はあとで入力したものが上に並べる。
        // 行に出す口座・分類（小分類のときは上の大分類も）・相手は、with() で記録といっしょにまとめて読む（1 件ずつ読みに行かない）
        $records = Record::query()
            ->whereBetween('date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->with(['account', 'toAccount', 'category.parent', 'partner'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        // 月の合計：移動と調整は入れない（ホームの今月の収支・月のまとめと同じ数え方）
        $income = (int) $records->where('type', RecordType::Income)->sum('amount');
        $expense = (int) $records->where('type', RecordType::Expense)->sum('amount');

        // 日付ごとのかたまりに分ける。並びは上の orderBy のまま（新しい日が上）
        $days = $records->groupBy(fn (Record $record) => $record->date->toDateString());

        return view('records.index', [
            'month' => $month,
            'income' => $income,
            'expense' => $expense,
            'days' => $days,
            // 日付の見出しに出す、その日の支出の合計（収入・移動・調整は足さない）
            'dayExpenses' => $days->map(fn ($dayRecords) => (int) $dayRecords->where('type', RecordType::Expense)->sum('amount')),
        ]);
    }

    /**
     * 記録の編集（画面設計書 5.4）。入力欄と保存・削除は、登録と同じ Livewire の部品（RecordForm）
     */
    public function edit(Record $record): View
    {
        return view('records.edit', ['record' => $record]);
    }

    /**
     * ?month=2026-09 から、見る月の 1 日を出す。書いていないとき・形がちがうときは今月
     */
    private function month(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m', $value);
        }

        return CarbonImmutable::today()->startOfMonth();
    }
}
