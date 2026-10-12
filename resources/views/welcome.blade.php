<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>家計簿</title>

    {{-- 字は Noto Sans JP（画面設計書 6.4）。太さはふつうと太いの 2 つだけ。読みこみ中は機械の字で先に出す（display=swap） --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700&display=swap" rel="stylesheet">

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
    {{-- 開発環境ができたかを確かめるための仮の画面。ホーム（画面設計書 5.2）を作るときに置きかえる --}}
    <main class="container py-4">
        <h1 class="h3 mb-3">家計簿（準備中）</h1>
        <p class="text-secondary">Bootstrap と色の設定が効いているかを確かめる画面です。</p>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 card-title">ボタン</h2>
                <button type="button" class="btn btn-primary">保存（基本の青緑）</button>
                <button type="button" class="btn btn-outline-primary">キャンセル</button>
                <button type="button" class="btn btn-danger">削除（注意の赤）</button>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 card-title">金額</h2>
                <p class="mb-1">支出：<span class="text-body">1,280 円</span></p>
                <p class="mb-1">収入：<span class="text-success fw-bold">+250,000 円</span></p>
                <p class="mb-0">マイナス：<span class="text-danger">-3,400 円</span></p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 card-title">入力欄</h2>
                <label for="amount" class="form-label">金額（押すと枠が青緑に光る）</label>
                <input id="amount" type="text" class="form-control mb-2" inputmode="numeric">
                <div class="form-check">
                    <input id="remember" type="checkbox" class="form-check-input" checked>
                    <label for="remember" class="form-check-label">ログインしたままにする</label>
                </div>
            </div>
        </div>

        <div class="alert alert-primary alert-dismissible fade show" role="alert">
            保存しました（✕ で閉じれば、Bootstrap の JavaScript も動いています）
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>
        </div>
    </main>
</body>
</html>
