{{-- 月のまとめの円グラフ（画面設計書 5.5）。$pie は MonthlyCharts::pie()。絵は resources/js/charts.js が Chart.js で描く。
     色と名前と割合は、グラフの横に字でも並べる（色だけにたよらないため。スマホでもグラフにさわらずに読めるように） --}}
@php($pieTotal = array_sum(array_column($pie, 'amount')))
<div class="card-body d-flex align-items-center gap-3 flex-wrap border-bottom">
    <div class="chart-box-pie">
        <canvas data-chart="pie" data-chart-data="{{ json_encode([
            'labels' => array_column($pie, 'name'),
            'values' => array_column($pie, 'amount'),
            'colors' => array_column($pie, 'color'),
        ]) }}" role="img" aria-label="支出の大分類ごとの割合の円グラフ（内訳は横の一覧）"></canvas>
    </div>
    <ul class="list-unstyled small mb-0 flex-grow-1 min-w-0">
        @foreach ($pie as $slice)
            <li class="d-flex align-items-center gap-2">
                <span class="chart-swatch" style="background-color: {{ $slice['color'] }}" aria-hidden="true"></span>
                <span class="flex-grow-1 text-truncate">{{ $slice['name'] }}</span>
                {{-- 割合は、ほかの % と同じく 1 % 未満を切り捨てる --}}
                <span class="text-nowrap">{{ intdiv($slice['amount'] * 100, $pieTotal) }}%</span>
            </li>
        @endforeach
    </ul>
</div>
