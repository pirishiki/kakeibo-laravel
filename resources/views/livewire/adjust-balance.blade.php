{{-- 残高を合わせる（画面設計書 5.7）。app/Livewire/AdjustBalance.php とつながっている
     上で実際の金額を入れ、下でその口座の最近の記録を見る（調整する前に、つけ忘れがないかを確かめられるように） --}}
<div style="max-width: 560px">
    {{-- 左上の「‹ やめる」：記録の登録・編集と同じく、保存せずに開く前の画面に戻る --}}
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ $backUrl }}" class="text-decoration-none">‹ やめる</a>
        <h1 class="h4 mb-0 text-break">{{ $account->name }}の残高を合わせる</h1>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form wire:submit="save" novalidate>
                <div class="d-flex justify-content-between mb-3">
                    <span>アプリの残高</span>
                    <x-money :amount="$this->appBalance" />
                </div>

                {{-- 実際の金額：マイナスも入れる（クレジットカードなど）。欄を離れたらカンマをつける（data-amount。resources/js/app.js） --}}
                <div class="mb-3">
                    <label for="actual" class="form-label">実際の金額</label>
                    <div class="input-group has-validation">
                        <input type="text" id="actual" inputmode="text" wire:model.live.debounce.300ms="actual" autocomplete="off" data-amount
                            class="form-control text-end @error('actual') is-invalid @enderror" autofocus>
                        <span class="input-group-text">円</span>
                        @error('actual')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- 差：実際の金額 − アプリの残高。プラスは「+」、マイナスは赤（4 章の 4） --}}
                <div class="d-flex justify-content-between mb-3" aria-live="polite">
                    <span>差</span>
                    @if ($this->difference === null)
                        <span class="text-secondary">—</span>
                    @elseif ($this->difference === 0)
                        <span class="text-secondary">ずれはありません</span>
                    @else
                        <span>@if ($this->difference > 0)+@endif<x-money :amount="$this->difference" /></span>
                    @endif
                </div>
                @if ($this->tooLargeMessage !== null)
                    <div class="text-danger small mb-3">{{ $this->tooLargeMessage }}</div>
                @endif

                <div class="mb-3">
                    <label for="date" class="form-label">日付</label>
                    {{-- 先の日付は選べない（数える前の金額は、まだわからないため）。前の日にすると、その日の終わりまでの残高とくらべる --}}
                    <input type="date" id="date" wire:model.live="date" max="{{ today()->toDateString() }}"
                        class="form-control @error('date') is-invalid @enderror" aria-describedby="date-help">
                    <div id="date-help" class="form-text">数えた日。前の日にすると、その日の終わりまでの残高とくらべます</div>
                    @error('date')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="memo" class="form-label">メモ</label>
                    <input type="text" id="memo" wire:model="memo" maxlength="1000" class="form-control @error('memo') is-invalid @enderror"
                        placeholder="財布を数えた">
                    @error('memo')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-grid">
                    {{-- 差がないとき（0 か、まだ入れていないとき）は押せない。0 円の調整の記録はつくらない --}}
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" @disabled(! $this->difference || $this->tooLargeMessage !== null)>調整として保存</button>
                </div>
            </form>
        </div>
    </div>

    {{-- つけ忘れを先に確かめる。つけ忘れを調整で直すと、その分が分類ごとの合計に入らず、月のまとめが実際とずれるため --}}
    <h2 class="h6">つけ忘れはありませんか？</h2>
    <p class="small text-secondary mb-2">{{ $account->name }}の最近の記録</p>
    {{-- 記録の画面から戻ってきたときも、この画面の戻る先を覚えておけるように、back つきのこの画面の URL を渡す --}}
    @php($here = route('accounts.adjust', ['account' => $account, 'back' => $backUrl]))
    @if ($this->recentRecords->isEmpty())
        <p class="text-secondary">まだ記録がありません</p>
    @else
        <div class="list-group mb-3">
            @foreach ($this->recentRecords as $record)
                @include('records._row', ['showDate' => true, 'back' => $here])
            @endforeach
        </div>
    @endif
    {{-- この口座を入れた状態で記録の登録を開く。相手を選んでも口座は変えない。保存するとこの画面に戻る --}}
    <a href="{{ route('records.create', ['account' => $account->id, 'keep_account' => 1, 'back' => $here]) }}"
        class="btn btn-outline-primary">＋ つけ忘れを記録する</a>
</div>
