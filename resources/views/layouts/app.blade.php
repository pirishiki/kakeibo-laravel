@extends('layouts.base')

{{-- ログインしたあとの画面の外わく。上にアプリの名前とログアウト（画面設計書 3.1）。メニューはホームを作るときに足す --}}
@section('body')
    <header class="navbar bg-white border-bottom">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="{{ route('home') }}">{{ config('app.name') }}</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                {{-- 青緑の枠・丸い形・扉から出る印（Bootstrap Icons の box-arrow-right と同じ形） --}}
                <button type="submit" class="btn btn-sm btn-outline-primary btn-logout rounded-pill d-inline-flex align-items-center gap-1 px-3">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z"/>
                        <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/>
                    </svg>
                    ログアウト
                </button>
            </form>
        </div>
    </header>

    <main class="container py-4">
        @yield('content')
    </main>
@endsection
