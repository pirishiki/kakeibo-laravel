<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 本番で CloudFront の後ろで動かすための設定（全体構成設計書 2 章）
 */
class ProductionSettingsTest extends TestCase
{
    public function test_本番ではLaravelが作るURLがいつもhttpsになる(): void
    {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', route('login'));
    }

    public function test_本番でないときはhttpsにしない(): void
    {
        $this->assertStringStartsWith('http://', route('login'));
    }

    public function test_CloudFrontがつけるX_Forwarded_Forから_使っている人のIPアドレスを取る(): void
    {
        Route::get('/_test/ip', fn () => request()->ip());

        // CloudFront（10.0.1.23 からつながってくる）が、使っている人の IP（203.0.113.5）をつけて渡す
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.23'])
            ->withHeader('X-Forwarded-For', '203.0.113.5')
            ->get('/_test/ip')
            ->assertSeeText('203.0.113.5');
    }
}
