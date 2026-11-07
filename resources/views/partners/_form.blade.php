{{-- 相手の追加・変更の入力欄（画面設計書 5.9） --}}
{{-- 相手「その他」は、名前を変えられず、「使わない」にもできない（画面設計書 5.9）。欄を、変えられない形にする --}}
@php($protected = $partner->exists && $partner->isProtected())
<div class="mb-3">
    <label for="name" class="form-label">名前</label>
    <input type="text" id="name" name="name" value="{{ $protected ? $partner->name : old('name', $partner->name) }}"
        class="form-control @error('name') is-invalid @enderror" maxlength="50" required @readonly($protected)>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@if ($partner->exists)
    {{-- 「使う」は変更の画面にだけ出す（口座の管理と同じ） --}}
    <div class="form-check mb-4">
        @if ($protected && $partner->is_active)
            {{-- 押せない欄は送られないので、「使う」のまま送る --}}
            <input type="hidden" name="is_active" value="1">
            <input type="checkbox" id="is_active" class="form-check-input" checked disabled>
        @else
            {{-- 「使わない」になっている古いデータの「その他」は、「使う」に戻せるように押せる形のままにする --}}
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" id="is_active" name="is_active" value="1"
                class="form-check-input @error('is_active') is-invalid @enderror"
                @checked(old('is_active', $partner->is_active))>
        @endif
        <label for="is_active" class="form-check-label">使う</label>
        @error('is_active')
            <div class="invalid-feedback">{{ $message }}
                @if ($message === \App\Models\Recurring::IN_USE_MESSAGE)
                    @include('recurrings._used-by', ['recurrings' => $runningUsedBy])
                @endif
            </div>
        @enderror
    </div>
@endif
