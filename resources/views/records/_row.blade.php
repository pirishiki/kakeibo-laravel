@use('App\Enums\RecordType')

{{-- 記録の一覧の 1 行（画面設計書 5.3 の「行の出し方」）。
     1 行目は大きい字（相手・どこから → どこへ・「調整」）と金額、2 行目は小さい字（分類 · 口座 · 品物など）。
     「使わない」にした口座・分類・相手も、元の名前のまま出す --}}
@php
    $category = $record->category;
    $categoryName = $category === null ? null : ($category->parent ? $category->parent->name.' > '.$category->name : $category->name);

    [$title, $details] = match ($record->type) {
        RecordType::Expense => [$record->partner?->name, [$categoryName, $record->account->name, $record->item]],
        RecordType::Income => [$record->partner?->name, [$categoryName, $record->account->name]],
        RecordType::Transfer => [$record->account->name.' → '.$record->toAccount?->name, []],
        RecordType::Adjustment => ['調整', [$record->account->name]],
    };
    // 品物は入れたときだけ出す
    $details = array_filter($details, fn ($text) => $text !== null && $text !== '');
@endphp
<li @class(['list-group-item', 'text-secondary' => $record->type === RecordType::Transfer])>
    <div class="d-flex justify-content-between align-items-baseline gap-3">
        <span class="text-break">{{ $title }}</span>
        <span class="text-nowrap">
            @switch($record->type)
                @case(RecordType::Income)
                    {{-- 収入は緑の太い字で「+」をつける（色だけにたよらないため。画面設計書 6.3） --}}
                    <span class="fw-bold text-success">+<x-money :amount="$record->amount" /></span>
                    @break
                @case(RecordType::Adjustment)
                    {{-- 調整は増えたら「+」、減ったら「-」（マイナスは赤い字。4 章の 4） --}}
                    @if ($record->amount > 0)+@endif<x-money :amount="$record->amount" />
                    @break
                @default
                    <x-money :amount="$record->amount" />
            @endswitch
        </span>
    </div>
    @if ($details !== [])
        <div class="small text-secondary text-break">{{ implode(' · ', $details) }}</div>
    @endif
</li>
