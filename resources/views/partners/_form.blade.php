{{-- 相手の追加・変更の入力欄（画面設計書 5.9） --}}
<div class="mb-3">
    <label for="name" class="form-label">名前</label>
    <input type="text" id="name" name="name" value="{{ old('name', $partner->name) }}"
        class="form-control @error('name') is-invalid @enderror" maxlength="50" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@if ($partner->exists)
    {{-- 「使う」は変更の画面にだけ出す（口座の管理と同じ） --}}
    <div class="form-check mb-4">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1"
            class="form-check-input @error('is_active') is-invalid @enderror"
            @checked(old('is_active', $partner->is_active))>
        <label for="is_active" class="form-check-label">使う</label>
        @error('is_active')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
@endif
