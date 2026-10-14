@extends('layouts.app')

@section('title', '月のまとめ')

{{-- 月のまとめ（画面設計書 5.5）。切りかえ（タブ）は使わず、上から 収支 → 支出の分類 → 収入の分類 → 相手 の順に全部並べる --}}
@section('content')
    <h1 class="visually-hidden">月のまとめ</h1>

    {{-- 月の切りかえ（記録の一覧と同じ形）。月を切りかえると、下の全部がその月に変わる --}}
    <nav class="d-flex justify-content-center align-items-center gap-3 mb-3" aria-label="月の切りかえ">
        <a href="{{ route('summary.index', ['month' => $month->subMonth()->format('Y-m')]) }}"
            class="btn btn-link text-decoration-none fs-4 py-0" aria-label="前の月">‹</a>
        <h2 class="h5 mb-0">{{ $month->format('Y年n月') }}</h2>
        <a href="{{ route('summary.index', ['month' => $month->addMonth()->format('Y-m')]) }}"
            class="btn btn-link text-decoration-none fs-4 py-0" aria-label="次の月">›</a>
    </nav>

    @if (! $hasRecords)
        {{-- 記録がない月は、空白にせず次にすることを出す（4 章の 6。記録の一覧と同じ） --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-3">この月の記録はまだありません</p>
                <a href="{{ route('records.create', $month->isSameMonth(today()) ? [] : ['date' => $month->toDateString()]) }}"
                    class="btn btn-primary">＋ 記録する</a>
            </div>
        </div>
    @else
        <div class="row g-3">
            {{-- 収支：移動と調整は入れない（記録の一覧の月の合計と同じ計算） --}}
            <div class="col-12">
                <section class="card">
                    <h3 class="card-header h6 mb-0">収支</h3>
                    <div class="card-body">
                        <p class="mb-1">
                            収入 <x-money :amount="$income" class="fw-bold text-success" />
                            ／ 支出 <x-money :amount="$expense" />
                        </p>
                        <p class="mb-0 fs-5">差 <x-money :amount="$income - $expense" class="fw-bold" /></p>
                    </div>
                </section>
            </div>

            <div class="col-12 col-md-6">
                <section class="card h-100">
                    <h3 class="card-header h6 mb-0">支出の分類</h3>
                    @include('summary._categories', ['rows' => $expenseCategories, 'prefix' => 'expense', 'empty' => 'この月の支出はありません'])
                </section>
            </div>

            <div class="col-12 col-md-6">
                <section class="card h-100">
                    <h3 class="card-header h6 mb-0">収入の分類</h3>
                    @include('summary._categories', ['rows' => $incomeCategories, 'prefix' => 'income', 'empty' => 'この月の収入はありません'])
                </section>
            </div>

            {{-- 相手ごとの支出：多い順に 5 件。6 件目からは「ほかの相手も見る」で開く --}}
            <div class="col-12 col-md-6">
                <section class="card h-100">
                    <h3 class="card-header h6 mb-0">相手ごとの支出</h3>
                    @if ($partners->isEmpty())
                        <p class="card-body text-secondary small mb-0">この月の支出はありません</p>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach ($partners->take(5) as $row)
                                @include('summary._partner')
                            @endforeach
                            @if ($partners->count() > 5)
                                <div class="collapse" id="more-partners">
                                    @foreach ($partners->slice(5) as $row)
                                        @include('summary._partner')
                                    @endforeach
                                </div>
                                <button type="button" class="list-group-item list-group-item-action text-center text-primary small"
                                    data-bs-toggle="collapse" data-bs-target="#more-partners" aria-expanded="false" aria-controls="more-partners">
                                    ほかの相手も見る（{{ $partners->count() - 5 }} 件） <x-chevron class="align-text-bottom" />
                                </button>
                            @endif
                        </div>
                    @endif
                </section>
            </div>
        </div>
    @endif
@endsection
