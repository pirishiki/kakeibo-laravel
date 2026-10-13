@extends('layouts.app')

@section('title', 'ホーム')

{{-- 仮のホーム。ログインできたかを確かめるための画面で、ホーム（画面設計書 5.2）を作るときに置きかえる --}}
@section('content')
    <h1 class="h4 mb-3">ホーム（準備中）</h1>
    <p>{{ auth()->user()->name }} さん、ログインしています。</p>
    {{-- メニューができるまでの仮の入り口 --}}
    <p><a href="{{ route('records.create') }}" class="btn btn-primary">＋ 記録する</a></p>
    <ul>
        <li><a href="{{ route('records.index') }}">記録の一覧</a></li>
        <li><a href="{{ route('accounts.index') }}">口座の管理</a></li>
        <li><a href="{{ route('categories.index') }}">分類の管理</a></li>
        <li><a href="{{ route('partners.index') }}">相手の管理</a></li>
    </ul>
@endsection
