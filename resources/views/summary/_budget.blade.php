@use('App\Support\Budgets')

{{-- 月のまとめの、予算のある分類の行の下に出す予算の状況（画面設計書 5.5）。$spent は使った額、$budget はその月の予算、$child は小分類の行か。
     こえたときは赤、80% 以上（80% ちょうどもふくむ）でまだこえていないときはオレンジ（ホームのお知らせと同じ決まり。app/Support/Budgets.php） --}}
@php($status = Budgets::status($spent, $budget))
@php($width = min(100, $status['percent']))
<div @class(['list-group-item summary-budget pt-0 small', 'ps-4' => $child])>
    <div class="d-flex align-items-center gap-2">
        <span class="text-secondary text-nowrap">予算 {{ number_format($budget) }}</span>
        <div class="progress flex-grow-1 summary-budget-bar" role="progressbar" aria-label="予算のうち使った割合"
            aria-valuenow="{{ $width }}" aria-valuemin="0" aria-valuemax="100">
            <div @class(['progress-bar', 'bg-danger' => $status['over'], 'bg-caution' => $status['warn'] && ! $status['over']]) style="width: {{ $width }}%"></div>
        </div>
        <span @class(['text-nowrap text-end', 'fw-bold' => $status['warn'], 'text-danger' => $status['over'], 'text-caution' => $status['warn'] && ! $status['over']])>
            @if ($status['over'])
                {{ number_format(-$status['rest']) }} こえた
            @else
                あと {{ number_format($status['rest']) }}（{{ $status['percent'] }}%）
            @endif
        </span>
    </div>
</div>
