<?php

namespace App\Enums;

/**
 * 取っておく額の種類（データベース設計書 3.2）。画面に出すのは第 2 段階で、それまではいつも「なし」
 */
enum ReserveType: string
{
    case None = 'none';
    case Amount = 'amount';
    case All = 'all';
}
