<?php

namespace App\Enums;

/**
 * 定期的な記録の日付が土日・祝日のときのずらし方（データベース設計書 3.6）
 */
enum RecurringShift: string
{
    case Previous = 'previous';
    case Next = 'next';

    public function label(): string
    {
        return match ($this) {
            self::Previous => '前の平日',
            self::Next => '次の平日',
        };
    }

    /** 最初の値：収入なら前の平日、支出・移動なら次の平日（画面設計書 5.10） */
    public static function defaultFor(RecordType $type): self
    {
        return $type === RecordType::Income ? self::Previous : self::Next;
    }
}
