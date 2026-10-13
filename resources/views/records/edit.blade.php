@extends('layouts.app')

@section('title', '記録を直す')

{{-- 記録の編集（画面設計書 5.4）。入力欄は、登録と同じ Livewire の部品（app/Livewire/RecordForm.php） --}}
@section('content')
    {{-- 見出しと「‹ やめる」は、戻る先を知っている部品の中に置く --}}
    <livewire:record-form :record="$record" />
@endsection
