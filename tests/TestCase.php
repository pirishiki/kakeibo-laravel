<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // テストでは、画面用のファイル（npm run build で作る CSS・JS）を読まない
        // CI のテストでは画面のビルドをしないので、読もうとするとエラーになるため（ビルドは CI の build で確かめる）
        $this->withoutVite();
    }
}
