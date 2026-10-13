@extends('layouts.app')

@section('title', '相手の管理')

{{-- 相手の管理の一覧（画面設計書 5.9）。最近使った順 → まだ使っていない相手 → 使わない相手 --}}
@section('content')
    <h1 class="h4 mb-3">相手の管理</h1>

    {{-- 名前で探す：打った文字をふくむ相手だけを出す（画面の中だけでしぼる。resources/js/app.js） --}}
    <div class="mb-3" style="max-width: 560px">
        <label for="partner-search" class="visually-hidden">名前で探す</label>
        <input type="search" id="partner-search" class="form-control" placeholder="名前で探す"
            data-filter="#partner-lists" autocomplete="off">
    </div>

    <div id="partner-lists" style="max-width: 560px">
        @if ($used->isEmpty() && $unused->isEmpty() && $inactive->isEmpty())
            {{-- 相手がまだないときは、空白にせず次にすることを出す（4 章の 6） --}}
            <p>まだ相手がありません。よく行くお店などを追加しましょう</p>
        @endif

        @if ($used->isNotEmpty())
            <div class="list-group mb-3" data-filter-group>
                @foreach ($used as $partner)
                    @include('partners._row')
                @endforeach
            </div>
        @endif

        @if ($unused->isNotEmpty())
            <section data-filter-group>
                <h2 class="h6 text-secondary">まだ使っていない相手</h2>
                <div class="list-group mb-3">
                    @foreach ($unused as $partner)
                        @include('partners._row')
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-secondary" data-filter-empty role="status" hidden>打った文字をふくむ相手はありません</p>

        <a href="{{ route('partners.create') }}" class="btn btn-primary mb-4">＋ 相手を追加</a>

        @if ($inactive->isNotEmpty())
            {{-- 「使わない」相手は、いちばん下にまとめて薄い字で出す（口座の管理と同じ） --}}
            <section data-filter-group>
                <h2 class="h6 text-secondary">使わない相手</h2>
                <div class="list-group">
                    @foreach ($inactive as $partner)
                        @include('partners._row')
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
