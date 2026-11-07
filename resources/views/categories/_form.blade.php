{{-- 分類の追加・変更の入力欄（画面設計書 5.8）。大分類と小分類で出す欄がちがう --}}
@php($recordCount ??= 0)

<div class="mb-3">
    <label for="name" class="form-label">名前</label>
    <input type="text" id="name" name="name" value="{{ old('name', $category->name) }}"
        class="form-control @error('name') is-invalid @enderror" maxlength="50" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@if ($category->isParent())
    {{-- 収入用・支出用：大分類の追加のときだけ選べる。あとから変えられない（記録の収入・支出とずれないようにするため） --}}
    <div class="mb-4">
        @if ($category->exists)
            <span class="form-label d-block">収入用・支出用</span>
            <p class="mb-0">{{ $category->kind->label() }}用 <span class="form-text">（あとから変えられません）</span></p>
            {{-- 保存では使わない。ほかの画面でこの分類が削除されていたとき、打った内容を同じ用の追加の画面へ持っていくため（app/Support/DeletedElsewhere.php） --}}
            <input type="hidden" name="kind" value="{{ $category->kind->value }}">
        @else
            <span class="form-label d-block">収入用・支出用</span>
            @foreach (\App\Enums\CategoryKind::cases() as $kind)
                <div class="form-check form-check-inline">
                    <input type="radio" id="kind-{{ $kind->value }}" name="kind" value="{{ $kind->value }}"
                        class="form-check-input @error('kind') is-invalid @enderror"
                        @checked(old('kind', $category->kind->value) === $kind->value)>
                    <label for="kind-{{ $kind->value }}" class="form-check-label">{{ $kind->label() }}用</label>
                </div>
            @endforeach
            <div class="form-text">あとから変えられません</div>
            @error('kind')
                <div class="text-danger small">{{ $message }}</div>
            @enderror
        @endif
    </div>
@else
    {{-- 親の大分類：小分類だけ。同じ用の大分類から選ぶ。選び直すと、この小分類の記録もいっしょに移る --}}
    <div class="mb-4">
        <label for="parent_id" class="form-label">親の大分類</label>
        {{-- 保存できずに戻ったとき、選んでいた親が選ぶ欄にない（「使わない」だった・削除された）なら、いまの親を選んでおく
             （どれも選ばれていないと、ブラウザが最初の大分類を黙って選び、次の保存でそこへ移ってしまうため。画面設計書 5.8） --}}
        {{-- 1 行の形で書く（このファイルの先頭も 1 行の形。かたまりの形とまぜると、Blade が読みちがえてエラーの画面になるため） --}}
        @php($oldParent = (int) old('parent_id', $category->parent_id))
        @php($selectedParent = $parentOptions->contains('id', $oldParent) ? $oldParent : $category->parent_id)
        <select id="parent_id" name="parent_id" class="form-select @error('parent_id') is-invalid @enderror" required>
            @foreach ($parentOptions as $option)
                <option value="{{ $option->id }}" @selected($selectedParent === $option->id)>
                    {{ $option->name }}@unless ($option->is_active)（使わない）@endunless
                </option>
            @endforeach
        </select>
        @error('parent_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        @if ($inactiveParent ?? null)
            {{-- 決まり（「使わない」大分類の下に「使う」小分類は置けない。画面設計書 5.8）ができる前からある形。勝手に変えず、知らせる --}}
            <div class="form-text text-danger">
                大分類が『使わない』です。この小分類は、記録の登録で選べません。{{ $category->is_active ? '使うなら' : '『使う』に戻すときは' }}、先に大分類を『使う』にするか、別の大分類へ移してください
            </div>
        @endif
        @if ($category->exists && $recordCount > 0)
            {{-- 親を選び直したときだけ出す（resources/js/app.js） --}}
            <div class="form-text text-primary" data-show-when-changed="parent_id" data-original="{{ $category->parent_id }}" hidden>
                この小分類の記録 {{ number_format($recordCount) }} 件もいっしょに移ります
            </div>
            {{-- 移す元か移す先の大分類に予算があるときは、過去の月の予算の状況も数え直されることを知らせる（選んだ先ごとに文を用意しておく。第 3 段階） --}}
            @foreach ($parentOptions as $option)
                @php($budgetNames = $option->id === $category->parent_id ? [] : $parentOptions
                    ->filter(fn ($p) => in_array($p->id, [$category->parent_id, $option->id], true) && in_array($p->id, $budgetedParentIds ?? [], true))
                    ->pluck('name')->all())
                @if ($budgetNames !== [])
                    <div class="form-text text-primary" data-show-when-field="parent_id" data-show-when-value="{{ $option->id }}" hidden>
                        過去の月のまとめも新しい分け方で出るので、{{ implode('・', $budgetNames) }} の過去の月の予算の状況（こえた・こえていない）も変わります
                    </div>
                @endif
            @endforeach
        @endif
    </div>
@endif

@if ($category->exists)
    {{-- 「使う」は変更の画面にだけ出す（口座の管理と同じ） --}}
    <div class="form-check mb-4">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1"
            class="form-check-input @error('is_active') is-invalid @enderror"
            @checked(old('is_active', $category->is_active))>
        <label for="is_active" class="form-check-label">使う</label>
        @if ($category->isParent())
            <div class="form-text">この下に「使う」小分類があるうちは、外せません（「使わない」大分類の下の小分類は、記録で選べないため）</div>
        @endif
        {{-- 理由が 2 つ（動いている定期的な記録・「使う」小分類）のときは、両方出す（どちらも直してもらうため） --}}
        @foreach ($errors->get('is_active') as $message)
            <div class="invalid-feedback">{{ $message }}
                @if ($message === \App\Models\Recurring::IN_USE_MESSAGE)
                    @include('recurrings._used-by', ['recurrings' => $runningUsedBy])
                @endif
            </div>
        @endforeach
    </div>
@endif
