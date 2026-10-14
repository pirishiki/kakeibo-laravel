@extends('layouts.app')

@section('title', '相手を直す')

@section('content')
    <a href="{{ route('partners.index') }}" class="d-inline-block mb-2">‹ 相手の管理</a>
    <h1 class="h4 mb-3">相手を直す</h1>

    @error('delete')
        <div class="alert alert-danger" role="alert" style="max-width: 560px">{{ $message }}
            @if ($message === \App\Models\Recurring::DELETE_BLOCKED_MESSAGE)
                @include('recurrings._used-by', ['recurrings' => $usedBy])
            @endif
        </div>
    @enderror

    <div class="card mb-4" style="max-width: 560px">
        <div class="card-body">
            <form method="POST" action="{{ route('partners.update', $partner) }}" novalidate>
                @csrf
                @method('PUT')
                @include('partners._form')
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">保存</button>
                    <button type="button" class="btn btn-outline-danger ms-auto" data-bs-toggle="modal" data-bs-target="#delete-dialog">削除</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ほかの相手にまとめる（画面設計書 5.9）：打ちまちがいなどでできた似た相手を 1 つにする --}}
    <div class="card" style="max-width: 560px">
        <div class="card-body">
            <h2 class="h6">ほかの相手にまとめる</h2>
            @if ($mergeTargets->isEmpty())
                <p class="text-secondary mb-0">まとめ先にできる相手がありません（「使う」になっている、ほかの相手が必要です）</p>
            @else
            <form method="POST" action="{{ route('partners.merge', $partner) }}" id="merge-form" novalidate>
                @csrf
                <div class="mb-3">
                    <label for="merge_target" class="form-label">まとめ先</label>
                    {{-- 打つと候補が出る（自分と「使わない」相手は出ない） --}}
                    <input type="text" id="merge_target" name="merge_target" list="merge-targets" value="{{ old('merge_target') }}"
                        class="form-control @error('merge_target') is-invalid @enderror" autocomplete="off" required aria-describedby="merge_target-error">
                    <datalist id="merge-targets">
                        @foreach ($mergeTargets as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                    <div class="invalid-feedback" id="merge_target-error">
                        @error('merge_target'){{ $message }}@else まとめ先は、出てくる相手から選んでください。@enderror
                    </div>
                </div>
                {{-- 押すと、まとめ先が候補にあるかを確かめてから、確認の小窓を出す（resources/js/app.js） --}}
                <button type="button" class="btn btn-outline-primary" data-merge-open>まとめる</button>
            </form>
            @endif
        </div>
    </div>

    {{-- まとめるときの確認の小窓。まとめ先の名前は、開くときに入れる --}}
    <div class="modal fade" id="merge-dialog" tabindex="-1" aria-labelledby="merge-dialog-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    <p id="merge-dialog-title" class="mb-0">
                        {{ $partner->name }}の記録 {{ number_format($recordCount) }} 件@if ($usedBy->isNotEmpty())と定期的な記録 {{ $usedBy->count() }} 件@endifを<span data-merge-target-name></span>に移し、{{ $partner->name }}を削除します。元に戻せません
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="submit" form="merge-form" class="btn btn-primary" data-merge-confirm>まとめる</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 削除の小窓（口座の管理と同じ形）。記録がなくても、定期的な記録が使っている相手は削除できない。
         「使う」で終わっていない定期的な記録が使っているときは「使わない」にもできない（4 章の 9） --}}
    @php
        $canDelete = $recordCount === 0 && $usedBy->isEmpty();
        $reason = $recordCount > 0 ? '記録があるので削除できません。' : \App\Models\Recurring::DELETE_BLOCKED_MESSAGE.'。';
    @endphp
    <div class="modal fade" id="delete-dialog" tabindex="-1" aria-labelledby="delete-dialog-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    @if ($canDelete)
                        <p id="delete-dialog-title" class="mb-0">「{{ $partner->name }}」を削除しますか？</p>
                    @elseif (! $partner->is_active)
                        <p id="delete-dialog-title" class="mb-0">{{ $reason }}この相手は、もう「使わない」になっています。</p>
                    @elseif ($runningUsedBy->isNotEmpty())
                        <p id="delete-dialog-title" class="mb-0">{{ $reason }}{{ \App\Models\Recurring::IN_USE_MESSAGE }}</p>
                    @else
                        <p id="delete-dialog-title" class="mb-0">{{ $reason }}『使わない』にしますか？</p>
                    @endif
                    @if ($usedBy->isNotEmpty())
                        @include('recurrings._used-by', ['recurrings' => $usedBy])
                    @endif
                </div>
                <div class="modal-footer">
                    @if ($canDelete)
                        <form method="POST" action="{{ route('partners.destroy', $partner) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger">削除する</button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                    @elseif ($partner->is_active && $runningUsedBy->isEmpty())
                        <form method="POST" action="{{ route('partners.deactivate', $partner) }}">
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
