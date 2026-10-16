{{-- 予算を決める画面の 1 行：分類の名前と金額の欄。小分類は 1 段下げる。
     originals には開いたときの金額を入れておき、保存のときに、開いたあとにほかの画面で変わっていないかを確かめる（app/Support/Budgets.php）。
     特別な月では、分類の横にふだんの予算を小さく出し（data-usual）、空の欄は「ふだんのまま」 --}}
@php
    $id = $category->id;
    $saved = $values[$id] ?? null;
    $usualAmount = $usual[$id] ?? null;
@endphp
<div @class(['list-group-item d-flex align-items-center gap-2', 'ps-4' => $child])
    data-budget-row="{{ $child ? 'child' : 'parent' }}" @if ($special !== null && $usualAmount !== null) data-usual="{{ $usualAmount }}" @endif>
    <label for="budget-{{ $id }}" @class(['flex-grow-1 min-w-0 text-break', 'small' => $child])>
        {{ $category->name }}
        @if ($special !== null)
            <span class="d-block small text-secondary">ふだん {{ $usualAmount === null ? 'なし' : number_format($usualAmount) }}</span>
        @endif
    </label>
    <input type="hidden" name="originals[{{ $id }}]" value="{{ $useOld ? old('originals.'.$id, $saved === null ? '' : (string) $saved) : ($saved === null ? '' : (string) $saved) }}">
    <div class="input-group has-validation flex-shrink-0" style="width: 11rem">
        <input type="text" id="budget-{{ $id }}" name="amounts[{{ $id }}]" inputmode="numeric" autocomplete="off" data-amount
            value="{{ $useOld ? old('amounts.'.$id, $saved === null ? '' : number_format($saved)) : ($saved === null ? '' : number_format($saved)) }}"
            placeholder="{{ $special === null ? 'なし' : 'ふだんのまま' }}"
            class="form-control text-end @error('amounts.'.$id) is-invalid @enderror"
            @error('amounts.'.$id) aria-describedby="budget-{{ $id }}-error" @enderror>
        <span class="input-group-text">円</span>
        @error('amounts.'.$id)
            <div id="budget-{{ $id }}-error" class="invalid-feedback text-start">{{ $message }}</div>
        @enderror
    </div>
</div>
