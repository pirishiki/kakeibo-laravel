@extends('layouts.app')

@section('title', '目標を直す')

@section('content')
    <a href="{{ route('goals.index') }}" class="d-inline-block mb-2">‹ 貯金の目標</a>
    <h1 class="h4 mb-3">目標を直す</h1>

    {{-- 開いたあとに、ほかの画面で直されていたとき --}}
    @error('save')
        <div class="alert alert-warning" role="alert" style="max-width: 560px">{{ $message }}</div>
    @enderror

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('goals.update', $goal) }}" novalidate>
                @csrf
                @method('PUT')
                {{-- 開いたときの中身の印（古い画面の中身で上書きしないため。app/Models/Goal.php の fingerprint）。
                     入力のまちがい・時間切れ（419）・ログインし直しで戻ったときは、開いたときの印のまま（そのあいだに直されていたら、次の保存で気づけるように）。
                     ほかの画面で直されたと知らせたときだけ、今の中身の印にする（もう一度保存すると、打った内容で上書きできる） --}}
                <input type="hidden" name="opened_at" value="{{ $errors->has('save') ? $goal->fingerprint() : old('opened_at', $goal->fingerprint()) }}">
                @include('goals._form')
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">保存</button>
                    {{-- 削除は赤（画面設計書 6.3）。押すと、すぐには消さずに小窓を出す（4 章の 8） --}}
                    <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#delete-dialog">削除</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 削除の小窓。目標には記録がつかないので、いつでも削除できる。口座と残高はそのまま --}}
    <div class="modal fade" id="delete-dialog" tabindex="-1" aria-labelledby="delete-dialog-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    <p id="delete-dialog-title" class="mb-0">「{{ $goal->name }}」を削除しますか？ 口座と残高は、そのまま残ります。</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="{{ route('goals.destroy', $goal) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">削除する</button>
                    </form>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                </div>
            </div>
        </div>
    </div>
@endsection
