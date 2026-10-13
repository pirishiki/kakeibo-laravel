{{-- 分類の一覧の 1 行：つまみ・名前・›。小分類は 1 段下げる。「使わない」はその場で薄い字にする（下へまとめない） --}}
<div id="category-{{ $category->id }}"
    @class(['list-group-item d-flex align-items-center gap-2', 'ps-5' => ! $category->isParent(), 'text-secondary' => $muted])>
    {{-- つまみ：ここを押さえたまま上下に動かすと並べかえられる（同じ段の中だけ。resources/js/app.js） --}}
    <button type="button" class="sort-handle" title="押さえたまま上下に動かす" aria-label="{{ $category->name }}をつかんで動かす">
        <x-grip-icon />
    </button>
    <a href="{{ route('categories.edit', $category) }}" class="flex-grow-1 text-reset text-decoration-none text-break">
        {{ $category->name }}
        @unless ($category->is_active)
            <span class="small ms-1">（使わない）</span>
        @endunless
    </a>
    <a href="{{ route('categories.edit', $category) }}" class="text-secondary text-decoration-none px-1" aria-hidden="true" tabindex="-1">›</a>
</div>
