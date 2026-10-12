<?php

// 入力チェックのまちがいの文。使うチェックの分だけを書き、機能を作るたびに足す（画面設計書 4 章の 10）。
// ここにないチェックは、Laravel の英語の文が出る。

return [

    'confirmed' => ':attributeが 2 回目と合っていません。',
    'email' => ':attributeの形がちがいます。',
    'max' => [
        'string' => ':attributeは :max 文字以内で入れてください。',
    ],
    'min' => [
        'string' => ':attributeは :min 文字以上で入れてください。',
    ],
    'required' => ':attributeを入れてください。',
    'string' => ':attributeは文字で入れてください。',
    'unique' => 'この:attributeは、もう使われています。',

    // 欄の名前（:attribute に入る）
    'attributes' => [
        'email' => 'メールアドレス',
        'name' => '名前',
        'password' => 'パスワード',
    ],

];
