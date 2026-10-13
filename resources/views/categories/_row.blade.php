{{-- 分類の一覧の 1 行：名前・↑ ↓・›。小分類は 1 段下げる。「使わない」はその場で薄い字にする（下へまとめない） --}}
<div id="category-{{ $category->id }}"
    @class(['list-group-item d-flex align-items-center gap-2', 'ps-5' => ! $category->isParent(), 'text-secondary' => $muted])>
    <a href="{{ route('categories.edit', $category) }}" class="flex-grow-1 text-reset text-decoration-none text-break">
        {{ $category->name }}
        @unless ($category->is_active)
            <span class="small ms-1">（使わない）</span>
        @endunless
    </a>

    {{-- ↑ ↓：同じ段の中だけで 1 つ動かす。いちばん上の ↑・いちばん下の ↓ は押せない --}}
    @foreach (['up' => ['↑', '上へ', $isFirst], 'down' => ['↓', '下へ', $isLast]] as $direction => [$mark, $label, $disabled])
        <form method="POST" action="{{ route('categories.move', $category) }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="direction" value="{{ $direction }}">
            <button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="{{ $category->name }}を{{ $label }}"
                @disabled($disabled)>{{ $mark }}</button>
        </form>
    @endforeach

    <a href="{{ route('categories.edit', $category) }}" class="text-secondary text-decoration-none px-1" aria-hidden="true" tabindex="-1">›</a>
</div>
