{{-- ホームのお知らせ（画面設計書 5.2）。app/Livewire/PendingAmounts.php とつながっている
     いまは「金額がまだ」だけ。ふだんは 1 行で、押すとその場で入力欄が開く。お知らせが 1 つもないときは、カードごと出さない --}}
{{-- ホームの横並び（row）の 1 つとして、横いっぱいに出す。お知らせがないときは、すき間もできないように隠す --}}
<div @class(['col-12', 'd-none' => $this->items === []])>
    @if ($this->items !== [])
        <section class="card home-card border-primary">
            <div class="card-body">
                <h2 class="home-label">お知らせ</h2>
                <button type="button" class="btn btn-link p-0 text-decoration-none d-inline-flex align-items-center gap-1 text-start"
                    data-bs-toggle="collapse" data-bs-target="#home-pending" aria-expanded="false" aria-controls="home-pending">
                    金額がまだのものが {{ count($this->items) }} 件あります <x-chevron />
                </button>

                {{-- 開いたあと「今回はなし」で描き直しても、開いたままにする（wire:ignore.self） --}}
                <div class="collapse" id="home-pending" wire:ignore.self>
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
                                                    class="form-control text-end @error('amounts.'.$key) is-invalid @enderror">
                                                <span class="input-group-text">円</span>
                                                @error('amounts.'.$key)
                                                    <div class="invalid-feedback text-start">{{ $message }}</div>
                                                @enderror
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" wire:click="skip('{{ $key }}')" wire:loading.attr="disabled">今回はなし</button>
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
            </div>
        </section>
    @endif
</div>
