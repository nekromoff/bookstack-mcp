<?php

namespace BookStackMcp\OAuth;

use BookStackMcp\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OAuth clients (AI apps) registered via RFC 7591 dynamic client registration.
 * All clients are public clients secured by PKCE; none hold a secret.
 */
class Clients
{
    protected const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];
    protected const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'about', 'blob'];

    /**
     * @param string[] $redirectUris
     */
    public function register(string $name, array $redirectUris): object
    {
        $now = Carbon::now();
        $client = [
            'client_id'     => 'mcp_' . Str::random(32),
            'client_name'   => Str::limit(trim($name) ?: 'Unnamed MCP client', 180, ''),
            'redirect_uris' => json_encode(array_values($redirectUris)),
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        DB::table('mcp_oauth_clients')->insert($client);

        return $this->find($client['client_id']);
    }

    public function find(string $clientId): ?object
    {
        if ($clientId === '') {
            return null;
        }

        $client = DB::table('mcp_oauth_clients')->where('client_id', '=', $clientId)->first();
        if ($client) {
            $client->redirect_uris = json_decode($client->redirect_uris, true) ?: [];
        }

        return $client;
    }

    /**
     * Check a redirect URI is acceptable for registration.
     * Returns an error description, or null if acceptable.
     */
    public function validateRedirectUri(mixed $uri): ?string
    {
        if (!is_string($uri) || strlen($uri) > 2000) {
            return 'Redirect URIs must be strings of a reasonable length';
        }

        $parts = parse_url($uri);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!$scheme || isset($parts['fragment'])) {
            return "Redirect URI \"{$uri}\" must be absolute and must not contain a fragment";
        }

        if (in_array($scheme, self::BLOCKED_SCHEMES) || !preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme)) {
            return "Redirect URI scheme \"{$scheme}\" is not allowed";
        }

        $host = strtolower($parts['host'] ?? '');
        $isLoopback = in_array($host, self::LOOPBACK_HOSTS);

        if ($scheme === 'http' && !$isLoopback) {
            return 'Plain http redirect URIs are only allowed for localhost';
        }

        if (in_array($scheme, ['http', 'https']) && !$isLoopback) {
            $allowedHosts = Config::allowedRedirectHosts();
            if (count($allowedHosts) > 0 && !in_array($host, $allowedHosts)) {
                return "Redirect host \"{$host}\" is not on this instance's allow list";
            }
        }

        return null;
    }

    /**
     * Check the given redirect URI was registered for the client.
     * Loopback URIs may use any port, as per RFC 8252 section 7.3.
     */
    public function redirectUriMatches(object $client, string $uri): bool
    {
        foreach ($client->redirect_uris as $registered) {
            if (hash_equals($registered, $uri)) {
                return true;
            }

            if ($this->withoutLoopbackPort($registered) === $this->withoutLoopbackPort($uri)) {
                return true;
            }
        }

        return false;
    }

    protected function withoutLoopbackPort(string $uri): ?string
    {
        $parts = parse_url($uri);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'http' || !in_array($host, self::LOOPBACK_HOSTS)) {
            return null;
        }

        return 'http://' . $host . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public function deleteUnused(Carbon $olderThan): int
    {
        return DB::table('mcp_oauth_clients')
            ->where('created_at', '<', $olderThan)
            ->whereNotIn('client_id', DB::table('mcp_oauth_tokens')->select('client_id'))
            ->delete();
    }
}
