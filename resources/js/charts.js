// 月のまとめのグラフ（画面設計書 5.5）。数字はサーバーで計算して canvas の data-chart-data に入れてあり、ここでは絵を描くだけ
// （表とグラフの金額がずれないように）。Chart.js は使う部品だけを読みこんで、ファイルを小さくする
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    Legend,
} from 'chart.js';

Chart.register(ArcElement, BarController, BarElement, CategoryScale, DoughnutController, LinearScale, LineController, LineElement, PointElement, Tooltip, Legend);

// 色（画面設計書 6.3）と字（6.4）
const colors = { primary: '#0b7a75', income: '#4a8f1c', text: '#23271f', muted: '#6b7166', grid: '#e3e5e1' };
Chart.defaults.font.family = "'Noto Sans JP', system-ui, sans-serif";
Chart.defaults.color = colors.muted;

const yen = (value) => `${Number(value).toLocaleString('ja-JP')} 円`;
// 軸の目もりは短く：1 万円以上は「12万」（ホームのカレンダーと同じ考え方）
const shortYen = (value) => (Math.abs(value) >= 10000 ? `${Math.round(value / 1000) / 10}万` : Number(value).toLocaleString('ja-JP'));

// 動きを減らす設定の人には、描くときの動きをなくす
const animation = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : undefined;

const builders = {
    // 円グラフ：まん中を少しあけたドーナツの形。名前と割合は、横の一覧に字で出してある
    pie: (data) => ({
        type: 'doughnut',
        data: {
            labels: data.labels,
            datasets: [{ data: data.values, backgroundColor: data.colors, borderColor: '#fff', borderWidth: 2 }],
        },
        options: {
            animation,
            maintainAspectRatio: false,
            cutout: '45%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => `${ctx.label} ${yen(ctx.parsed)}` } },
            },
        },
    }),
    // 棒グラフ：収入は緑、支出はふつうの字の色（支出は赤にしない。6.3）
    bar: (data) => ({
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [
                { label: '収入', data: data.income, backgroundColor: colors.income },
                { label: '支出', data: data.expense, backgroundColor: colors.text },
            ],
        },
        options: {
            animation,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: colors.grid }, ticks: { callback: shortYen } },
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12 } },
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label} ${yen(ctx.parsed.y)}` } },
            },
        },
    }),
    // 折れ線グラフ：基本の青緑。来月から先（null）は線を引かない
    line: (data) => ({
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{ label: '合計残高', data: data.balances, borderColor: colors.primary, backgroundColor: colors.primary, tension: 0, spanGaps: false }],
        },
        options: {
            animation,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { display: false } },
                y: { grid: { color: colors.grid }, ticks: { callback: shortYen } },
            },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => yen(ctx.parsed.y) } },
            },
        },
    }),
};

export function drawCharts(canvases) {
    canvases.forEach((canvas) => {
        const build = builders[canvas.dataset.chart];
        if (build) {
            new Chart(canvas, build(JSON.parse(canvas.dataset.chartData)));
        }
    });
}
