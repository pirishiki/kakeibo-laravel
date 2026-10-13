{{-- 相手の一覧の 1 行：名前と、最後に使った日。押すと変更の画面へ --}}
<a href="{{ route('partners.edit', $partner) }}" data-name="{{ $partner->name }}"
    @class(['list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3', 'text-secondary' => ! $partner->is_active])>
    <span class="text-break">{{ $partner->name }}</span>
    <span class="text-nowrap small text-secondary">
        @if ($partner->lastUsedOn())
            <x-date :date="$partner->lastUsedOn()" />
        @endif
        <span class="ms-1" aria-hidden="true">›</span>
    </span>
</a>
