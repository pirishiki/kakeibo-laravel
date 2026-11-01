@extends('layouts.app')

@section('title', $category->isParent() ? '大分類を追加' : '小分類を追加')

@section('content')
    <a href="{{ route('categories.index') }}" class="d-inline-block mb-2">‹ 分類の管理</a>
    <h1 class="h4 mb-3">{{ $category->isParent() ? '大分類を追加' : '小分類を追加' }}</h1>

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('categories.store') }}" novalidate>
                @csrf
                {{-- 保存が 2 回届いても 1 件だけ作るための「送信用の合言葉」。画面を開くたびに変わる（app/Http/Middleware/PreventsDoubleAdd.php） --}}
                <input type="hidden" name="submit_token" value="{{ old('submit_token') ?: Str::uuid() }}">
                @include('categories._form')
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>
@endsection
