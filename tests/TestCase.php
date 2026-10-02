<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without built frontend assets (CI doesn't run npm)
        $this->withoutVite();

        // Tests use their own Redis database (REDIS_DB in phpunit.xml), start each one empty
        Redis::flushdb();
    }

    /**
     * @return array<string, string>
     */
    protected function basicAuth(string $user = 'admin', string $password = 'secret'): array
    {
        return ['Authorization' => 'Basic '.base64_encode("{$user}:{$password}")];
    }
}
