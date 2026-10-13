@props(['amount'])

{{-- 金額：3 けたごとにカンマ、マイナスは「-」をつけて赤い字（画面設計書 4 章の 4） --}}
<span {{ $attributes->class(['text-danger' => $amount < 0]) }}>{{ number_format($amount) }}</span>
