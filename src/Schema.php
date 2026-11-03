<?php

namespace BookStackMcp;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * Theme modules can't ship migrations, so the module manages its own tables.
 */
class Schema
{
    protected const VERSION = 1;

    public static function ensure(): void
    {
        $cacheKey = 'bookstack-mcp:schema:v' . self::VERSION;
        if (Cache::get($cacheKey)) {
            return;
        }

        self::install();
        Cache::forever($cacheKey, true);
    }

    public static function install(): void
    {
        if (!SchemaFacade::hasTable('mcp_oauth_clients')) {
            SchemaFacade::create('mcp_oauth_clients', function (Blueprint $table) {
                $table->increments('id');
                $table->string('client_id', 64)->unique();
                $table->string('client_name', 191);
                $table->text('redirect_uris');
                $table->timestamps();
            });
        }

        if (!SchemaFacade::hasTable('mcp_oauth_tokens')) {
            SchemaFacade::create('mcp_oauth_tokens', function (Blueprint $table) {
                $table->increments('id');
                $table->string('client_id', 64)->index();
                $table->unsignedInteger('user_id')->index();
                $table->string('access_token_hash', 64)->unique();
                $table->string('refresh_token_hash', 64)->unique();
                $table->string('scope', 191);
                $table->string('resource', 191);
                $table->timestamp('access_expires_at')->nullable();
                $table->timestamp('refresh_expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public static function uninstall(): void
    {
        SchemaFacade::dropIfExists('mcp_oauth_tokens');
        SchemaFacade::dropIfExists('mcp_oauth_clients');
        Cache::forget('bookstack-mcp:schema:v' . self::VERSION);
    }
}
