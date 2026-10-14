@use('App\Support\HomeCalendar')

@extends('layouts.app')

@section('title', 'ホーム')

{{-- ホーム（画面設計書 5.2）。カード型（6.1）で、上から 今月のカレンダー → 小さなカード（残高・収支・多く使った分類）→ 最近の記録。
     お知らせ・使える額（第 2 段階）と貯金の目標（第 3 段階）は、その段階で足す --}}
@section('content')
    <h1 class="visually-hidden">ホーム</h1>

    <div class="row g-3">
        {{-- 今月のカレンダー：今月だけ（ホームでは月を切りかえない）。マスには、その日の支出（ふつうの字）と収入（緑の太字に「+」）。移動と調整は出さない --}}
        <div class="col-12">
            <section class="card home-card">
                <div class="card-body">
                    <h2 class="home-label mb-2">今月（{{ $month->format('n月') }}）のカレンダー</h2>
                    <div class="home-cal" role="list">
                        @foreach (['日', '月', '火', '水', '木', '金', '土'] as $dow)
                            <div class="home-cal-dow" aria-hidden="true">{{ $dow }}</div>
                        @endforeach
                        @foreach ($weeks as $week)
                            @foreach ($week as $day)
                                @if ($day === null)
                                    <div aria-hidden="true"></div>
                                @else
                                    @php
                                        $date = $day->toDateString();
                                        $dayIncome = $days[$date]['income'] ?? 0;
                                        $dayExpense = $days[$date]['expense'] ?? 0;
                                        $jump = HomeCalendar::jumpDate($day, $recordDates);
                                    @endphp
                                    {{-- 押すと、記録の一覧の今月のその日の見出しへ（その日に記録がないときは、その日より前でいちばん近い記録のある日） --}}
                                    <a href="{{ route('records.index').($jump === null ? '' : '#day-'.$jump) }}" role="listitem"
                                        @class(['home-cal-day', 'is-today' => $day->isToday()])
                                        @if ($day->isToday()) aria-current="date" @endif>
                                        <span class="home-cal-num">{{ $day->day }}<span class="visually-hidden">日</span></span>
                                        @if ($dayExpense > 0)
                                            <span class="home-cal-amount">
                                                <span class="visually-hidden">支出</span>
                                                {{-- パソコンでは金額をそのまま、スマホでは 1 万円以上を「12.2万」の形に短くする --}}
                                                <span class="d-none d-lg-inline">{{ number_format($dayExpense) }}</span><span class="d-lg-none">{{ HomeCalendar::shortAmount($dayExpense) }}</span>
                                            </span>
                                        @endif
                                        @if ($dayIncome > 0)
                                            <span class="home-cal-amount fw-bold text-success">
                                                <span class="visually-hidden">収入</span>
                                                <span class="d-none d-lg-inline">+{{ number_format($dayIncome) }}</span><span class="d-lg-none">+{{ HomeCalendar::shortAmount($dayIncome) }}</span>
                                            </span>
                                        @endif
                                    </a>
                                @endif
                            @endforeach
                        @endforeach
                    </div>
                    <p class="small text-secondary mb-0 mt-2">
                        ふつうの字＝支出　<span class="fw-bold text-success">緑の太字（+）</span>＝収入　日を押すと、その日の記録へ
                    </p>
                </div>
            </section>
        </div>

        {{-- 今の残高の合計：今日までの記録で計算する。「口座ごと」を押すと、その場で口座ごとの残高が開く --}}
        <div class="col-6 col-lg-4">
            <section class="card home-card h-100">
                <div class="card-body">
                    <h2 class="home-label">今の残高の合計</h2>
                    @if ($accounts->isEmpty())
                        <p class="small mb-2">まだ口座がありません</p>
                        <a href="{{ route('accounts.create') }}" class="btn btn-sm btn-outline-primary">口座を追加する</a>
                    @else
                        <p class="home-value mb-1"><x-money :amount="array_sum($balances)" /></p>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-inline-flex align-items-center gap-1"
                            data-bs-toggle="collapse" data-bs-target="#home-accounts" aria-expanded="false" aria-controls="home-accounts">
                            口座ごと <x-chevron />
                        </button>
                    @endif
                </div>
                @if ($accounts->isNotEmpty())
                    {{-- 行を押すと、残高を合わせる画面へ --}}
                    <div class="collapse" id="home-accounts">
                        <div class="list-group list-group-flush border-top">
                            @foreach ($accounts as $account)
                                <a href="{{ route('accounts.adjust', $account) }}"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-baseline gap-2 small">
                                    <span class="text-break">{{ $account->name }}</span>
                                    <x-money :amount="$balances[$account->id]" class="text-nowrap" />
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        </div>

        {{-- 今月の収支：移動と調整は入れない（記録の一覧・月のまとめと同じ計算）。カードを押すと、月のまとめの今月へ --}}
        <div class="col-6 col-lg-4">
            <section class="card home-card h-100">
                <div class="card-body">
                    <h2 class="home-label">
                        <a href="{{ route('summary.index') }}" class="stretched-link text-reset text-decoration-none">今月の収支</a>
                    </h2>
                    <p class="home-value mb-1"><x-money :amount="$income - $expense" /></p>
                    <p class="small mb-0">
                        収入 <x-money :amount="$income" class="fw-bold text-success" /><br>
                        支出 <x-money :amount="$expense" />
                    </p>
                </div>
            </section>
        </div>

        {{-- 多く使った分類：今月の支出の大分類を、多い順に 3 つ。カードを押すと、月のまとめの今月へ --}}
        <div class="col-6 col-lg-4">
            <section class="card home-card h-100">
                <div class="card-body">
                    <h2 class="home-label">
                        <a href="{{ route('summary.index') }}" class="stretched-link text-reset text-decoration-none">多く使った分類</a>
                    </h2>
                    @if ($topCategories->isEmpty())
                        <p class="small text-secondary mb-0">今月の支出はまだありません</p>
                    @else
                        <ul class="list-unstyled small mb-0">
                            @foreach ($topCategories as $row)
                                <li class="d-flex justify-content-between align-items-baseline gap-2">
                                    <span class="text-truncate">{{ $row['category']->name }}</span>
                                    <x-money :amount="$row['amount']" class="text-nowrap" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        </div>

        {{-- 最近の記録：最後に入力した 5 件（入力した順）。入れたものがちゃんと入ったかを、すぐ確かめられるように --}}
        <div class="col-12">
            <section class="card home-card">
                <h2 class="home-label card-body pb-0 mb-0">最近の記録</h2>
                @if ($recentRecords->isEmpty())
                    <div class="card-body text-center">
                        <p class="mb-3">まだ記録がありません</p>
                        <a href="{{ route('records.create') }}" class="btn btn-primary">＋ 記録する</a>
                    </div>
                @else
                    <div class="list-group list-group-flush mt-2">
                        @foreach ($recentRecords as $record)
                            @include('records._row', ['showDate' => true])
                        @endforeach
                        <a href="{{ route('records.index') }}" class="list-group-item list-group-item-action text-end text-primary small">記録の一覧へ ›</a>
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
