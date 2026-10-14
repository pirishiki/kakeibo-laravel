@extends('layouts.base')

{{-- ログインしたあとの画面の外わく。上にアプリの名前とログアウト、メニュー（画面設計書 3.1）。
     メニューは全部の画面に出すので、画面ごとには書かず、ここに 1 回だけ書く。ログイン画面は layouts/base だけを使うので、メニューは出ない --}}
@php
    // いま開いている画面に合うメニューの項目に、印をつける（定期的な記録・口座・分類・相手は、スマホでは「設定」の中にあるので「設定」に印）
    $menu = [
        'home' => request()->routeIs('home'),
        'records' => request()->routeIs('records.index', 'records.edit'),
        'summary' => request()->routeIs('summary.*'),
        'recurrings' => request()->routeIs('recurrings.*'),
        'accounts' => request()->routeIs('accounts.*'),
        'categories' => request()->routeIs('categories.*'),
        'partners' => request()->routeIs('partners.*'),
    ];
    $menu['settings'] = request()->routeIs('settings.*') || $menu['recurrings'] || $menu['accounts'] || $menu['categories'] || $menu['partners'];
@endphp

@section('body')
    <header class="navbar bg-white border-bottom">
        <div class="container-fluid px-3 px-lg-4">
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

    {{-- 保存・削除できたときのお知らせ。画面の上に数秒だけ出して消える（4 章の 3。消す動きは resources/js/app.js） --}}
    @if (session('status'))
        <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3">
            <div class="toast align-items-center text-bg-primary border-0" role="status" aria-live="polite" data-bs-delay="3000">
                <div class="d-flex">
                    <div class="toast-body">{{ session('status') }}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="閉じる"></button>
                </div>
            </div>
        </div>
    @endif

    <div class="d-lg-flex">
        {{-- パソコンのメニュー：画面の左。全部の画面をここに出すので、設定の画面を開かなくても行ける。
             予算・貯金の目標（第 3 段階）は、その段階で足す --}}
        <nav class="side-menu d-none d-lg-block" aria-label="メニュー">
            <div class="list-group list-group-flush">
                @foreach ([
                    ['home', 'home', 'ホーム'],
                    ['records', 'records.index', '記録の一覧'],
                    ['summary', 'summary.index', '月のまとめ'],
                    ['recurrings', 'recurrings.index', '定期的な記録'],
                ] as [$key, $route, $label])
                    <a href="{{ route($route) }}" @class(['list-group-item list-group-item-action', 'active' => $menu[$key]])
                        @if ($menu[$key]) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
            <div class="side-menu-heading">設定</div>
            <div class="list-group list-group-flush">
                @foreach ([
                    ['accounts', 'accounts.index', '口座'],
                    ['categories', 'categories.index', '分類'],
                    ['partners', 'partners.index', '相手'],
                ] as [$key, $route, $label])
                    <a href="{{ route($route) }}" @class(['list-group-item list-group-item-action', 'active' => $menu[$key]])
                        @if ($menu[$key]) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
        </nav>

        <main class="container py-4 app-main">
            @yield('content')
        </main>
    </div>

    {{-- パソコンの「＋ 記録する」：画面の右下に浮かべる。記録の登録・編集の画面では、保存のボタンに重ならないように出さない --}}
    @unless (request()->routeIs('records.create', 'records.edit', 'recurrings.create', 'recurrings.edit'))
        <a href="{{ route('records.create') }}" class="btn btn-primary rounded-pill shadow px-4 py-2 record-fab d-none d-lg-inline-block">＋ 記録する</a>
    @endunless

    {{-- スマホのメニュー：画面のいちばん下（親指で押しやすい）。まん中の「＋」で記録の登録へ --}}
    <nav class="bottom-menu d-lg-none" aria-label="メニュー">
        <a href="{{ route('home') }}" @class(['bottom-menu-item', 'active' => $menu['home']]) @if ($menu['home']) aria-current="page" @endif>ホーム</a>
        <a href="{{ route('records.index') }}" @class(['bottom-menu-item', 'active' => $menu['records']]) @if ($menu['records']) aria-current="page" @endif>記録の一覧</a>
        <a href="{{ route('records.create') }}" class="bottom-menu-plus" aria-label="記録する"><span aria-hidden="true">＋</span></a>
        <a href="{{ route('summary.index') }}" @class(['bottom-menu-item', 'active' => $menu['summary']]) @if ($menu['summary']) aria-current="page" @endif>月のまとめ</a>
        <a href="{{ route('settings.index') }}" @class(['bottom-menu-item', 'active' => $menu['settings']]) @if ($menu['settings']) aria-current="page" @endif>設定</a>
    </nav>
@endsection
