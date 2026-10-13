{{-- 口座の一覧の 1 行：名前と今の残高。押すと変更の画面へ --}}
<a href="{{ route('accounts.edit', $account) }}"
    @class(['list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3', 'text-secondary' => $muted ?? false])>
    <span class="text-break">{{ $account->name }}</span>
    <span class="text-nowrap">
        <x-money :amount="$balances[$account->id]" />
        <span class="text-secondary ms-1" aria-hidden="true">›</span>
    </span>
</a>
