@extends('layouts.app')

@section('title', '定期的な記録')

{{-- 定期的な記録（画面設計書 5.10）。上の「月ごとの金額」は次のかたまりで足し、いまは下の「登録してある定期的な記録」だけ --}}
@section('content')
    <h1 class="h4 mb-3">定期的な記録</h1>

    <div style="max-width: 560px">
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
