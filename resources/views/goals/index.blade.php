@extends('layouts.app')

@section('title', '貯金の目標')

{{-- 貯金の目標の一覧（画面設計書 5.13）。期限の近い順、期限なしはその下に追加した順。達成したものは下にまとめて薄い字 --}}
@section('content')
    <h1 class="h4 mb-3">貯金の目標</h1>

    @if ($active->isEmpty() && $achieved->isEmpty())
        {{-- 目標がまだないときは、空白にせず次にすることを出す（4 章の 6） --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-3">まだ目標がありません。貯金用の口座を選んで、目標を追加しましょう</p>
                <a href="{{ route('goals.create') }}" class="btn btn-primary">＋ 目標を追加</a>
            </div>
        </div>
    @else
        @if ($active->isNotEmpty())
            <div class="list-group mb-3">
                @foreach ($active as $row)
                    @include('goals._row')
                @endforeach
            </div>
        @endif

        <a href="{{ route('goals.create') }}" class="btn btn-primary mb-4">＋ 目標を追加</a>

        @if ($achieved->isNotEmpty())
            <h2 class="h6 text-secondary">達成した目標</h2>
            <div class="list-group">
                @foreach ($achieved as $row)
                    @include('goals._row')
                @endforeach
            </div>
        @endif
    @endif
@endsection
