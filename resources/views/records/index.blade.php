@extends('layouts.app')

@section('title', '記録の一覧')

{{-- 記録の一覧（画面設計書 5.3）。1 か月ずつ、日付ごとに見出しをつけて、新しい日を上に出す --}}
@section('content')
    <h1 class="visually-hidden">記録の一覧</h1>

    <div class="text-center mb-3">
        @if (! $searching)
            {{-- 月の切りかえと、その横の「探す」 --}}
            <div class="position-relative">
                <nav class="d-flex justify-content-center align-items-center gap-3" aria-label="月の切りかえ">
                    <a href="{{ route('records.index', ['month' => $month->subMonth()->format('Y-m')]) }}"
                        class="btn btn-link text-decoration-none fs-4 py-0" aria-label="前の月">‹</a>
                    <h2 class="h5 mb-0">{{ $month->format('Y年n月') }}</h2>
                    <a href="{{ route('records.index', ['month' => $month->addMonth()->format('Y-m')]) }}"
                        class="btn btn-link text-decoration-none fs-4 py-0" aria-label="次の月">›</a>
                </nav>
                <button type="button" class="btn btn-sm btn-outline-primary position-absolute end-0 top-50 translate-middle-y"
                    data-bs-toggle="collapse" data-bs-target="#search-panel" aria-expanded="false" aria-controls="search-panel">探す</button>
            </div>
        @else
            {{-- 探しているあいだは、月の切りかえのかわりに条件と件数を出す（画面設計書 5.3） --}}
            <h2 class="h5 mb-1">探した結果
                @if ($searchErrors->isEmpty())
                    <span class="fw-normal">{{ number_format($count) }} 件</span>
                @endif
            </h2>
            @if ($search)
                <ul class="list-inline small mb-1">
                    @foreach ($search->conditions() as $condition)
                        <li class="list-inline-item"><span class="text-secondary">{{ $condition['label'] }}</span> {{ $condition['value'] }}</li>
                    @endforeach
                </ul>
            @endif
        @endif
        @if ($searchErrors->isEmpty())
            {{-- 合計：移動と調整は入れない。探しているときは、見つかった記録だけで計算する --}}
            <p class="mb-0 small">
                収入 <x-money :amount="$income" class="fw-bold text-success" />
                ／ 支出 <x-money :amount="$expense" />
                ／ 差 <x-money :amount="$income - $expense" />
            </p>
        @endif
        @if ($searching)
            <div class="d-flex justify-content-center gap-2 mt-2">
                <button type="button" @class(['btn btn-sm btn-outline-primary', 'collapsed' => $searchErrors->isEmpty()])
                    data-bs-toggle="collapse" data-bs-target="#search-panel"
                    aria-expanded="{{ $searchErrors->isEmpty() ? 'false' : 'true' }}" aria-controls="search-panel">条件を変える</button>
                <a href="{{ route('records.index') }}" class="btn btn-sm btn-outline-secondary">条件を消す</a>
            </div>
        @endif
    </div>

    {{-- 「探す」の欄。条件にまちがいがあるときは、開いて知らせを出す --}}
    @include('records._search')

    @if ($searchErrors->isNotEmpty())
        {{-- まちがった条件で探した結果は出さない（欄の知らせを見て直してもらう） --}}
    @elseif ($firstUrl !== null && $days->isEmpty())
        {{-- 続きのページを開いたが、そのあいだにほかの画面で記録を消したなどで、続きがなくなったとき --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-3">この続きの記録はありません（ほかの画面で記録を消したときなど）</p>
                <a href="{{ $firstUrl }}" class="btn btn-outline-primary">最初から見る</a>
            </div>
        </div>
    @elseif ($searching && $days->isEmpty())
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-0">条件に合う記録はありません</p>
            </div>
        </div>
    @elseif ($days->isEmpty())
        {{-- 記録がない月は、空白にせず次にすることを出す（4 章の 6） --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-3">この月の記録はまだありません</p>
                {{-- 今月でないときは、その月の 1 日を日付の最初の値にする（見ている月の記録をつけたいときに、日付を探さずに済むように） --}}
                <a href="{{ route('records.create', $month->isSameMonth(today()) ? [] : ['date' => $month->toDateString()]) }}"
                    class="btn btn-primary">＋ 記録する</a>
            </div>
        </div>
    @else
        @php($shown = $days->flatten(1)->count())
        @if ($count > $shown)
            {{-- 1 回で出しきれないときは、何件目を出しているかを知らせる。件数と合計は、ほかのページの記録もふくめた数 --}}
            <div class="alert alert-info small d-flex justify-content-between align-items-center gap-2 flex-wrap" role="status">
                <span>{{ number_format($count) }} 件のうち、{{ number_format($start) }}〜{{ number_format($start + $shown - 1) }} 件目を出しています（新しい順）。</span>
                @if ($firstUrl !== null)
                    <a href="{{ $firstUrl }}" class="btn btn-sm btn-outline-primary">最初から見る</a>
                @endif
            </div>
        @endif
        {{-- data-records-more：続きのページがある一覧のしるし。ホームのカレンダーで押した日が、このページになかったときに、
             その日から始まる続きのページを開くために使う（resources/js/app.js） --}}
        <div @if ($nextUrl !== null) data-records-more @endif>
        @foreach ($days as $date => $records)
            {{-- id は、ホームのカレンダーの日を押したときに、その日の見出しまで動くための目印（画面設計書 5.2） --}}
            <section class="card mb-3" id="day-{{ $date }}">
                {{-- 日付の見出し：その日の支出の合計だけを出す（収入・移動・調整は足さない。探しているときは、見つかった記録だけ）。
                     日が前と次のページにまたがるときも、見出しの金額はその日の全部の分。またがることを見出しに出す --}}
                <h3 class="card-header h6 mb-0 d-flex justify-content-between align-items-center gap-3">
                    <span>
                        <x-date :date="$records->first()->date" />
                        @if ($loop->first && $firstDayContinued)
                            <span class="small fw-normal text-secondary">（前のページから続く）</span>
                        @endif
                        @if ($loop->last && $lastDayContinues)
                            <span class="small fw-normal text-secondary">（続きは次のページ）</span>
                        @endif
                    </span>
                    <span class="fw-normal text-nowrap">支出 <x-money :amount="$dayExpenses[$date] ?? 0" /></span>
                </h3>
                <div class="list-group list-group-flush">
                    @foreach ($records as $record)
                        {{-- 直したあと・やめたあとに戻る先を、URL の back で渡す（前の画面の URL をブラウザが送らない設定でも戻れるように）。
                             探した一覧なら同じ条件の一覧へ、月ごとの一覧なら記録の月の一覧へ戻る（RecordForm::backUrlAfter） --}}
                        @include('records._row', ['back' => $backUrl])
                    @endforeach
                </div>
            </section>
        @endforeach
        </div>
        @if ($nextUrl !== null)
            <div class="text-center mb-3">
                <a href="{{ $nextUrl }}" class="btn btn-outline-primary">
                    続きを見る（あと {{ number_format($count - ($start + $shown - 1)) }} 件）
                </a>
            </div>
        @endif
    @endif
@endsection
