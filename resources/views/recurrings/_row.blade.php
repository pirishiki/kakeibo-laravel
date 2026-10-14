{{-- 定期的な記録の一覧の 1 行（画面設計書 5.10）。押すと変更の画面へ
     1 行目：名前。2 行目：日付（ずらし方）・種類・金額。移動は「どこから → どこへ」。
     最初の月が先のものは「○月から」、最後の月があるものは「○月まで」 --}}
@php
    $stopped = ! $recurring->is_active || $recurring->isEnded();
    $typeLabel = ['income' => '収入', 'expense' => '支出', 'transfer' => '移動'][$recurring->type->value];
@endphp
<a href="{{ route('recurrings.edit', $recurring) }}"
    @class(['list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3', 'text-secondary' => $stopped])>
    <span class="min-w-0">
        <span class="d-block text-break">{{ $recurring->name }}@if (! $recurring->is_active)（使わない）@endif</span>
        <span @class(['d-block small text-break', 'text-secondary' => ! $stopped])>
            {{ $recurring->scheduleLabel() }}・{{ $typeLabel }}@if ($recurring->is_fixed)・{{ number_format((int) $recurring->amount) }}@endif
        </span>
        @if ($recurring->type->value === 'transfer')
            <span @class(['d-block small text-break', 'text-secondary' => ! $stopped])>{{ $recurring->account->name }} → {{ $recurring->toAccount?->name }}</span>
        @endif
        @if (! $recurring->is_fixed)
            <span @class(['d-block small', 'text-secondary' => ! $stopped])>金額が変わる（概算 {{ number_format((int) $recurring->estimate()) }}）</span>
        @endif
        @if ($recurring->start_month->gt(today()->startOfMonth()) || $recurring->end_month !== null)
            <span @class(['d-block small', 'text-secondary' => ! $stopped])>
                @if ($recurring->start_month->gt(today()->startOfMonth()))
                    {{ $recurring->start_month->format('Y年n月') }}から
                @endif
                @if ($recurring->end_month !== null)
                    {{ $recurring->end_month->format('Y年n月') }}まで
                @endif
            </span>
        @endif
    </span>
    <span class="text-secondary" aria-hidden="true">›</span>
</a>
