@use('App\Support\HomeCalendar')

@extends('layouts.app')

@section('title', 'ホーム')

{{-- ホーム（画面設計書 5.2）。カード型（6.1）で、上から お知らせ → 使える額 → 今月のカレンダー → 小さなカード（残高・収支・多く使った分類・貯金の目標）→ 最近の記録 --}}
@section('content')
    <h1 class="visually-hidden">ホーム</h1>

    <div class="row g-3">
        {{-- お知らせ：金額がまだの定期的な記録（第 2 段階）。あるときだけ、いちばん上に出す --}}
        <livewire:pending-amounts :shortages="$shortages" :budget-alerts="$budgetAlerts" :today="$today->toDateString()" :draft="$draft" />

        {{-- 使える額（第 2 段階）：いちばん大きな、色つきのカード。1 日あたり・1 週間あたりと、押すと開く内訳 3 行 --}}
        @if ($accounts->isNotEmpty())
            <div class="col-12">
                <section class="card home-card home-available">
                    <div class="card-body">
                        <h2 class="home-label">使える額</h2>
                        <p class="home-available-value mb-1"><x-money :amount="$available['available']" /></p>
                        <p class="small mb-2">
                            1日あたり <x-money :amount="$available['perDay']" class="fw-bold" />
                            ／ {{ min(7, $available['daysLeft']) === 7 ? '1週間' : 'あと'.$available['daysLeft'].'日' }} <x-money :amount="$available['perWeek']" class="fw-bold" />
                        </p>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-inline-flex align-items-center gap-1"
                            data-bs-toggle="collapse" data-bs-target="#home-available-detail" aria-expanded="false" aria-controls="home-available-detail">
                            内訳 <x-chevron />
                        </button>
                        <div class="collapse" id="home-available-detail">
                            <dl class="home-available-rows small mt-2 mb-0">
                                <div><dt>今の合計</dt><dd><x-money :amount="$available['total']" /></dd></div>
                                <div><dt>− 取っておく額</dt><dd><x-money :amount="$available['reserved']" /></dd></div>
                                <div>
                                    <dt>
                                        @if ($available['plans'] === [])
                                            − 今月末までの予定
                                        @else
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none d-inline-flex align-items-center gap-1 text-start"
                                                data-bs-toggle="collapse" data-bs-target="#home-available-plans" aria-expanded="false" aria-controls="home-available-plans">
                                                − 今月末までの予定（{{ count($available['plans']) }} 件） <x-chevron />
                                            </button>
                                        @endif
                                    </dt>
                                    <dd><x-money :amount="$available['planned']" /></dd>
                                </div>
                            </dl>
                            @if ($available['plans'] !== [])
                                {{-- 予定の 1 件ずつ。金額がまだのものは「概算」の印 --}}
                                <ul class="collapse list-unstyled small text-secondary border-top mt-2 pt-2 mb-0" id="home-available-plans">
                                    @foreach ($available['plans'] as $plan)
                                        <li class="d-flex justify-content-between align-items-baseline gap-2">
                                            <span class="text-break">
                                                <x-date :date="$plan['date']" /> {{ $plan['name'] }}
                                                @if ($plan['estimated'])
                                                    <span class="badge rounded-pill text-secondary bg-secondary-subtle fw-normal">概算</span>
                                                @endif
                                            </span>
                                            <x-money :amount="$plan['amount']" class="text-nowrap" />
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </section>
            </div>
        @endif

        {{-- 今月のカレンダー：今月だけ（ホームでは月を切りかえない）。マスには、その日の支出（ふつうの字）と収入（緑の太字に「+」）。移動と調整は出さない --}}
        <div class="col-12">
            <section class="card home-card">
                {{-- スマホではマスを広くするため、カードの余白を小さくする --}}
                <div class="card-body p-2 p-lg-3">
                    <h2 class="home-label mb-2">今月（{{ $month->format('n月') }}）のカレンダー</h2>
                    <div class="home-cal">
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
                                        $planExpense = $plans[$date]['expense'] ?? 0;
                                        $planIncome = $plans[$date]['income'] ?? 0;
                                        $jump = HomeCalendar::jumpDate($day, $recordDates);
                                    @endphp
                                    {{-- 押すと、記録の一覧の今月のその日の見出しへ（その日に記録がないときは、その日より前でいちばん近い記録のある日） --}}
                                    {{-- 読み上げでは「10月9日（金） 支出 1,200」のように、月と曜日もつけて読む --}}
                                    {{-- 月も URL に入れる（ホームを開いたまま 0 時をこえて月が変わっても、カレンダーの月の一覧を開くように） --}}
                                    <a href="{{ route('records.index', ['month' => $day->format('Y-m')]).($jump === null ? '' : '#day-'.$jump) }}"
                                        @class(['home-cal-day', 'is-today' => $day->isToday()])
                                        @if ($day->isToday()) aria-current="date" @endif>
                                        <span class="home-cal-num" aria-hidden="true">{{ $day->day }}</span>
                                        <x-date :date="$day" class="visually-hidden" />
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
                                        {{-- まだ記録されていない定期的な記録（灰色）。収入の予定は「+」をつける --}}
                                        @if ($planExpense > 0)
                                            <span class="home-cal-amount text-secondary">
                                                <span class="visually-hidden">支出の予定</span>
                                                <span class="d-none d-lg-inline">{{ number_format($planExpense) }}</span><span class="d-lg-none">{{ HomeCalendar::shortAmount($planExpense) }}</span>
                                            </span>
                                        @endif
                                        @if ($planIncome > 0)
                                            <span class="home-cal-amount text-secondary">
                                                <span class="visually-hidden">収入の予定</span>
                                                <span class="d-none d-lg-inline">+{{ number_format($planIncome) }}</span><span class="d-lg-none">+{{ HomeCalendar::shortAmount($planIncome) }}</span>
                                            </span>
                                        @endif
                                    </a>
                                @endif
                            @endforeach
                        @endforeach
                    </div>
                    <p class="small text-secondary mb-0 mt-2">
                        ふつうの字＝支出　<span class="fw-bold text-success">緑の太字（+）</span>＝収入　灰色＝これからの予定　日を押すと、その日の記録へ
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
                                    <span class="text-break">
                                        {{ $account->name }}
                                        @include('accounts._reserve')
                                    </span>
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

        {{-- 貯金の目標（第 3 段階）：達成していない目標ごとの進み具合（%）を、一覧と同じ順で。達成したものは出さない。カードを押すと貯金の目標へ --}}
        <div class="col-6 col-lg-4">
            <section class="card home-card h-100">
                <div class="card-body">
                    <h2 class="home-label">
                        <a href="{{ route('goals.index') }}" class="stretched-link text-reset text-decoration-none">貯金の目標</a>
                    </h2>
                    @if ($goals === [])
                        <p class="small text-secondary mb-0">{{ $hasGoals ? '全部の目標を達成しました' : 'まだ目標がありません' }}</p>
                    @else
                        <ul class="list-unstyled small mb-0">
                            @foreach ($goals as $row)
                                <li class="d-flex justify-content-between align-items-baseline gap-2">
                                    <span class="text-truncate">{{ $row['goal']->name }}</span>
                                    <span class="text-nowrap">{{ $row['percent'] }}%</span>
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
