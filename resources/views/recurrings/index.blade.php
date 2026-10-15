@extends('layouts.app')

@section('title', '定期的な記録')

{{-- 定期的な記録（画面設計書 5.10）。上に「月ごとの金額」、下に「登録してある定期的な記録」 --}}
@section('content')
    <h1 class="h4 mb-3">定期的な記録</h1>

    <div style="max-width: 560px">
        @if ($running->isNotEmpty() || $stopped->isNotEmpty())
            <section class="mb-4">
                <h2 class="h6 text-secondary">月ごとの金額</h2>
                {{-- 月の切りかえ：「◀ 9月　2026年10月　11月 ▶」。となりの月の名前も出して、押すとどの月へ行くかわかるようにする（画面設計書 5.10。2026-10-15 に本人と決定）。
                     前は予定のいちばん早い最初の月まで、先は 12 か月先まで。行けない側は、同じ幅のすき間にして、まん中の月がずれないようにする --}}
                <nav class="d-flex justify-content-between align-items-center gap-2 mb-2" aria-label="月の切りかえ">
                    @if ($hasPrevious)
                        <a href="{{ route('recurrings.index', ['month' => $month->subMonth()->format('Y-m')]) }}"
                            class="btn btn-link text-decoration-none px-1" style="min-width: 5rem" aria-label="前の月（{{ $month->subMonth()->format('Y年n月') }}）">◀ {{ $month->subMonth()->format('n月') }}</a>
                    @else
                        <span style="min-width: 5rem" aria-hidden="true"></span>
                    @endif
                    <h3 class="h5 mb-0 text-nowrap">{{ $month->format('Y年n月') }}</h3>
                    @if ($hasNext)
                        <a href="{{ route('recurrings.index', ['month' => $month->addMonth()->format('Y-m')]) }}"
                            class="btn btn-link text-decoration-none px-1 text-end" style="min-width: 5rem" aria-label="次の月（{{ $month->addMonth()->format('Y年n月') }}）">{{ $month->addMonth()->format('n月') }} ▶</a>
                    @else
                        <span style="min-width: 5rem" aria-hidden="true"></span>
                    @endif
                </nav>
                <livewire:monthly-amounts :month="$month" :key="$month->format('Y-m')" />
            </section>
        @endif

        <h2 class="h6 text-secondary">登録してある定期的な記録</h2>

        @if ($running->isEmpty() && $stopped->isEmpty())
            {{-- まだないときは、空白にせず次にすることを出す（4 章の 6） --}}
            <p>まだ定期的な記録がありません。給料・家賃・引き落としなど、毎月あるものを登録しましょう</p>
        @endif

        @if ($running->isNotEmpty())
            <div class="list-group mb-3">
                @foreach ($running as $recurring)
                    @include('recurrings._row')
                @endforeach
            </div>
        @endif

        <a href="{{ route('recurrings.create') }}" class="btn btn-primary mb-4">＋ 定期的な記録を登録する</a>

        @if ($stopped->isNotEmpty())
            {{-- 終わったもの・「使わない」ものは、いちばん下にまとめて薄い字で出す。押すと変更の画面で「使う」に戻したり、最後の月を延ばしたりできる --}}
            <section>
                <h2 class="h6 text-secondary">終わったもの・使わないもの</h2>
                <div class="list-group">
                    @foreach ($stopped as $recurring)
                        @include('recurrings._row')
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
