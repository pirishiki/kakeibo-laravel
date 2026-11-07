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
                {{-- 保存が 2 回届いても 1 つだけ作るための「送信用の合言葉」。画面を開くたびに変わる（口座の追加と同じ。app/Http/Middleware/PreventsDoubleAdd.php） --}}
                <input type="hidden" name="submit_token" value="{{ old('submit_token') ?: Str::uuid() }}">
                @include('goals._form')
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>
@endsection
