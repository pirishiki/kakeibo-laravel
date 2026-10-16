{{-- 目標の一覧の 1 行（画面設計書 5.13）。$row は app/Support/Goals.php の progress() の結果。押すと変更の画面へ --}}
@php($goal = $row['goal'])
<a href="{{ route('goals.edit', $goal) }}"
    @class(['list-group-item list-group-item-action', 'text-secondary' => $row['achieved']])>
    <div class="d-flex justify-content-between align-items-baseline gap-2">
        <span class="text-break">
            {{ $goal->name }}
            @if ($row['achieved'])
                <span class="ms-1">✓ 達成</span>
            @endif
        </span>
        <span class="text-nowrap">
            {{-- 達成した目標は % を出さない（100% をこえた数より、「✓ 達成」で伝えるほうがわかりやすいため。画面設計書 5.13 の図） --}}
            <x-money :amount="$row['balance']" /> / {{ number_format($goal->target_amount) }}@unless ($row['achieved'])（{{ $row['percent'] }}%）@endunless
            <span class="text-secondary ms-1" aria-hidden="true">›</span>
        </span>
    </div>
    {{-- 棒：残高がマイナス（0%）のときは出さない。達成したものは出さない（薄い字の行にする） --}}
    @if (! $row['achieved'] && $row['percent'] > 0)
        <div class="progress goal-bar my-1" role="progressbar" aria-label="目標のうち貯まった割合"
            aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: {{ $row['percent'] }}%"></div>
        </div>
    @endif
    <div class="small text-secondary">
        {{ $goal->account->name }}・{{ $goal->deadline_month !== null ? $goal->deadline_month->format('Y年n月').'まで' : '期限なし' }}
        @if ($row['monthsLeft'] !== null)
            <br>（あと {{ $row['monthsLeft'] }} か月、1 か月あたり {{ number_format($row['perMonth']) }} 円）
        @endif
    </div>
    @if ($row['overdue'])
        <div class="small text-danger fw-bold">期限を過ぎました</div>
    @endif
</a>
