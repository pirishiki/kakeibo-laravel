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
        {{-- step="1"：円に小数はない。マイナスも入れられる（クレジットカードなど） --}}
        <input type="number" id="initial_balance" name="initial_balance" step="1"
            value="{{ old('initial_balance', $account->initial_balance) }}"
            class="form-control text-end @error('initial_balance') is-invalid @enderror"
            @disabled($hasRecords) required>
        <span class="input-group-text">円</span>
        @error('initial_balance')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    @if ($hasRecords)
        {{-- 「残高を合わせる」（5.7）の画面ができたら、ここをリンクにする --}}
        <div class="form-text">記録があるので変えられません。今の残高を直すときは「残高を合わせる」を使います（準備中）</div>
    @else
        <div class="form-text">使い始める日の金額。クレジットカードで、まだ払っていない分があるときはマイナスで入れます</div>
    @endif
</div>
