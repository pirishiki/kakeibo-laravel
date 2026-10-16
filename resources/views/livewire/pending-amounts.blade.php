{{-- ホームのお知らせ（画面設計書 5.2）。app/Livewire/PendingAmounts.php とつながっている
     「金額がまだ」は、ふだんは 1 行で、押すとその場で入力欄が開く。「取っておく額を下回っています」は押すと口座の管理へ。
     お知らせが 1 つもないときは、カードごと出さない --}}
{{-- ホームの横並び（row）の 1 つとして、横いっぱいに出す。お知らせがないときは、すき間もできないように隠す --}}
<div @class(['col-12', 'd-none' => $this->items === [] && $shortages === [] && $budgetAlerts === [] && $notice === ''])>
    {{-- 入れた金額を使えなかったときは、黙らずに知らせる（お知らせが全部なくなったときも出す） --}}
    @if ($notice !== '')
        <div class="alert alert-warning" role="alert">{{ $notice }}</div>
    @endif

    @if ($this->items !== [] || $shortages !== [] || $budgetAlerts !== [])
        <section class="card home-card border-primary">
            <div class="card-body">
                <h2 class="home-label">お知らせ</h2>
                {{-- 取っておく額を下回っている口座（「金額」の口座で、今月末の見込みの残高が足りないとき） --}}
                @foreach ($shortages as $name)
                    <a href="{{ route('accounts.index') }}" class="d-block text-decoration-none mb-1">{{ $name }} が取っておく額を下回っています <span aria-hidden="true">›</span></a>
                @endforeach
                {{-- 今月、予算の 80% 以上になった分類（第 3 段階）。押すと月のまとめへ。% は 1 % 未満を切り捨て、「こえました」は予算より多く使ったときだけ --}}
                @foreach ($budgetAlerts as $alert)
                    <a href="{{ route('summary.index') }}" class="d-block text-decoration-none text-danger mb-1">
                        @if ($alert['status']['over'])
                            {{ $alert['name'] }} が予算をこえました（+{{ number_format(-$alert['status']['rest']) }} 円）
                        @else
                            {{ $alert['name'] }} が予算の {{ $alert['status']['percent'] }}% です（あと {{ number_format($alert['status']['rest']) }} 円）
                        @endif
                        <span aria-hidden="true">›</span>
                    </a>
                @endforeach

                @if ($this->items !== [])
                <button type="button" class="btn btn-link p-0 text-decoration-none d-inline-flex align-items-center gap-1 text-start"
                    data-bs-toggle="collapse" data-bs-target="#home-pending" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="home-pending" wire:ignore.self>
                    金額がまだのものが {{ count($this->items) }} 件あります <x-chevron />
                </button>

                {{-- 開いたあと描き直しても、開いたままにする（wire:ignore.self。上のボタンの「開いている」と矢印の向きも同じ）。
                     「今回はなし」でホームを出し直したときは、開いたまま出す（$open） --}}
                <div @class(['collapse', 'show' => $open]) id="home-pending" wire:ignore.self>
                    <form wire:submit="save" novalidate class="mt-2">
                        <div class="list-group list-group-flush mb-2">
                            @foreach ($this->items as $item)
                                @php
                                    $key = \App\Livewire\PendingAmounts::key($item['recurring'], $item['month']);
                                @endphp
                                <div class="list-group-item px-0" wire:key="pending-{{ $key }}">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                        <div class="min-w-0">
                                            <label for="pending-{{ $key }}" class="d-block text-break">
                                                {{-- もとの予定の月が今月でないときは「9月の分」「11月の分」の印 --}}
                                                @if ($item['label'] !== null)
                                                    <span class="badge rounded-pill text-primary bg-primary-subtle fw-normal me-1">{{ $item['label'] }}</span>
                                                @endif
                                                {{ $item['recurring']->name }}
                                            </label>
                                            <x-date :date="$item['date']" class="d-block small text-secondary" />
                                        </div>
                                        <div class="text-end flex-shrink-0">
                                            {{-- 概算を薄い字で出しておく。欄を離れたらカンマをつける（data-amount） --}}
                                            <div class="input-group input-group-sm has-validation ms-auto" style="width: 10rem">
                                                <input type="text" id="pending-{{ $key }}" inputmode="numeric" autocomplete="off" data-amount
                                                    wire:model="amounts.{{ $key }}"
                                                    placeholder="{{ $item['estimate'] === null ? '' : '概算 '.number_format($item['estimate']) }}"
                                                    class="form-control text-end @error('amounts.'.$key) is-invalid @enderror"
                                                    @error('amounts.'.$key) aria-describedby="pending-{{ $key }}-error" @enderror>
                                                <span class="input-group-text">円</span>
                                                @error('amounts.'.$key)
                                                    <div id="pending-{{ $key }}-error" class="invalid-feedback text-start">{{ $message }}</div>
                                                @enderror
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" wire:click="skip('{{ $key }}')" aria-label="{{ $item['recurring']->name }}を今回はなしにする" wire:loading.attr="disabled">今回はなし</button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="text-end">
                            {{-- 入れたものだけを保存する（空の欄は保存しない）。日付が今日か過ぎていたものは、予定の日付で記録する --}}
                            <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">まとめて保存</button>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </section>
    @endif
</div>
