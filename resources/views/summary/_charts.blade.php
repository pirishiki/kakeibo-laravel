{{-- 月のまとめの棒グラフ・折れ線グラフ（画面設計書 5.5）。選んだ月までの 6 か月（使い始めた月より前は出さない）。
     数字はサーバーで計算したもの（app/Support/MonthlyCharts.php）。絵は resources/js/charts.js が Chart.js で描く。
     画面を読み上げる機械のために、同じ数字を表でも持っておく（見た目には出さない） --}}
@php($labels = array_map(fn ($m) => $m->format('n月'), $chartMonths))
<div class="row g-3 mt-0">
    <div class="col-12 col-md-6">
        <section class="card h-100">
            <h3 class="card-header h6 mb-0">月ごとの収入・支出</h3>
            <div class="card-body">
                <div class="chart-box">
                    <canvas data-chart="bar" data-chart-data="{{ json_encode([
                        'labels' => $labels,
                        'income' => array_column($monthlyIncomeExpense, 'income'),
                        'expense' => array_column($monthlyIncomeExpense, 'expense'),
                    ]) }}" role="img" aria-label="月ごとの収入と支出の棒グラフ"></canvas>
                </div>
                <table class="visually-hidden">
                    <caption>月ごとの収入・支出</caption>
                    <thead><tr><th scope="col">月</th><th scope="col">収入</th><th scope="col">支出</th></tr></thead>
                    <tbody>
                        @foreach ($chartMonths as $i => $chartMonth)
                            <tr>
                                <th scope="row">{{ $chartMonth->format('Y年n月') }}</th>
                                <td>{{ number_format($monthlyIncomeExpense[$i]['income']) }} 円</td>
                                <td>{{ number_format($monthlyIncomeExpense[$i]['expense']) }} 円</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-12 col-md-6">
        <section class="card h-100">
            <h3 class="card-header h6 mb-0">全体の残高の移り変わり</h3>
            <div class="card-body">
                <div class="chart-box">
                    <canvas data-chart="line" data-chart-data="{{ json_encode([
                        'labels' => $labels,
                        'balances' => $monthEndBalances,
                    ]) }}" role="img" aria-label="各月末の合計残高の折れ線グラフ"></canvas>
                </div>
                {{-- 来月から先の月は、まだ残高がないので線を引かない --}}
                <p class="small text-secondary mb-0 mt-2">各月末の、全部の口座の合計です。今月は今日までの残高です。</p>
                <table class="visually-hidden">
                    <caption>各月末の合計残高</caption>
                    <thead><tr><th scope="col">月</th><th scope="col">残高</th></tr></thead>
                    <tbody>
                        @foreach ($chartMonths as $i => $chartMonth)
                            <tr>
                                <th scope="row">{{ $chartMonth->format('Y年n月') }}</th>
                                <td>{{ $monthEndBalances[$i] === null ? 'まだありません' : number_format($monthEndBalances[$i]).' 円' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
