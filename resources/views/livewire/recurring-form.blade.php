{{-- 定期的な記録の登録・変更の入力欄（画面設計書 5.10）。app/Livewire/RecurringForm.php とつながっている --}}
<div style="max-width: 560px">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('recurrings.index') }}" class="text-decoration-none">‹ やめる</a>
        <h1 class="h4 mb-0">{{ $recurringId === null ? '定期的な記録を登録する' : '定期的な記録を直す' }}</h1>
    </div>

    <div class="card">
        <div class="card-body">
            {{-- 種類：いちばん上で切りかえる（記録の登録 5.4 と同じ）。移動のときは相手・分類の欄が消え、口座が 2 つになる --}}
            <div class="btn-group w-100 mb-4" role="group" aria-label="種類">
                @foreach (['expense' => '支出', 'income' => '収入', 'transfer' => '移動'] as $value => $label)
                    <input type="radio" class="btn-check" name="type" id="type-{{ $value }}" value="{{ $value }}" wire:model.live="type" autocomplete="off">
                    <label class="btn btn-outline-primary" for="type-{{ $value }}">{{ $label }}</label>
                @endforeach
            </div>

            <form wire:submit="save" novalidate>
                <div class="mb-3">
                    <label for="name" class="form-label">名前</label>
                    <input type="text" id="name" wire:model="name" maxlength="50" class="form-control @error('name') is-invalid @enderror"
                        placeholder="給料・家賃など" @if ($recurringId === null) autofocus @endif>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- 金額：決まっているなら金額、毎月変わるなら概算（どちらも必須） --}}
                <fieldset class="mb-3">
                    <legend class="form-label fs-6 mb-2">金額</legend>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" id="isFixed-1" value="1" wire:model.live="isFixed">
                        <label class="form-check-label" for="isFixed-1">決まっている</label>
                    </div>
                    @if ($isFixed === '1')
                        <div class="input-group has-validation ms-4 mt-1 mb-2" style="max-width: 240px">
                            <input type="text" id="amount" inputmode="numeric" wire:model="amount" autocomplete="off" data-amount aria-label="金額"
                                class="form-control text-end @error('amount') is-invalid @enderror">
                            <span class="input-group-text">円</span>
                            @error('amount')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    @endif
                    <div class="form-check">
                        <input class="form-check-input" type="radio" id="isFixed-0" value="0" wire:model.live="isFixed">
                        <label class="form-check-label" for="isFixed-0">毎月変わる</label>
                    </div>
                    @if ($isFixed === '0')
                        <div class="ms-4 mt-1">
                            <div class="input-group has-validation" style="max-width: 240px">
                                <span class="input-group-text">概算</span>
                                <input type="text" id="estimatedAmount" inputmode="numeric" wire:model="estimatedAmount" autocomplete="off" data-amount aria-label="概算"
                                    class="form-control text-end @error('estimatedAmount') is-invalid @enderror" aria-describedby="estimated-hint">
                                <span class="input-group-text">円</span>
                                @error('estimatedAmount')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div id="estimated-hint" class="form-text">毎月の金額は、ホームのお知らせから入れます。概算は、まだ入れていない月の目安に使います</div>
                        </div>
                    @endif
                </fieldset>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label for="day" class="form-label">日付</label>
                        {{-- 1〜28 日か月末だけ（29〜31 日は、ない月があるため。要件定義書 No.11・12） --}}
                        <select id="day" wire:model.live="day" class="form-select @error('day') is-invalid @enderror">
                            @foreach ([...range(1, 28), \App\Support\RecurringDate::END_OF_MONTH] as $d)
                                <option value="{{ $d }}">{{ \App\Support\RecurringDate::dayLabel($d) }}</option>
                            @endforeach
                        </select>
                        @error('day')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-6">
                        <label for="shift" class="form-label">土日・祝日</label>
                        <select id="shift" wire:model.live="shift" class="form-select @error('shift') is-invalid @enderror">
                            @foreach (\App\Enums\RecurringShift::cases() as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                        </select>
                        @error('shift')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6 mb-2">どの月</legend>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" id="monthsMode-every" value="every" wire:model.live="monthsMode">
                        <label class="form-check-label" for="monthsMode-every">毎月</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" id="monthsMode-selected" value="selected" wire:model.live="monthsMode">
                        <label class="form-check-label" for="monthsMode-selected">選んだ月だけ</label>
                    </div>
                    @if ($monthsMode === 'selected')
                        {{-- 賞与・ボーナス払いなど。1 つ以上選ぶ --}}
                        <div class="d-flex flex-wrap gap-2 mt-2" role="group" aria-label="選んだ月">
                            @foreach (range(1, 12) as $m)
                                <input type="checkbox" class="btn-check" id="month-{{ $m }}" value="{{ $m }}" wire:model.live="months" autocomplete="off">
                                <label class="btn btn-sm btn-outline-primary month-check" for="month-{{ $m }}">{{ $m }}月</label>
                            @endforeach
                        </div>
                        @error('months')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                        @error('months.*')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    @endif
                </fieldset>

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
                    @include('livewire.partials.partner-category')

                    <div class="mb-3">
                        <label for="accountId" class="form-label">口座</label>
                        @include('livewire.partials.account-select', ['field' => 'accountId'])
                    </div>
                @endif

                {{-- 最初の月：新しく登録するときは、記録する日がまだ来ていない、いちばん早い月が入っている --}}
                <fieldset class="mb-3">
                    <legend class="form-label fs-6 mb-2">最初の月</legend>
                    <div class="d-flex gap-2 align-items-center">
                        <select id="startYear" wire:model.live="startYear" aria-label="最初の月の年" class="form-select w-auto @error('startYear') is-invalid @enderror">
                            @foreach ($this->startYears() as $y)
                                <option value="{{ $y }}">{{ $y }}年</option>
                            @endforeach
                        </select>
                        <select id="startMonth" wire:model.live="startMonth" aria-label="最初の月の月" class="form-select w-auto @error('startMonth') is-invalid @enderror">
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}">{{ $m }}月</option>
                            @endforeach
                        </select>
                    </div>
                    @error('startYear')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                    @error('startMonth')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </fieldset>

                {{-- 最後の月：わかるときだけ（ローンなど）。空なら終わりなし --}}
                <fieldset class="mb-3">
                    <legend class="form-label fs-6 mb-2">最後の月</legend>
                    <div class="d-flex gap-2 align-items-center">
                        <select id="endYear" wire:model.live="endYear" aria-label="最後の月の年" class="form-select w-auto @error('endYear') is-invalid @enderror">
                            <option value="">なし</option>
                            @foreach ($this->endYears() as $y)
                                <option value="{{ $y }}">{{ $y }}年</option>
                            @endforeach
                        </select>
                        <select id="endMonth" wire:model="endMonth" aria-label="最後の月の月" class="form-select w-auto @error('endMonth') is-invalid @enderror"
                            @disabled($endYear === '')>
                            <option value="">-</option>
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}">{{ $m }}月</option>
                            @endforeach
                        </select>
                    </div>
                    @error('endYear')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                    @error('endMonth')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </fieldset>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="isActive" wire:model="isActive">
                    <label class="form-check-label" for="isActive">使う</label>
                </div>

                <div class="d-grid gap-2">
                    {{-- 保存しているあいだは押せなくする（2 回押して、同じ予定が 2 つできないように） --}}
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">保存</button>
                    @if ($this->saved?->is_fixed)
                        {{-- 金額が先の月から変わるとき：今の予定に最後の月を入れて保存してから押す --}}
                        <button type="button" class="btn btn-outline-primary" wire:click="continueWithNewAmount" wire:loading.attr="disabled">金額を変えて続きを作る</button>
                        @error('continue')
                            <div class="text-danger small" role="alert">{{ $message }}</div>
                        @enderror
                    @endif
                    @if ($this->saved !== null)
                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-dialog">削除</button>
                    @endif
                </div>
            </form>
        </div>
    </div>

    {{-- 保存の前の確認（画面設計書 5.10）
         ・過ぎた月が記録の対象に入るときは、その月を「今回はなし」にすると知らせる
         ・日付の変更で、今月の分の日付がもう過ぎた日になるときは、「今回はなし」か「○月○日で記録する」かを選んでもらう --}}
    <div class="modal fade" id="skip-dialog" tabindex="-1" aria-labelledby="skip-dialog-title" aria-hidden="true" wire:ignore.self>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body" id="skip-dialog-title">
                    @if ($skipNotice !== '')
                        <p>{{ $skipNotice }}</p>
                        <p class="small text-secondary">記録したい月は、あとで月ごとの金額の「もどす」で記録できます</p>
                    @endif
                    @if ($passedNotice !== '')
                        <p class="mb-0">{{ $passedNotice }}</p>
                    @endif
                </div>
                <div class="modal-footer">
                    @if ($passedNotice !== '')
                        <button type="button" class="btn btn-primary" wire:click="save(true, 'record')" wire:loading.attr="disabled">{{ $passedRecordLabel }}</button>
                        <button type="button" class="btn btn-outline-primary" wire:click="save(true, 'skip')" wire:loading.attr="disabled">今回はなし</button>
                    @else
                        <button type="button" class="btn btn-primary" wire:click="save(true)" wire:loading.attr="disabled">保存する</button>
                    @endif
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                </div>
            </div>
        </div>
    </div>

    @if ($this->saved !== null)
        {{-- 削除の小窓。記録がある予定は削除できず、「使わない」にするかを聞く（4 章の 8・9） --}}
        <div class="modal fade" id="delete-dialog" tabindex="-1" aria-labelledby="delete-dialog-title" aria-hidden="true" wire:ignore.self>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body">
                        @if (! $this->saved->records_exists)
                            <p id="delete-dialog-title" class="mb-0">「{{ $this->saved->name }}」を削除しますか？</p>
                        @elseif (! $this->saved->is_active)
                            <p id="delete-dialog-title" class="mb-0">記録があるので削除できません。この定期的な記録は、もう「使わない」になっています。</p>
                        @else
                            <p id="delete-dialog-title" class="mb-0">記録があるので削除できません。『使わない』にしますか？</p>
                        @endif
                        @error('delete')
                            <p class="text-danger small mt-2 mb-0" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="modal-footer">
                        @if (! $this->saved->records_exists)
                            <button type="button" class="btn btn-danger" wire:click="delete" wire:loading.attr="disabled">削除する</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">やめる</button>
                        @elseif ($this->saved->is_active)
                            <button type="button" class="btn btn-primary" wire:click="deactivate" wire:loading.attr="disabled">「使わない」にする</button>
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
