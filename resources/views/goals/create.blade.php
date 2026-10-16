@extends('layouts.app')

@section('title', '目標を追加')

@section('content')
    <a href="{{ route('goals.index') }}" class="d-inline-block mb-2">‹ 貯金の目標</a>
    <h1 class="h4 mb-3">目標を追加</h1>

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            {{-- novalidate：ブラウザの吹き出しではなく、アプリのまちがいの文を欄の下に出す（4 章の 2） --}}
            <form method="POST" action="{{ route('goals.store') }}" novalidate>
                @csrf
                @include('goals._form')
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>
@endsection
