@extends('layouts.app')

@section('title', '相手を追加')

@section('content')
    <a href="{{ route('partners.index') }}" class="d-inline-block mb-2">‹ 相手の管理</a>
    <h1 class="h4 mb-3">相手を追加</h1>

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('partners.store') }}" novalidate>
                @csrf
                @include('partners._form')
                <button type="submit" class="btn btn-primary mt-2">保存</button>
            </form>
        </div>
    </div>
@endsection
