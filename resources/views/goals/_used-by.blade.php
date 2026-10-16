{{-- 口座を使っている貯金の目標へのリンク（画面設計書 4 章の 9）。$goal に目標を渡す --}}
<ul class="mb-0 mt-2">
    <li><a href="{{ route('goals.edit', $goal) }}">{{ $goal->name }}</a>（貯金の目標）</li>
</ul>
