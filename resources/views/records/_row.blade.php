@use('App\Enums\RecordType')

{{-- 記録の一覧の 1 行（画面設計書 5.3 の「行の出し方」）。
     1 行目は大きい字（相手・どこから → どこへ・「調整」）と金額、2 行目は小さい字（分類 · 口座 · 品物など）。
     「使わない」にした口座・分類・相手も、元の名前のまま出す。
     押すと編集の画面へ（口座の管理の行と同じ形：押せる行と右の「›」） --}}
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
{{-- $back：直したあと・やめたあとに戻る画面（残高を合わせるの最近の記録から開くとき。その画面の戻る先も引き継ぐため） --}}
<a href="{{ route('records.edit', isset($back) ? ['record' => $record, 'back' => $back] : $record) }}"
    @class(['list-group-item list-group-item-action d-flex align-items-center gap-2', 'text-secondary' => $record->type === RecordType::Transfer])>
    <div class="flex-grow-1" style="min-width: 0">
        <div class="d-flex justify-content-between align-items-baseline gap-3">
            <span class="text-break">
                {{-- 日付の見出しがない場所（残高を合わせるの最近の記録）では、行の頭に日付を出す --}}
                @if ($showDate ?? false)
                    <x-date :date="$record->date" class="text-secondary me-1" />
                @endif
                {{ $title }}
            </span>
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
    </div>
    <span class="text-secondary" aria-hidden="true">›</span>
</a>
