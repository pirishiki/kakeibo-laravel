{{-- 記録の登録・編集の入力欄（画面設計書 5.4）。app/Livewire/RecordForm.php とつながっている
     wire:model：欄の値を、部品の変数とつなぐ。.live がついた欄は、変えたらすぐサーバーに送り、画面の一部を書きかえる --}}
<div style="max-width: 560px">
    {{-- 左上の「‹ やめる」：保存せずに、開く前の画面（見ていた月の一覧など）に戻る。
         保存から離れた場所に置き、保存のつもりで押して直した内容が消えないようにする（画面設計書 5.4） --}}
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ $backUrl }}" class="text-decoration-none">‹ やめる</a>
        <h1 class="h4 mb-0">{{ $recordId === null ? '記録する' : '記録を直す' }}</h1>
    </div>

    <div class="card">
        <div class="card-body">
            @if ($type === 'adjustment')
                {{-- 調整の記録は、種類を切りかえず「調整」と書く（金額・日付・口座・メモだけを出す） --}}
                <p class="fw-bold mb-4">調整</p>
            @else
                {{-- 種類：いちばん上で切りかえる。切りかえると、下の欄が種類に合わせて入れかわる --}}
                <div class="btn-group w-100 mb-4" role="group" aria-label="種類">
                    @foreach (['expense' => '支出', 'income' => '収入', 'transfer' => '移動'] as $value => $label)
                        <input type="radio" class="btn-check" name="type" id="type-{{ $value }}" value="{{ $value }}" wire:model.live="type" autocomplete="off">
                        <label class="btn btn-outline-primary" for="type-{{ $value }}">{{ $label }}</label>
                    @endforeach
                </div>
            @endif

            <form wire:submit="save" novalidate>
                {{-- 金額：毎回入れるので、いちばん上。スマホでは数字のキーボードを出す（inputmode="numeric"）。
                     欄を離れたらカンマをつける（data-amount。resources/js/app.js） --}}
                <div class="mb-3">
                    <label for="amount" class="form-label">金額</label>
                    <div class="input-group has-validation">
                        {{-- 調整はマイナスも打つので、スマホでも「-」のあるキーボードにする --}}
                        <input type="text" id="amount" inputmode="{{ $type === 'adjustment' ? 'text' : 'numeric' }}" wire:model="amount" autocomplete="off" data-amount
                            class="form-control text-end @error('amount') is-invalid @enderror" @if ($recordId === null) autofocus @endif>
                        <span class="input-group-text">円</span>
                        @error('amount')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="date" class="form-label">日付</label>
                    <input type="date" id="date" wire:model.live="date" class="form-control @error('date') is-invalid @enderror" aria-describedby="date-hint">
                    {{-- 曜日と、今日から何日か（画面設計書 4 章の 5 の「10月9日（金）」の形）。1 年以内の打ちまちがいにも気づけるように --}}
                    <div id="date-hint" class="form-text">{{ $this->dateHint }}</div>
                    @error('date')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                @if ($type === 'adjustment')
                    <div class="mb-3">
                        <label for="accountId" class="form-label">口座</label>
                        @include('livewire.partials.account-select', ['field' => 'accountId'])
                    </div>
                @elseif ($type === 'transfer')
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
                                    {{-- 編集で、もともと入っている相手のときは「使う」に戻さないので、この文は出さない --}}
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

                {{-- 「使わない」口座の残高が 0 円でなくなる直しのときの理由（画面設計書 5.4） --}}
                @error('blocker')
                    <div class="alert alert-danger" role="alert">{{ $message }}</div>
                @enderror

                <div class="d-grid gap-2">
                    {{-- 保存しているあいだは押せなくする（2 回押して、同じ記録が 2 つできないように） --}}
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">保存</button>
                    @if ($recordId === null)
                        {{-- 夜にレシートをまとめて入れるとき：種類・日付・口座を残して、続けて入れる --}}
                        <button type="button" class="btn btn-outline-primary" wire:click="save(true)" wire:loading.attr="disabled">保存して続けて入力</button>
                    @else
                        {{-- 編集のときは「削除」。押すと確認の小窓を出す（4 章の 8） --}}
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-dialog">削除</button>
                    @endif
                </div>
            </form>
        </div>
    </div>

    @if ($recordId !== null)
        {{-- 削除の確認の小窓。削除できないとき（「使わない」口座の残高が変わるとき）は、理由と「閉じる」だけを出す --}}
        <div class="modal fade" id="delete-dialog" tabindex="-1" aria-labelledby="delete-dialog-title" aria-hidden="true" wire:ignore.self>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body">
                        @if ($this->deleteBlocker === null)
                            <p id="delete-dialog-title" class="mb-0">この記録を削除しますか？</p>
                        @else
                            <p id="delete-dialog-title" class="mb-0">{{ $this->deleteBlocker }}</p>
                        @endif
                    </div>
                    <div class="modal-footer">
                        @if ($this->deleteBlocker === null)
                            <button type="button" class="btn btn-danger" wire:click="delete" wire:loading.attr="disabled">削除する</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                        @else
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">閉じる</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
