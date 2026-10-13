<?php

namespace App\Enums;

/**
 * 分類が収入用か支出用か（データベース設計書 3.3）。一覧に出す順番は、ここに書いた順（支出 → 収入。画面設計書 5.8）
 */
enum CategoryKind: string
{
    case Expense = 'expense';
    case Income = 'income';

    /** 画面に出す名前（「支出の分類」「支出用」などに使う） */
    public function label(): string
    {
        return match ($this) {
            self::Expense => '支出',
            self::Income => '収入',
        };
    }
}
