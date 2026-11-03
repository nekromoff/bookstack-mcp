<?php

namespace BookStackMcp;

class Config
{
    public const VERSION = '0.1.0';
    public const SCOPE = 'read';

    /**
     * The canonical URL of the MCP endpoint, used as the OAuth resource identifier.
     */
    public static function resourceUrl(): string
    {
        return url('/mcp');
    }

    public static function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    public static function protectedResourceMetadataUrl(): string
    {
        return url('/.well-known/oauth-protected-resource/mcp');
    }

    public static function accessTokenTtl(): int
    {
        return max(60, (int) env('MCP_ACCESS_TOKEN_TTL', 3600));
    }

    public static function refreshTokenTtl(): int
    {
        return max(3600, (int) env('MCP_REFRESH_TOKEN_TTL', 60 * 60 * 24 * 30));
    }

    public static function dynamicRegistrationEnabled(): bool
    {
        return filter_var(env('MCP_DYNAMIC_REGISTRATION', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * Hosts that client redirect URIs may point to. Empty means any host.
     *
     * @return string[]
     */
    public static function allowedRedirectHosts(): array
    {
        $hosts = explode(',', strtolower((string) env('MCP_ALLOWED_REDIRECT_HOSTS', '')));

        return array_values(array_filter(array_map('trim', $hosts)));
    }

    /**
     * Max characters returned for a single tool result, to keep responses
     * within what an AI assistant can usefully take in.
     */
    public static function maxResultChars(): int
    {
        return max(10000, (int) env('MCP_MAX_RESULT_CHARS', 200000));
    }
}
