{{-- 月のまとめの分類の表（支出・収入で同じ形）。$rows は MonthlySummary::categoryTotals()、$prefix は開く場所の id の頭
     小分類がある大分類は、押すとその下に小分類と「小分類なし」が開く（もう一度押すと閉じる。Bootstrap の collapse） --}}
@if ($rows->isEmpty())
    <p class="card-body text-secondary small mb-0">{{ $empty }}</p>
@else
    <div class="list-group list-group-flush">
        @foreach ($rows as $row)
            @php($target = $prefix.'-'.$row['category']->id)
            @if ($row['children']->contains(fn ($child) => $child['category'] !== null))
                <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                    data-bs-toggle="collapse" data-bs-target="#{{ $target }}" aria-expanded="false" aria-controls="{{ $target }}">
                    <span class="flex-grow-1 text-truncate">{{ $row['category']->name }}</span>
                    <x-money :amount="$row['amount']" class="text-nowrap" />
                    <span class="summary-caret text-secondary" aria-hidden="true">▸</span>
                </button>
                <div class="collapse" id="{{ $target }}">
                    @foreach ($row['children'] as $child)
                        <div class="list-group-item d-flex align-items-center gap-2 ps-4 small">
                            @if ($child['category'] === null)
                                <span class="flex-grow-1 text-secondary">小分類なし</span>
                            @else
                                <span class="flex-grow-1 text-truncate">{{ $child['category']->name }}</span>
                            @endif
                            <x-money :amount="$child['amount']" class="text-nowrap" />
                            <span class="summary-caret-space" aria-hidden="true"></span>
                        </div>
                    @endforeach
                </div>
            @else
                {{-- 小分類を使っていない大分類は、開くものがないので押せない行にする --}}
                <div class="list-group-item d-flex align-items-center gap-2">
                    <span class="flex-grow-1 text-truncate">{{ $row['category']->name }}</span>
                    <x-money :amount="$row['amount']" class="text-nowrap" />
                    <span class="summary-caret-space" aria-hidden="true"></span>
                </div>
            @endif
        @endforeach
    </div>
@endif
