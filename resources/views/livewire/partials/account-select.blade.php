{{-- 口座を選ぶ欄（記録の登録の口座・どこから・どこへ）。「使わない」口座は出さない --}}
<select id="{{ $field }}" wire:model="{{ $field }}" class="form-select @error($field) is-invalid @enderror">
    <option value="">選んでください</option>
    @foreach ($this->accounts as $account)
        <option value="{{ $account->id }}">{{ $account->name }}</option>
    @endforeach
</select>
@error($field)
    <div class="invalid-feedback">{{ $message }}</div>
@enderror
