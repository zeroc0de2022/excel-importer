<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_urls_use_https_behind_a_trusted_tls_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.1']);

        $this->assertStringStartsWith('https://', $this->reportUrlVia('10.0.0.1'));
    }

    public function test_forwarded_headers_from_other_addresses_are_ignored(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.1']);

        $this->assertStringStartsWith('http://', $this->reportUrlVia('203.0.113.9'));
    }

    private function reportUrlVia(string $remoteAddress): string
    {
        $import = Import::create([
            'file_name' => 'x.xlsx',
            'file_path' => 'x',
            'status' => ImportStatus::Completed,
            'report_path' => 'imports/x/result.txt',
        ]);

        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->getJson("/api/imports/{$import->id}", $this->basicAuth() + ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->json('data.report_url');
    }
}
