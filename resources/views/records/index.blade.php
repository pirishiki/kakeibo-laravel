@extends('layouts.app')

@section('title', '記録の一覧')

{{-- 記録の一覧（画面設計書 5.3）。1 か月ずつ、日付ごとに見出しをつけて、新しい日を上に出す --}}
@section('content')
    <h1 class="visually-hidden">記録の一覧</h1>

    {{-- 月の切りかえと月の合計 --}}
    <div class="text-center mb-3">
        <nav class="d-flex justify-content-center align-items-center gap-3" aria-label="月の切りかえ">
            <a href="{{ route('records.index', ['month' => $month->subMonth()->format('Y-m')]) }}"
                class="btn btn-link text-decoration-none fs-4 py-0" aria-label="前の月">‹</a>
            <h2 class="h5 mb-0">{{ $month->format('Y年n月') }}</h2>
            <a href="{{ route('records.index', ['month' => $month->addMonth()->format('Y-m')]) }}"
                class="btn btn-link text-decoration-none fs-4 py-0" aria-label="次の月">›</a>
        </nav>
        <p class="mb-0 small">
            収入 <x-money :amount="$income" class="fw-bold text-success" />
            ／ 支出 <x-money :amount="$expense" />
            ／ 差 <x-money :amount="$income - $expense" />
        </p>
    </div>

    @if ($days->isEmpty())
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
        @foreach ($days as $date => $records)
            {{-- id は、ホームのカレンダーの日を押したときに、その日の見出しまで動くための目印（画面設計書 5.2） --}}
            <section class="card mb-3" id="day-{{ $date }}">
                {{-- 日付の見出し：その日の支出の合計だけを出す（収入・移動・調整は足さない） --}}
                <h3 class="card-header h6 mb-0 d-flex justify-content-between align-items-center gap-3">
                    <x-date :date="$records->first()->date" />
                    <span class="fw-normal text-nowrap">支出 <x-money :amount="$dayExpenses[$date]" /></span>
                </h3>
                <div class="list-group list-group-flush">
                    @foreach ($records as $record)
                        @include('records._row')
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
@endsection
