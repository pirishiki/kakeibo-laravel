{{-- 口座の行の下に小さく出す取っておく額（画面設計書 5.2・5.6）。「なし」とクレジットカードのときは何も出さない --}}
@if ($account->type !== \App\Enums\AccountType::CreditCard && $account->reserve_type !== \App\Enums\ReserveType::None)
    <span class="d-block small text-secondary">
        @if ($account->reserve_type === \App\Enums\ReserveType::All)
            全部取っておく
        @else
            取っておく額 {{ number_format((int) $account->reserve_amount) }}
        @endif
    </span>
@endif
