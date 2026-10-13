<?php

namespace App\Enums;

/**
 * 記録の種類（データベース設計書 3.5）
 */
enum RecordType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';
}
