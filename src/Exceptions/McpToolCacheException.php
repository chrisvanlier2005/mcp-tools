<?php

namespace Lier\McpTools\Exceptions;

use RuntimeException;
use Throwable;

final class McpToolCacheException extends RuntimeException
{
    /**
     * Create a new exception instance.
     *
     * @param list<\Throwable> $errors
     * @return self
     */
    public static function buildFailed(array $errors): self
    {
        return new self(implode(PHP_EOL, array_map(fn (Throwable $error) => $error->getMessage(), $errors)));
    }

    /**
     * Create a new exception instance.
     *
     * @param string $path
     * @return self
     */
    public static function unwritable(string $path): self
    {
        return new self("Unable to write the MCP tool cache at [{$path}].");
    }

    /**
     * Create a new exception instance.
     *
     * @param string $tool
     * @return self
     */
    public static function staleRoute(string $tool): self
    {
        return new self(
            "The cached MCP tool [{$tool}] no longer matches its route. "
            . 'Run `php artisan mcp-tools:cache` after changing routes.',
        );
    }
}
