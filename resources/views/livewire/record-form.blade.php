{{-- 記録の登録の入力欄（画面設計書 5.4）。app/Livewire/RecordForm.php とつながっている
     wire:model：欄の値を、部品の変数とつなぐ。.live がついた欄は、変えたらすぐサーバーに送り、画面の一部を書きかえる --}}
<div class="card" style="max-width: 560px">
    <div class="card-body">
        {{-- 種類：いちばん上で切りかえる。切りかえると、下の欄が種類に合わせて入れかわる --}}
        <div class="btn-group w-100 mb-4" role="group" aria-label="種類">
            @foreach (['expense' => '支出', 'income' => '収入', 'transfer' => '移動'] as $value => $label)
                <input type="radio" class="btn-check" name="type" id="type-{{ $value }}" value="{{ $value }}" wire:model.live="type" autocomplete="off">
                <label class="btn btn-outline-primary" for="type-{{ $value }}">{{ $label }}</label>
            @endforeach
        </div>

        <form wire:submit="save" novalidate>
            {{-- 金額：毎回入れるので、いちばん上。スマホでは数字のキーボードを出す（inputmode="numeric"）。
                 欄を離れたらカンマをつける（data-amount。resources/js/app.js） --}}
            <div class="mb-3">
                <label for="amount" class="form-label">金額</label>
                <div class="input-group has-validation">
                    <input type="text" id="amount" inputmode="numeric" wire:model="amount" autocomplete="off" data-amount
                        class="form-control text-end @error('amount') is-invalid @enderror" autofocus>
                    <span class="input-group-text">円</span>
                    @error('amount')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="mb-3">
                <label for="date" class="form-label">日付</label>
                <input type="date" id="date" wire:model="date" class="form-control @error('date') is-invalid @enderror">
                @error('date')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @if ($type === 'transfer')
                <div class="mb-3">
                    <label for="accountId" class="form-label">どこから</label>
                    @include('livewire.partials.account-select', ['field' => 'accountId'])
                </div>
                <div class="mb-3">
                    <label for="toAccountId" class="form-label">どこへ</label>
                    @include('livewire.partials.account-select', ['field' => 'toAccountId'])
                </div>
            @else
                {{-- 相手：分類と口座より上に置く。打つと候補が出て、選ぶとその相手の前回の分類と口座が入る --}}
                <div class="mb-3 position-relative">
                    <label for="partnerName" class="form-label">相手</label>
                    <input type="text" id="partnerName" wire:model.live.debounce.250ms="partnerName" autocomplete="off"
                        class="form-control @error('partnerName') is-invalid @enderror" maxlength="50">
                    @error('partnerName')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    @if ($partnerId === null && trim($partnerName) !== '')
                        <div class="list-group mt-1" aria-label="相手の候補">
                            @foreach ($this->partnerSuggestions as $suggestion)
                                <button type="button" class="list-group-item list-group-item-action" wire:click="selectPartner({{ $suggestion->id }})">
                                    {{ $suggestion->name }}
                                </button>
                            @endforeach
                            @if ($this->exactPartner === null)
                                <div class="list-group-item small text-primary">＋ 「{{ trim($partnerName) }}」を新しい相手として追加します</div>
                            @elseif (! $this->exactPartner->is_active)
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

                <div class="mb-3">
                    <label for="accountId" class="form-label">口座</label>
                    @include('livewire.partials.account-select', ['field' => 'accountId'])
                </div>

                @if ($type === 'expense')
                    {{-- 品物は、支出のときだけ出す --}}
                    <div class="mb-3">
                        <label for="item" class="form-label">品物</label>
                        <input type="text" id="item" wire:model="item" maxlength="100" class="form-control @error('item') is-invalid @enderror">
                        @error('item')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            @endif

            <div class="mb-4">
                <label for="memo" class="form-label">メモ</label>
                <textarea id="memo" wire:model="memo" rows="2" maxlength="1000" class="form-control @error('memo') is-invalid @enderror"></textarea>
                @error('memo')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">保存</button>
                {{-- 夜にレシートをまとめて入れるとき：種類・日付・口座を残して、続けて入れる --}}
                <button type="button" class="btn btn-outline-primary" wire:click="save(true)">保存して続けて入力</button>
            </div>
        </form>
    </div>
</div>
