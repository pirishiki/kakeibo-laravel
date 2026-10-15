{{-- 口座の追加・変更の入力欄（画面設計書 5.6）。$hasRecords が true のときは、最初の残高を変えられなくする --}}
@php($hasRecords ??= false)

<div class="mb-3">
    <label for="name" class="form-label">名前</label>
    <input type="text" id="name" name="name" value="{{ old('name', $account->name) }}"
        class="form-control @error('name') is-invalid @enderror" maxlength="50" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="type" class="form-label">種類</label>
    <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" required>
        @foreach (\App\Enums\AccountType::cases() as $type)
            <option value="{{ $type->value }}" @selected(old('type', $account->type?->value) === $type->value)>{{ $type->label() }}</option>
        @endforeach
    </select>
    @error('type')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-4">
    <label for="initial_balance" class="form-label">最初の残高</label>
    <div class="input-group has-validation">
        {{-- 記録の金額の欄と同じ形：文字の欄で、欄を離れたらカンマをつける（data-amount。resources/js/app.js）。
             マイナスも入れられる（クレジットカードなど）ので、「-」のあるキーボードにする --}}
        <input type="text" id="initial_balance" name="initial_balance" inputmode="text" autocomplete="off" data-amount
            value="{{ $hasRecords ? number_format((int) $account->initial_balance) : old('initial_balance', number_format((int) $account->initial_balance)) }}"
            class="form-control text-end @error('initial_balance') is-invalid @enderror"
            aria-describedby="initial_balance-help"
            @disabled($hasRecords) required>
        <span class="input-group-text">円</span>
        @error('initial_balance')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    @if ($hasRecords)
        {{-- 「使わない」口座は残高を合わせられないので、リンクにしない --}}
        @if ($account->is_active)
            <div id="initial_balance-help" class="form-text">記録があるので変えられません。今の残高を直すときは<a href="{{ route('accounts.adjust', $account) }}">「残高を合わせる」</a>を使います</div>
        @else
            <div id="initial_balance-help" class="form-text">記録があるので変えられません。今の残高を直すときは「残高を合わせる」を使います</div>
        @endif
    @else
        <div id="initial_balance-help" class="form-text">使い始める日の金額。クレジットカードで、まだ払っていない分があるときはマイナスで入れます</div>
    @endif
</div>

@if ($account->exists)
    {{-- 「使う」は変更の画面にだけ出す。外すと「使わない」になる（残高が 0 円のときだけ） --}}
    <div class="form-check mb-4">
        {{-- チェックを外すと何も送られないので、先に 0 を送っておく（チェックがあれば 1 で上書きされる） --}}
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1"
            class="form-check-input @error('is_active') is-invalid @enderror"
            @checked(old('is_active', $account->is_active))>
        <label for="is_active" class="form-check-label">使う</label>
        @error('is_active')
            {{-- 定期的な記録が理由のときは、どれが使っているかとリンクを出す --}}
            <div class="invalid-feedback">{{ $message }}@if (str_starts_with($message, '残高が'))（<a href="{{ route('accounts.adjust', $account) }}">残高を合わせる</a>）@endif@if ($message === \App\Models\Recurring::IN_USE_MESSAGE)@include('recurrings._used-by', ['recurrings' => $usedBy->filter(fn ($r) => $r->is_active && ! $r->isEnded())])@endif</div>
        @enderror
    </div>
@endif
