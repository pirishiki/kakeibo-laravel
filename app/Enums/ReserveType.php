<?php

namespace App\Enums;

/**
 * 取っておく額の種類（データベース設計書 3.2、画面設計書 5.6）
 */
enum ReserveType: string
{
    case None = 'none';
    case Amount = 'amount';
    case All = 'all';

    /** 口座の追加・変更の画面に出す名前 */
    public function label(): string
    {
        return match ($this) {
            self::None => 'なし',
            self::Amount => '金額',
            self::All => '全部取っておく',
        };
    }
}
