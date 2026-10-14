{{-- 設定の 1 行：押すとその画面へ。1 行目に名前、2 行目に何の画面かと件数（口座の管理の行と同じ、押せる行と右の「›」） --}}
<a href="{{ $href }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
    <span class="flex-grow-1 min-w-0">
        <span class="d-block">{{ $label }}</span>
        <span class="d-block small text-secondary text-truncate">{{ $detail }}</span>
    </span>
    <span class="text-secondary" aria-hidden="true">›</span>
</a>
