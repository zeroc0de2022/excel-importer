<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BasicAuthTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function protectedRoutes(): array
    {
        return [
            'upload page' => ['GET', '/'],
            'rows page' => ['GET', '/rows'],
            'import list' => ['GET', '/api/imports'],
            'upload' => ['POST', '/api/imports'],
            'rows api' => ['GET', '/api/rows'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_requires_credentials(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Excel Importer"');
    }

    public function test_rejects_wrong_password(): void
    {
        $this->getJson('/api/imports', $this->basicAuth('admin', 'wrong'))->assertUnauthorized();
    }

    public function test_accepts_valid_credentials(): void
    {
        $this->get('/', $this->basicAuth())->assertOk();
    }

    public function test_health_check_is_public(): void
    {
        $this->get('/up')->assertOk();
    }
}
