<?php

namespace BookStackMcp\Commands;

use BookStack\Users\Models\User;
use BookStackMcp\OAuth\Clients;
use BookStackMcp\OAuth\Tokens;
use BookStackMcp\Schema;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneCommand extends Command
{
    protected $signature = 'bookstack-mcp:prune
                            {--user= : Disconnect all MCP clients for the user with this ID or email}';

    protected $description = 'Remove expired MCP tokens and unused clients, or disconnect a user';

    public function handle(Tokens $tokens, Clients $clients): int
    {
        Schema::ensure();

        $userOption = $this->option('user');
        if ($userOption) {
            $user = User::query()->where('id', '=', $userOption)->orWhere('email', '=', $userOption)->first();
            if (!$user) {
                $this->error("User \"{$userOption}\" not found.");
                return self::FAILURE;
            }

            $tokens->revokeForUser($user->id);
            $this->info("Disconnected all MCP clients for {$user->name}.");

            return self::SUCCESS;
        }

        $expired = $tokens->deleteExpired();
        $unused = $clients->deleteUnused(Carbon::now()->subDay());
        $this->info("Removed {$expired} expired token(s) and {$unused} unused client(s).");

        return self::SUCCESS;
    }
}
