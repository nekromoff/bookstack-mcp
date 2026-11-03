<?php

namespace BookStackMcp\OAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Short-lived, single-use authorization codes, kept in the app cache.
 */
class AuthCodes
{
    protected const TTL_SECONDS = 300;

    /**
     * @param array{client_id: string, user_id: int, redirect_uri: string, code_challenge: string, scope: string, resource: string} $data
     */
    public function create(array $data): string
    {
        $code = Str::random(64);
        Cache::put($this->cacheKey($code), $data, self::TTL_SECONDS);

        return $code;
    }

    public function consume(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        return Cache::pull($this->cacheKey($code));
    }

    protected function cacheKey(string $code): string
    {
        return 'bookstack-mcp:code:' . hash('sha256', $code);
    }
}
