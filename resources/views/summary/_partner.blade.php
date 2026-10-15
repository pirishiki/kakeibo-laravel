@use('App\Support\RecordSearch')

{{-- 相手ごとの支出の 1 行。$row は MonthlySummary::partnerExpenses() の 1 つ
     押すと、その月・その相手の支出で探した記録の一覧を開く（「支出だけ」の条件もつける。金額が支出だけの合計のため。画面設計書 5.3） --}}
<a href="{{ route('records.index', RecordSearch::monthParams($month, ['partner' => $row['partner']->id, 'type' => 'expense'])) }}"
    class="list-group-item list-group-item-action d-flex align-items-center gap-2">
    <span class="flex-grow-1 text-truncate">{{ $row['partner']->name }}</span>
    <x-money :amount="$row['amount']" class="text-nowrap" />
    <span class="visually-hidden">の支出の記録を見る</span>
    <span class="summary-caret-space text-center text-secondary" aria-hidden="true">›</span>
</a>
