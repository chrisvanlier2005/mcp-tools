<?php

namespace Lier\McpTools;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Lier\McpTools\Console\CacheCommand;
use Lier\McpTools\Console\ClearCommand;
use Override;
use Webmozart\Assert\Assert;

final class McpToolsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mcp-tools.php', 'mcp-tools');

        $this->app->singleton(RouteToolCache::class, function () {
            return new RouteToolCache($this->app->bootstrapPath('cache/mcp-tools.php'));
        });

        $this->app->singleton(RouteToolDiscovery::class, function () {
            $config = $this->app->make(Repository::class);
            $documentPath = $config->get('mcp-tools.openapi_path');
            $toolClass = $config->get('mcp-tools.tool') ?? RouteTool::class;

            Assert::string($toolClass);
            Assert::true(is_a($toolClass, RouteTool::class, true), 'The mcp-tools.tool class must extend RouteTool.');

            return new RouteToolDiscovery(
                router: $this->app->make(Router::class),
                cache: $this->app->make(RouteToolCache::class),
                documentPath: is_string($documentPath) && $documentPath !== ''
                    ? $documentPath
                    : $this->app->basePath(Assert::string($config->get('scramble.export_path', 'api.json'))),
                // Tests discover the tools from the current code, rather than from a local cache.
                useCache: !$this->app->runningUnitTests(),
                toolClass: $toolClass,
            );
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__ . '/../config/mcp-tools.php' => config_path('mcp-tools.php')], 'mcp-tools-config');

        $this->commands([CacheCommand::class, ClearCommand::class]);

        $this->optimizes(optimize: 'mcp-tools:cache', clear: 'mcp-tools:clear', key: 'mcp-tools');
    }
}
