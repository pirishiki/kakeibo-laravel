{{-- 分類の追加・変更の入力欄（画面設計書 5.8）。大分類と小分類で出す欄がちがう --}}
@php($recordCount ??= 0)

<div class="mb-3">
    <label for="name" class="form-label">名前</label>
    <input type="text" id="name" name="name" value="{{ old('name', $category->name) }}"
        class="form-control @error('name') is-invalid @enderror" maxlength="50" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@if ($category->isParent())
    {{-- 収入用・支出用：大分類の追加のときだけ選べる。あとから変えられない（記録の収入・支出とずれないようにするため） --}}
    <div class="mb-4">
        @if ($category->exists)
            <span class="form-label d-block">収入用・支出用</span>
            <p class="mb-0">{{ $category->kind->label() }}用 <span class="form-text">（あとから変えられません）</span></p>
        @else
            <span class="form-label d-block">収入用・支出用</span>
            @foreach (\App\Enums\CategoryKind::cases() as $kind)
                <div class="form-check form-check-inline">
                    <input type="radio" id="kind-{{ $kind->value }}" name="kind" value="{{ $kind->value }}"
                        class="form-check-input @error('kind') is-invalid @enderror"
                        @checked(old('kind', $category->kind->value) === $kind->value)>
                    <label for="kind-{{ $kind->value }}" class="form-check-label">{{ $kind->label() }}用</label>
                </div>
            @endforeach
            <div class="form-text">あとから変えられません</div>
            @error('kind')
                <div class="text-danger small">{{ $message }}</div>
            @enderror
        @endif
    </div>
@else
    {{-- 親の大分類：小分類だけ。同じ用の大分類から選ぶ。選び直すと、この小分類の記録もいっしょに移る --}}
    <div class="mb-4">
        <label for="parent_id" class="form-label">親の大分類</label>
        <select id="parent_id" name="parent_id" class="form-select @error('parent_id') is-invalid @enderror" required>
            @foreach ($parentOptions as $option)
                <option value="{{ $option->id }}" @selected((int) old('parent_id', $category->parent_id) === $option->id)>
                    {{ $option->name }}@unless ($option->is_active)（使わない）@endunless
                </option>
            @endforeach
        </select>
        @error('parent_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        @if ($category->exists && $recordCount > 0)
            {{-- 親を選び直したときだけ出す（resources/js/app.js） --}}
            <div class="form-text text-primary" data-show-when-changed="parent_id" data-original="{{ $category->parent_id }}" hidden>
                この小分類の記録 {{ number_format($recordCount) }} 件もいっしょに移ります
            </div>
        @endif
    </div>
@endif

@if ($category->exists)
    {{-- 「使う」は変更の画面にだけ出す（口座の管理と同じ） --}}
    <div class="form-check mb-4">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1"
            class="form-check-input @error('is_active') is-invalid @enderror"
            @checked(old('is_active', $category->is_active))>
        <label for="is_active" class="form-check-label">使う</label>
        @if ($category->isParent())
            <div class="form-text">外すと、この下の小分類も記録で選べなくなります</div>
        @endif
        @error('is_active')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
@endif
