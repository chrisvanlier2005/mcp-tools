<?php

namespace Lier\McpTools\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Lier\McpTools\Exceptions\McpToolCacheException;
use Lier\McpTools\RouteToolCache;

#[Signature('mcp-tools:clear')]
#[Description('Remove the cached MCP tools')]
final class ClearCommand extends Command
{
    /**
     * Execute the console command.
     *
     * @param \Lier\McpTools\RouteToolCache $routeToolCache
     * @return int
     */
    public function handle(RouteToolCache $routeToolCache): int
    {
        try {
            $routeToolCache->clear();
        } catch (McpToolCacheException $exception) {
            $this->components->error('Could not clear the cached MCP route tools.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Cleared the cached MCP route tools.');

        return self::SUCCESS;
    }
}
