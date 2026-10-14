{{-- 相手ごとの支出の 1 行。$row は MonthlySummary::partnerExpenses() の 1 つ --}}
<div class="list-group-item d-flex align-items-center gap-2">
    <span class="flex-grow-1 text-truncate">{{ $row['partner']->name }}</span>
    <x-money :amount="$row['amount']" class="text-nowrap" />
</div>
