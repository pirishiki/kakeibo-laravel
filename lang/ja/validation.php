<?php

// 入力チェックのまちがいの文。使うチェックの分だけを書き、機能を作るたびに足す。
// ここにないチェックは、Laravel の英語の文が出る。

return [

    'email' => ':attributeの形がちがいます。',
    'required' => ':attributeを入れてください。',
    'string' => ':attributeは文字で入れてください。',

    // 欄の名前（:attribute に入る）
    'attributes' => [
        'email' => 'メールアドレス',
        'password' => 'パスワード',
    ],

];
