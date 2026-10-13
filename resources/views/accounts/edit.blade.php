@extends('layouts.app')

@section('title', '口座を直す')

@section('content')
    <a href="{{ route('accounts.index') }}" class="d-inline-block mb-2">‹ 口座の管理</a>
    <h1 class="h4 mb-3">口座を直す</h1>

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('accounts.update', $account) }}" novalidate>
                @csrf
                @method('PUT')
                @include('accounts._form')
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>
@endsection
