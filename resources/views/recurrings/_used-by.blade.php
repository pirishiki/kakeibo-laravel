{{-- 口座・分類・相手を使っている定期的な記録の一覧と、そこへのリンク（画面設計書 4 章の 9）。$recurrings に予定の並びを渡す --}}
<ul class="mb-0 mt-2">
    @foreach ($recurrings as $recurring)
        <li><a href="{{ route('recurrings.edit', $recurring) }}">{{ $recurring->name }}</a>（{{ $recurring->scheduleLabel() }}）</li>
    @endforeach
</ul>
