<?php

declare(strict_types=1);

return [

    // Reverse proxies allowed to set X-Forwarded-* headers (comma-separated IPs, or "*").
    // In production TLS ends at an edge proxy, so Laravel must trust it to know the request was HTTPS.
    'proxies' => env('TRUSTED_PROXIES'),

];
