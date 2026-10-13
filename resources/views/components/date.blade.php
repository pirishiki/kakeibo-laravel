@props(['date'])

{{-- 日付：「10月9日（金）」の形。今年でないときだけ年をつける（画面設計書 4 章の 5） --}}
<span {{ $attributes }}>{{ $date->locale('ja')->isoFormat($date->isCurrentYear() ? 'M月D日（ddd）' : 'YYYY年M月D日（ddd）') }}</span>
