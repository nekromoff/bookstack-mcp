<?php

namespace BookStackMcp\Commands;

use BookStackMcp\Schema;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'bookstack-mcp:install {--uninstall : Drop the MCP tables, disconnecting all clients}';

    protected $description = 'Create (or drop) the database tables used by the MCP module';

    public function handle(): int
    {
        if ($this->option('uninstall')) {
            if (!$this->confirm('This removes all MCP clients and tokens. Continue?')) {
                return self::FAILURE;
            }

            Schema::uninstall();
            $this->info('MCP tables dropped.');

            return self::SUCCESS;
        }

        Schema::install();
        Schema::ensure();
        $this->info('MCP tables are ready. MCP endpoint: ' . url('/mcp'));

        return self::SUCCESS;
    }
}
