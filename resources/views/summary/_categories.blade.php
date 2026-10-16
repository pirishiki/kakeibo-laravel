@use('App\Support\RecordSearch')

{{-- 月のまとめの分類の表（支出・収入で同じ形）。$rows は MonthlySummary::categoryTotals()、$prefix は開く場所の id の頭、
     $budgets は分類の id → その月の予算（支出だけ。予算のある行の下に予算の状況を出す。第 3 段階）
     小分類がある大分類は、名前の横に「小分類 ○」の札と右に矢印を出し、押すとその下に小分類と「小分類なし」が開く
     （もう一度押すと閉じる。Bootstrap の collapse）。札で、押すと何が出るかと、開けない行とのちがいがわかるようにする
     小分類・「小分類なし」・小分類を使っていない大分類の行は、押すと、その月・その分類で探した記録の一覧を開く（右の「›」。画面設計書 5.5） --}}
@if ($rows->isEmpty())
    <p class="card-body text-secondary small mb-0">{{ $empty }}</p>
@else
    <div class="list-group list-group-flush">
        @foreach ($rows as $row)
            @php($target = $prefix.'-'.$row['category']->id)
            @php($subCount = $row['children']->filter(fn ($child) => $child['category'] !== null)->count())
            @if ($subCount > 0)
                <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                    data-bs-toggle="collapse" data-bs-target="#{{ $target }}" aria-expanded="false" aria-controls="{{ $target }}">
                    <span class="flex-grow-1 d-flex align-items-center gap-2 min-w-0">
                        <span class="text-truncate">{{ $row['category']->name }}</span>
                        <span class="badge rounded-pill text-primary bg-primary-subtle fw-normal">小分類 {{ $subCount }}</span>
                    </span>
                    <x-money :amount="$row['amount']" class="text-nowrap" />
                    <x-chevron class="text-primary" />
                </button>
                @isset($budgets[$row['category']->id])
                    @include('summary._budget', ['spent' => $row['amount'], 'budget' => $budgets[$row['category']->id], 'child' => false])
                @endisset
                <div class="collapse" id="{{ $target }}">
                    @foreach ($row['children'] as $child)
                        <a href="{{ route('records.index', RecordSearch::monthParams($month, [
                            'category' => $row['category']->id,
                            'sub' => $child['category']?->id ?? RecordSearch::NO_SUB,
                        ])) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2 ps-4 small">
                            @if ($child['category'] === null)
                                <span class="flex-grow-1 text-secondary">小分類なし</span>
                            @else
                                <span class="flex-grow-1 text-truncate">{{ $child['category']->name }}</span>
                            @endif
                            <x-money :amount="$child['amount']" class="text-nowrap" />
                            <span class="visually-hidden">の記録を見る</span>
                            <span class="summary-caret-space text-center text-secondary" aria-hidden="true">›</span>
                        </a>
                        @if ($child['category'] !== null && isset($budgets[$child['category']->id]))
                            @include('summary._budget', ['spent' => $child['amount'], 'budget' => $budgets[$child['category']->id], 'child' => true])
                        @endif
                    @endforeach
                </div>
            @else
                {{-- 小分類を使っていない大分類は、開くものがないので、押すとその大分類の記録の一覧を開く --}}
                <a href="{{ route('records.index', RecordSearch::monthParams($month, ['category' => $row['category']->id])) }}"
                    class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <span class="flex-grow-1 text-truncate">{{ $row['category']->name }}</span>
                    <x-money :amount="$row['amount']" class="text-nowrap" />
                    <span class="visually-hidden">の記録を見る</span>
                    <span class="summary-caret-space text-center text-secondary" aria-hidden="true">›</span>
                </a>
                @isset($budgets[$row['category']->id])
                    @include('summary._budget', ['spent' => $row['amount'], 'budget' => $budgets[$row['category']->id], 'child' => false])
                @endisset
            @endif
        @endforeach
    </div>
@endif
