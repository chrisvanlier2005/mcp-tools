<?php

namespace Lier\McpTools;

use Lier\McpTools\Exceptions\McpToolCacheException;
use Webmozart\Assert\Assert;

/**
 * The cached route tool definitions, written by `php artisan mcp-tools:cache`.
 *
 * The cache is a PHP file returning an array, so opcache keeps it in memory.
 */
final readonly class RouteToolCache
{
    /**
     * Create a new route tool cache instance.
     *
     * @param string $path
     * @return void
     */
    public function __construct(
        private string $path,
    ) {
    }

    /**
     * Get the cached definitions.
     *
     * @return list<\Lier\McpTools\RouteToolDefinition>|null
     */
    public function get(): ?array
    {
        if (!file_exists($this->path)) {
            return null;
        }

        $definitions = require $this->path;

        Assert::isList($definitions);
        Assert::allIsMap($definitions);

        return array_map(RouteToolDefinition::fromArray(...), $definitions);
    }

    /**
     * Cache the definitions.
     *
     * @param list<\Lier\McpTools\RouteToolDefinition> $definitions
     * @return void
     * @throws \Lier\McpTools\Exceptions\McpToolCacheException
     */
    public function put(array $definitions): void
    {
        $definitions = array_map(fn (RouteToolDefinition $definition) => $definition->toArray(), $definitions);

        // Write to a temporary file first, so MCP requests never read a partially written cache.
        $temporaryPath = $this->path . '.tmp';

        $contents = '<?php return ' . var_export($definitions, true) . ';' . PHP_EOL;

        if (file_put_contents($temporaryPath, $contents) === false || !rename($temporaryPath, $this->path)) {
            throw McpToolCacheException::unwritable($this->path);
        }
    }

    /**
     * Remove the cached definitions.
     *
     * @return void
     * @throws \Lier\McpTools\Exceptions\McpToolCacheException
     */
    public function clear(): void
    {
        if (file_exists($this->path) && !unlink($this->path)) {
            throw McpToolCacheException::unwritable($this->path);
        }
    }
}
