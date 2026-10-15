@use('App\Support\RecordSearch')

{{-- 記録の一覧の「探す」の欄（画面設計書 5.3・要件定義書 No.18）。条件は URL のうしろに書いて送る（GET）。
     欄は全部「かつ」でつなぐ。空の欄は「どれでもよい」。送るときに空の欄は外し、URL を短くする（resources/js/app.js） --}}
@php
    $value = fn (string $key) => $searchValues[$key];
    $invalid = fn (string $key) => $searchErrors->has($key) ? 'is-invalid' : '';
@endphp
<div @class(['collapse', 'show' => $searchErrors->isNotEmpty()]) id="search-panel">
    <form method="GET" action="{{ route('records.index') }}" class="card mb-3" data-search-form>
        <div class="card-body">
            <input type="hidden" name="{{ RecordSearch::MARK }}" value="1">
            @if ($searchErrors->has(RecordSearch::MARK))
                <div class="alert alert-danger py-2 small" role="alert">{{ $searchErrors->first(RecordSearch::MARK) }}</div>
            @endif

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <fieldset>
                        <legend class="form-label fs-6 mb-2">期間</legend>
                        <div class="d-flex align-items-center gap-2">
                            <input type="date" id="search-from" name="from" value="{{ $value('from') }}" aria-label="始めの日"
                                class="form-control {{ $invalid('from') }}">
                            <span>〜</span>
                            <input type="date" id="search-to" name="to" value="{{ $value('to') }}" aria-label="終わりの日"
                                class="form-control {{ $invalid('to') }}">
                        </div>
                        @foreach (['from', 'to'] as $key)
                            @if ($searchErrors->has($key))
                                <div class="invalid-feedback d-block">{{ $searchErrors->first($key) }}</div>
                            @endif
                        @endforeach
                    </fieldset>
                </div>

                <div class="col-6 col-md-3">
                    <label for="search-type" class="form-label">種類</label>
                    <select id="search-type" name="type" class="form-select {{ $invalid('type') }}">
                        <option value="">すべて</option>
                        @foreach (RecordSearch::TYPES as $type => $label)
                            <option value="{{ $type }}" @selected($value('type') === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($searchErrors->has('type'))
                        <div class="invalid-feedback">{{ $searchErrors->first('type') }}</div>
                    @endif
                </div>

                <div class="col-6 col-md-3">
                    <label for="search-account" class="form-label">口座</label>
                    <select id="search-account" name="account" class="form-select {{ $invalid('account') }}">
                        <option value="">すべて</option>
                        @foreach ($searchAccounts as $account)
                            <option value="{{ $account->id }}" @selected($value('account') === (string) $account->id)>
                                {{ $account->name }}{{ $account->is_active ? '' : '（使わない）' }}
                            </option>
                        @endforeach
                    </select>
                    @if ($searchErrors->has('account'))
                        <div class="invalid-feedback">{{ $searchErrors->first('account') }}</div>
                    @endif
                </div>

                {{-- 分類：大分類で探すと、その下の小分類の記録もふくめて見つかる。小分類の欄には、選んだ大分類の小分類だけを出す（app.js） --}}
                <div class="col-6 col-md-3">
                    <label for="search-category" class="form-label">大分類</label>
                    <select id="search-category" name="category" class="form-select {{ $invalid('category') }}">
                        <option value="">すべて</option>
                        @foreach ($searchParents as $kind => $parents)
                            <optgroup label="{{ $kind }}">
                                @foreach ($parents as $parent)
                                    <option value="{{ $parent->id }}" @selected($value('category') === (string) $parent->id)>
                                        {{ $parent->name }}{{ $parent->is_active ? '' : '（使わない）' }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @if ($searchErrors->has('category'))
                        <div class="invalid-feedback">{{ $searchErrors->first('category') }}</div>
                    @endif
                </div>

                <div class="col-6 col-md-3">
                    <label for="search-sub" class="form-label">小分類</label>
                    <select id="search-sub" name="sub" class="form-select {{ $invalid('sub') }}" data-sub-of="search-category">
                        <option value="">すべて</option>
                        <option value="{{ RecordSearch::NO_SUB }}" @selected($value('sub') === RecordSearch::NO_SUB)>小分類なし</option>
                        @foreach ($searchParents as $parents)
                            @foreach ($parents as $parent)
                                @foreach ($parent->children as $child)
                                    <option value="{{ $child->id }}" data-parent="{{ $parent->id }}" @selected($value('sub') === (string) $child->id)>
                                        {{ $child->name }}{{ $child->is_active ? '' : '（使わない）' }}
                                    </option>
                                @endforeach
                            @endforeach
                        @endforeach
                    </select>
                    @if ($searchErrors->has('sub'))
                        <div class="invalid-feedback">{{ $searchErrors->first('sub') }}</div>
                    @endif
                </div>

                <div class="col-12 col-md-6">
                    <label for="search-partner" class="form-label">相手</label>
                    <select id="search-partner" name="partner" class="form-select {{ $invalid('partner') }}">
                        <option value="">すべて</option>
                        @foreach (['used' => '使った相手（最近使った順）','unused' => 'まだ使っていない相手', 'inactive' => '使わない相手'] as $group => $label)
                            @if ($searchPartners[$group]->isNotEmpty())
                                <optgroup label="{{ $label }}">
                                    @foreach ($searchPartners[$group] as $partner)
                                        <option value="{{ $partner->id }}" @selected($value('partner') === (string) $partner->id)>{{ $partner->name }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    @if ($searchErrors->has('partner'))
                        <div class="invalid-feedback">{{ $searchErrors->first('partner') }}</div>
                    @endif
                </div>

                <div class="col-12 col-md-6">
                    <label for="search-item" class="form-label">品物（言葉の一部）</label>
                    <input type="text" id="search-item" name="item" value="{{ $value('item') }}" maxlength="255"
                        class="form-control {{ $invalid('item') }}" placeholder="例：パン">
                    @if ($searchErrors->has('item'))
                        <div class="invalid-feedback">{{ $searchErrors->first('item') }}</div>
                    @endif
                    <div class="form-text">メモは探しません</div>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">探す</button>
            </div>
        </div>
    </form>
</div>
