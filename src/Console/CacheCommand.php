<?php

namespace Lier\McpTools\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Lier\McpTools\Exceptions\McpToolCacheException;
use Lier\McpTools\RouteToolDiscovery;

#[Signature('mcp-tools:cache')]
#[Description('Cache the MCP tools of the routes annotated with #[McpTool]')]
final class CacheCommand extends Command
{
    /**
     * Execute the console command.
     *
     * @param \Lier\McpTools\RouteToolDiscovery $routeToolDiscovery
     * @return int
     */
    public function handle(RouteToolDiscovery $routeToolDiscovery): int
    {
        try {
            $count = $routeToolDiscovery->cache();
        } catch (McpToolCacheException $exception) {
            $this->components->error('Could not cache the MCP route tools; the previous cache is kept.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Cached {$count} MCP route tools.");

        return self::SUCCESS;
    }
}
