@extends('layouts.app')

@section('title', '口座の管理')

{{-- 口座の管理の一覧（画面設計書 5.6） --}}
@section('content')
    <h1 class="h4 mb-3">口座の管理</h1>

    @if ($active->isEmpty() && $inactive->isEmpty())
        {{-- 口座がまだないときは、空白にせず次にすることを出す（4 章の 6） --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-3">まだ口座がありません。使っている口座を追加しましょう</p>
                <a href="{{ route('accounts.create') }}" class="btn btn-primary">＋ 口座を追加</a>
            </div>
        </div>
    @else
        @if ($active->isNotEmpty())
            <div class="list-group mb-3">
                @foreach ($active as $account)
                    @include('accounts._row')
                @endforeach
            </div>
        @endif

        <a href="{{ route('accounts.create') }}" class="btn btn-primary mb-4">＋ 口座を追加</a>

        @if ($inactive->isNotEmpty())
            {{-- 「使わない」口座は、いちばん下にまとめて薄い字で出す --}}
            <h2 class="h6 text-secondary">使わない口座</h2>
            <div class="list-group">
                @foreach ($inactive as $account)
                    @include('accounts._row', ['muted' => true])
                @endforeach
            </div>
        @endif
    @endif
@endsection
