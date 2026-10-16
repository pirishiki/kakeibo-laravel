{{-- 貯金の目標の追加・変更の入力欄（画面設計書 5.13）。$accounts は選べる口座、$years は期限の年の候補 --}}
<div class="mb-3">
    <label for="name" class="form-label">名前</label>
    <input type="text" id="name" name="name" value="{{ old('name', $goal->name) }}"
        class="form-control @error('name') is-invalid @enderror" maxlength="50" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="target_amount" class="form-label">目標額</label>
    <div class="input-group has-validation">
        {{-- 記録の金額の欄と同じ形：文字の欄で、欄を離れたらカンマをつける（data-amount。resources/js/app.js） --}}
        <input type="text" id="target_amount" name="target_amount" inputmode="numeric" autocomplete="off" data-amount
            value="{{ old('target_amount', $goal->target_amount !== null ? number_format($goal->target_amount) : '') }}"
            class="form-control text-end @error('target_amount') is-invalid @enderror" required>
        <span class="input-group-text">円</span>
        @error('target_amount')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <label for="account_id" class="form-label">口座</label>
    @php($selectedAccount = (string) old('account_id', $goal->account_id))
    <select id="account_id" name="account_id" class="form-select @error('account_id') is-invalid @enderror" aria-describedby="account_id-help" required>
        <option value="" @selected($selectedAccount === '')>選んでください</option>
        @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected($selectedAccount === (string) $account->id)>{{ $account->name }}</option>
        @endforeach
    </select>
    @error('account_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div id="account_id-help" class="form-text">この口座の今の残高を、貯めた額として使います。ほかの目標で使っている口座・クレジットカード・「使わない」口座は選べません</div>
</div>

{{-- 期限：月で選ぶ（その月の終わりまで）。年を「なし」にすると期限なし --}}
@php($deadlineYear = (string) old('deadline_year', $goal->deadline_month?->year))
@php($deadlineMonth = (string) old('deadline_month', $goal->deadline_month?->month))
<fieldset class="mb-4">
    <legend class="form-label fs-6 mb-2">期限</legend>
    <div class="d-flex gap-2 align-items-center">
        <select id="deadline_year" name="deadline_year" aria-label="期限の年" class="form-select w-auto @error('deadline_year') is-invalid @enderror">
            <option value="" @selected($deadlineYear === '')>なし</option>
            @foreach ($years as $y)
                <option value="{{ $y }}" @selected($deadlineYear === (string) $y)>{{ $y }}年</option>
            @endforeach
        </select>
        <select id="deadline_month" name="deadline_month" aria-label="期限の月" class="form-select w-auto @error('deadline_month') is-invalid @enderror">
            <option value="" @selected($deadlineMonth === '')>-</option>
            @foreach (range(1, 12) as $m)
                <option value="{{ $m }}" @selected($deadlineMonth === (string) $m)>{{ $m }}月</option>
            @endforeach
        </select>
        <span>まで</span>
    </div>
    @error('deadline_year')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
    @error('deadline_month')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
    <div class="form-text">今月より前は選べません</div>
</fieldset>
