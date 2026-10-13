<?php

// 入力チェックのまちがいの文。使うチェックの分だけを書き、機能を作るたびに足す（画面設計書 4 章の 10）。
// ここにないチェックは、Laravel の英語の文が出る。

return [

    'boolean' => ':attributeは、チェックをつけるか外すかで選んでください。',
    'confirmed' => ':attributeが 2 回目と合っていません。',
    'email' => ':attributeの形がちがいます。',
    'enum' => ':attributeは、出ている中から選んでください。',
    'exists' => ':attributeは、出ている中から選んでください。',
    'integer' => ':attributeは、小数のない数で入れてください。',
    'max' => [
        'string' => ':attributeは :max 文字以内で入れてください。',
    ],
    'min' => [
        'string' => ':attributeは :min 文字以上で入れてください。',
    ],
    'required' => ':attributeを入れてください。',
    'string' => ':attributeは文字で入れてください。',
    'unique' => 'この:attributeは、もう使われています。',

    // 欄ごとに文を変えるもの（金額は、けたが読みやすいようにカンマをつけて書く）
    'custom' => [
        'initial_balance' => [
            'between' => '最初の残高は -99,999,999〜99,999,999 円で入れてください。',
        ],
        'merge_target' => [
            'exists' => 'まとめ先は、出てくる相手から選んでください。',
        ],
    ],

    // 欄の名前（:attribute に入る）
    'attributes' => [
        'email' => 'メールアドレス',
        'initial_balance' => '最初の残高',
        'is_active' => '使う',
        'kind' => '収入用・支出用',
        'merge_target' => 'まとめ先',
        'name' => '名前',
        'parent_id' => '親の大分類',
        'password' => 'パスワード',
        'type' => '種類',
    ],

];
