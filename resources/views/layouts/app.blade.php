@extends('layouts.base')

{{-- ログインしたあとの画面の外わく。上にアプリの名前とログアウト（画面設計書 3.1）。メニューはホームを作るときに足す --}}
@section('body')
    <header class="navbar bg-white border-bottom">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="{{ route('home') }}">{{ config('app.name') }}</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">ログアウト</button>
            </form>
        </div>
    </header>

    <main class="container py-4">
        @yield('content')
    </main>
@endsection
