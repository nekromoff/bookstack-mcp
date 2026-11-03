<?php

namespace BookStackMcp\OAuth;

use BookStackMcp\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Access & refresh tokens. Only SHA-256 hashes of tokens are stored.
 * Refresh tokens are rotated on every use.
 */
class Tokens
{
    protected const TABLE = 'mcp_oauth_tokens';

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    public function issue(string $clientId, int $userId, string $scope, string $resource): array
    {
        $now = Carbon::now();
        $accessToken = 'bsmcp_at_' . Str::random(48);
        $refreshToken = 'bsmcp_rt_' . Str::random(48);

        DB::table(self::TABLE)->insert([
            'client_id'          => $clientId,
            'user_id'            => $userId,
            'access_token_hash'  => $this->hash($accessToken),
            'refresh_token_hash' => $this->hash($refreshToken),
            'scope'              => $scope,
            'resource'           => $resource,
            'access_expires_at'  => $now->copy()->addSeconds(Config::accessTokenTtl()),
            'refresh_expires_at' => $now->copy()->addSeconds(Config::refreshTokenTtl()),
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        return [
            'access_token'  => $accessToken,
            'token_type'    => 'Bearer',
            'expires_in'    => Config::accessTokenTtl(),
            'refresh_token' => $refreshToken,
            'scope'         => $scope,
        ];
    }

    /**
     * Find the unexpired token record for the given access token.
     */
    public function findByAccessToken(string $accessToken): ?object
    {
        if ($accessToken === '') {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('access_token_hash', '=', $this->hash($accessToken))
            ->where('access_expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Find the unexpired token record for the given refresh token & client.
     */
    public function findByRefreshToken(string $refreshToken, string $clientId): ?object
    {
        if ($refreshToken === '') {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('refresh_token_hash', '=', $this->hash($refreshToken))
            ->where('client_id', '=', $clientId)
            ->where('refresh_expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Replace the given token record with a newly issued pair.
     */
    public function rotate(object $token): array
    {
        $this->delete($token->id);

        return $this->issue($token->client_id, $token->user_id, $token->scope, $token->resource);
    }

    public function touch(object $token): void
    {
        $lastUsed = $token->last_used_at ? Carbon::parse($token->last_used_at) : null;
        if ($lastUsed && $lastUsed->gt(Carbon::now()->subMinutes(5))) {
            return;
        }

        DB::table(self::TABLE)->where('id', '=', $token->id)->update(['last_used_at' => Carbon::now()]);
    }

    public function delete(int $id): void
    {
        DB::table(self::TABLE)->where('id', '=', $id)->delete();
    }

    /**
     * Revoke the record matching either an access or refresh token.
     */
    public function revokeByToken(string $token): void
    {
        $hash = $this->hash($token);
        DB::table(self::TABLE)
            ->where('access_token_hash', '=', $hash)
            ->orWhere('refresh_token_hash', '=', $hash)
            ->delete();
    }

    public function revokeForUserAndClient(int $userId, string $clientId): void
    {
        DB::table(self::TABLE)
            ->where('user_id', '=', $userId)
            ->where('client_id', '=', $clientId)
            ->delete();
    }

    public function revokeForUser(int $userId): void
    {
        DB::table(self::TABLE)->where('user_id', '=', $userId)->delete();
    }

    /**
     * Active connections for a user, one entry per client.
     */
    public function connectionsForUser(int $userId): Collection
    {
        return DB::table(self::TABLE . ' as t')
            ->leftJoin('mcp_oauth_clients as c', 'c.client_id', '=', 't.client_id')
            ->where('t.user_id', '=', $userId)
            ->where('t.refresh_expires_at', '>', Carbon::now())
            ->groupBy('t.client_id', 'c.client_name')
            ->select([
                't.client_id',
                'c.client_name',
                DB::raw('MIN(t.created_at) as created_at'),
                DB::raw('MAX(t.last_used_at) as last_used_at'),
                DB::raw('MAX(t.refresh_expires_at) as expires_at'),
            ])
            ->orderBy('c.client_name')
            ->get();
    }

    public function deleteExpired(): int
    {
        return DB::table(self::TABLE)->where('refresh_expires_at', '<=', Carbon::now())->delete();
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
