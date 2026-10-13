{{-- 分類の一覧の 1 行：つまみ・名前・›。小分類は 1 段下げる。「使わない」は薄い字にして、その段のいちばん下に出す --}}
<div id="category-{{ $category->id }}"
    @class(['list-group-item d-flex align-items-center gap-2', 'ps-5' => ! $category->isParent(), 'text-secondary' => $muted])>
    @if ($muted)
        {{-- 「使わない」分類（と、「使わない」大分類の下の小分類）は、いつも下に出すので動かせない。名前の位置をそろえるため、つまみの幅だけ空ける --}}
        <span class="sort-handle-space" aria-hidden="true"></span>
    @else
        {{-- つまみ：ここを押さえたまま上下に動かすと並べかえられる（同じ段の中だけ。resources/js/app.js）。
             マウスと指だけで使う（キーボードでは並べかえない）ので、Tab で止まるボタンにはしない --}}
        <span class="sort-handle" id="handle-{{ $category->id }}" title="押さえたまま上下に動かす" aria-hidden="true">
            <x-grip-icon />
        </span>
    @endif
    <a href="{{ route('categories.edit', $category) }}" class="flex-grow-1 text-reset text-decoration-none text-break">
        {{ $category->name }}
        @unless ($category->is_active)
            <span class="small ms-1">（使わない）</span>
        @endunless
    </a>
    <a href="{{ route('categories.edit', $category) }}" class="text-secondary text-decoration-none px-1" aria-hidden="true" tabindex="-1">›</a>
</div>
