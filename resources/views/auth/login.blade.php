@extends('layouts.base')

@section('title', 'ログイン')

{{-- ログイン（画面設計書 5.1）。メニューは出さない。登録・パスワードリセットのリンクも出さない --}}
@section('body')
    <main class="container py-5">
        <div class="card mx-auto shadow-sm" style="max-width: 400px">
            <div class="card-body p-4">
                <h1 class="h4 text-center fw-bold text-primary mb-4">{{ config('app.name') }}</h1>

                {{-- novalidate：ブラウザの吹き出しではなく、アプリのまちがいの文を欄の下に出す（4 章の 2） --}}
                <form method="POST" action="{{ route('login') }}" novalidate>
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label">メールアドレス</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}"
                            class="form-control @error('email') is-invalid @enderror"
                            autocomplete="username" autofocus required>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">パスワード</label>
                        <input type="password" id="password" name="password"
                            class="form-control @error('password') is-invalid @enderror"
                            autocomplete="current-password" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- 最初はチェックあり。まちがえて戻ってきたときは、前に選んだとおりにする --}}
                    <div class="form-check mb-4">
                        <input type="checkbox" id="remember" name="remember" value="1" class="form-check-input"
                            @checked($errors->any() ? old('remember') : true)>
                        <label for="remember" class="form-check-label">ログインしたままにする</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">ログイン</button>
                </form>
            </div>
        </div>
    </main>
@endsection
