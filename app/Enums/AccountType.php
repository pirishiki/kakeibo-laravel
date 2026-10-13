<?php

namespace App\Enums;

/**
 * 口座の種類（データベース設計書 3.2）。並べる順番は、ここに書いた順（画面設計書 5.6）
 */
enum AccountType: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case TransitIc = 'transit_ic';
    case CreditCard = 'credit_card';

    /** 画面に出す名前 */
    public function label(): string
    {
        return match ($this) {
            self::Bank => '銀行口座',
            self::Cash => '現金',
            self::TransitIc => '交通系 IC',
            self::CreditCard => 'クレジットカード',
        };
    }
}
