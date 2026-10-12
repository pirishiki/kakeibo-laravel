<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') - @endif{{ config('app.name') }}</title>

    {{-- 字は Noto Sans JP（画面設計書 6.4）。太さはふつうと太いの 2 つだけ。読みこみ中は機械の字で先に出す（display=swap） --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700&display=swap" rel="stylesheet">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
    {{-- すべての画面の外わく。ログイン画面はこれをそのまま使い、ログインしたあとの画面は layouts/app を使う --}}
    @yield('body')
</body>
</html>
