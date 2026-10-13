@extends('layouts.app')

@section('title', '口座を直す')

@section('content')
    <a href="{{ route('accounts.index') }}" class="d-inline-block mb-2">‹ 口座の管理</a>
    <h1 class="h4 mb-3">口座を直す</h1>

    @error('delete')
        <div class="alert alert-danger" role="alert" style="max-width: 560px">{{ $message }}</div>
    @enderror

    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('accounts.update', $account) }}" novalidate>
                @csrf
                @method('PUT')
                @include('accounts._form')
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">保存</button>
                    {{-- 削除は赤（画面設計書 6.3）。押すと、すぐには消さずに小窓を出す（4 章の 8・9） --}}
                    <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#delete-dialog">削除</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 削除の小窓。記録があるかどうか、「使わない」にできるかどうかで、出す文とボタンを変える --}}
    <div class="modal fade" id="delete-dialog" tabindex="-1" aria-labelledby="delete-dialog-title" @if ($hasRecords && $blocker !== null) aria-describedby="delete-dialog-reason" @endif aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    @if (! $hasRecords)
                        <p id="delete-dialog-title" class="mb-0">「{{ $account->name }}」を削除しますか？</p>
                    @elseif (! $account->is_active)
                        <p id="delete-dialog-title" class="mb-0">記録があるので削除できません。この口座は、もう「使わない」になっています。</p>
                    @elseif ($blocker === null)
                        <p id="delete-dialog-title" class="mb-0">記録があるので削除できません。『使わない』にしますか？</p>
                    @else
                        <p id="delete-dialog-title">記録があるので削除できません。</p>
                        {{-- 残高の理由のときだけ「残高を合わせる」へのリンクを出す（先の日付の記録が理由のときは、合わせても解決しないため） --}}
                        <p id="delete-dialog-reason" class="mb-0">{{ $blocker }}@if (str_starts_with($blocker, '残高が'))（<a href="{{ route('accounts.adjust', $account) }}">残高を合わせる</a>）@endif</p>
                    @endif
                </div>
                <div class="modal-footer">
                    @if (! $hasRecords)
                        <form method="POST" action="{{ route('accounts.destroy', $account) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">削除する</button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                    @elseif ($account->is_active && $blocker === null)
                        <form method="POST" action="{{ route('accounts.deactivate', $account) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-primary">「使わない」にする</button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                    @else
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">閉じる</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
