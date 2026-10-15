@use('App\Support\RecurringMonthly')

{{-- 月ごとの金額（画面設計書 5.10 前半）。app/Livewire/MonthlyAmounts.php とつながっている
     その月の分の予定を、記録する日（ずらしたあとの日）の順に並べる。記録済み・決まった金額の行には入力欄を出さない --}}
<div>
    @if ($this->rows === [])
        {{-- 予定のない月は、空白にしない（4 章の 6） --}}
        <p class="text-secondary">この月の予定はありません</p>
    @else
        <form wire:submit="save" novalidate>
            <div class="list-group mb-3">
                @foreach ($this->rows as $row)
                    @php
                        $recurring = $row['recurring'];
                        $id = $recurring->id;
                    @endphp
                    <div @class(['list-group-item', 'text-secondary' => $row['status'] === RecurringMonthly::SKIPPED || ! $recurring->is_active]) wire:key="row-{{ $id }}">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div class="min-w-0">
                                @if ($row['status'] === RecurringMonthly::VARIABLE)
                                    <label for="amount-{{ $id }}" class="d-block text-break">{{ $recurring->name }}</label>
                                @else
                                    <span class="d-block text-break">{{ $recurring->name }}@if (! $recurring->is_active)（使わない）@endif</span>
                                @endif
                                {{-- となりの月にずれても、もとの予定の月に出す。ずらしたときは理由を小さく出す --}}
                                <span class="d-block small text-secondary">
                                    <x-date :date="$row['date']" />@if ($row['reason'] !== null)（{{ $row['reason'] }}）@endif
                                </span>
                            </div>
                            <div class="text-end flex-shrink-0">
                                @switch($row['status'])
                                    @case(RecurringMonthly::RECORDED)
                                        {{-- 記録済み：金額は記録から読む。直すときは「›」で記録の編集へ（保存すると、この画面に戻る） --}}
                                        <a href="{{ route('records.edit', $row['recordId']) }}" class="text-decoration-none text-reset"
                                            aria-label="{{ $recurring->name }}の記録を直す（{{ number_format((int) $row['amount']) }}円）">
                                            <x-money :amount="(int) $row['amount']" /> <span class="text-secondary" aria-hidden="true">›</span>
                                        </a>
                                        <span class="d-block small text-secondary">記録済み</span>
                                        @break
                                    @case(RecurringMonthly::SKIPPED)
                                        <span class="d-block">今回はなし</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" wire:click="restore({{ $id }})" aria-label="{{ $recurring->name }}をもどす" wire:loading.attr="disabled">もどす</button>
                                        @break
                                    @case(RecurringMonthly::FIXED)
                                        <x-money :amount="(int) $row['amount']" />
                                        <span class="d-block small text-secondary">決まった金額</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" wire:click="skip({{ $id }})" aria-label="{{ $recurring->name }}を今回はなしにする" wire:loading.attr="disabled">今回はなし</button>
                                        @break
                                    @default
                                        {{-- 金額が変わる：入れた金額があれば出し、なければ概算を薄い字で出す。欄を離れたらカンマをつける（data-amount） --}}
                                        <div class="input-group input-group-sm has-validation ms-auto" style="width: 10rem">
                                            <input type="text" id="amount-{{ $id }}" inputmode="numeric" autocomplete="off" data-amount
                                                wire:model="amounts.{{ $id }}"
                                                placeholder="{{ $row['estimate'] === null ? '' : '概算 '.number_format($row['estimate']) }}"
                                                class="form-control text-end @error('amounts.'.$id) is-invalid @enderror"
                                                @error('amounts.'.$id) aria-describedby="amount-{{ $id }}-error" @enderror>
                                            <span class="input-group-text">円</span>
                                            @error('amounts.'.$id)
                                                <div id="amount-{{ $id }}-error" class="invalid-feedback text-start">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" wire:click="skip({{ $id }})" aria-label="{{ $recurring->name }}を今回はなしにする" wire:loading.attr="disabled">今回はなし</button>
                                @endswitch
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if (collect($this->rows)->contains('status', RecurringMonthly::VARIABLE))
                <div class="text-end">
                    {{-- 全部の行をまとめて保存する。日付が今日か過ぎていたものは、予定の日付ですぐに記録する --}}
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">保存</button>
                </div>
            @endif
        </form>
    @endif
</div>
