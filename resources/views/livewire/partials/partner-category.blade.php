{{-- 相手・分類・小分類の欄（記録の登録 5.4 と定期的な記録の登録 5.10 で共通。app/Livewire/Concerns/ChoosesPartnerCategoryAccount.php とつながる） --}}
{{-- 相手：分類と口座より上に置く。打つと候補が出て、選ぶとその相手の前回の分類と口座が入る --}}
<div class="mb-3 position-relative">
    <label for="partnerName" class="form-label">相手</label>
    <input type="text" id="partnerName" wire:model.live.debounce.250ms="partnerName" autocomplete="off"
        class="form-control @error('partnerName') is-invalid @enderror" maxlength="50">
    @error('partnerName')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror

    @if ($partnerId === null && \App\Models\Partner::normalizeName($partnerName) !== '')
        <div class="list-group mt-1" role="group" aria-label="相手の候補" aria-live="polite">
            @foreach ($this->partnerSuggestions as $suggestion)
                <button type="button" class="list-group-item list-group-item-action" wire:click="selectPartner({{ $suggestion->id }})">
                    {{ $suggestion->name }}
                </button>
            @endforeach
            @if ($this->exactPartner === null)
                <div class="list-group-item small text-primary">＋ 「{{ \App\Models\Partner::normalizeName($partnerName) }}」を新しい相手として追加します</div>
            @elseif (! $this->exactPartner->is_active && ! $this->isOriginalPartner)
                {{-- 変更で、もともと入っている相手のときは「使う」に戻さないので、この文は出さない --}}
                <div class="list-group-item small text-secondary">「{{ $this->exactPartner->name }}」は『使わない』の相手です。保存すると『使う』に戻して使います</div>
            @endif
        </div>
    @endif
</div>

<div class="mb-3">
    <label for="categoryId" class="form-label">分類</label>
    <select id="categoryId" wire:model.live="categoryId" class="form-select @error('categoryId') is-invalid @enderror">
        <option value="">選んでください</option>
        @foreach ($this->categories as $category)
            <option value="{{ $category->id }}">{{ $category->name }}</option>
        @endforeach
    </select>
    @error('categoryId')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="subcategoryId" class="form-label">小分類</label>
    <select id="subcategoryId" wire:model="subcategoryId" class="form-select @error('subcategoryId') is-invalid @enderror"
        @disabled($this->subcategories->isEmpty())>
        <option value="">なし</option>
        @foreach ($this->subcategories as $subcategory)
            <option value="{{ $subcategory->id }}">{{ $subcategory->name }}</option>
        @endforeach
    </select>
    @error('subcategoryId')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
