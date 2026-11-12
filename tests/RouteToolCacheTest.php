<?php

namespace Lier\McpTools\Tests;

use Lier\McpTools\Exceptions\McpToolCacheException;
use Lier\McpTools\McpTool;
use Lier\McpTools\OpenApi\Schema;
use Lier\McpTools\RouteToolCache;
use Lier\McpTools\RouteToolDefinition;
use PHPUnit\Framework\TestCase;

final class RouteToolCacheTest extends TestCase
{
    public function testItHasNothingCachedWithoutAFile(): void
    {
        $this->assertNull(new RouteToolCache(sys_get_temp_dir() . '/missing-mcp-tools.php')->get());
    }

    public function testItCachesAndClearsDefinitions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-tools');
        unlink($path);

        $cache = new RouteToolCache($path);

        $definition = new RouteToolDefinition(
            action: 'Lier\McpTools\Tests\Fixtures\WidgetController@index',
            uri: 'households/{household}/task-categories',
            method: 'GET',
            attribute: new McpTool('get-task-categories', hideOutput: ['authorizations'], readOnly: true),
            title: 'List task categories',
            description: 'Retrieve the task categories.',
            inputSchema: new Schema(['type' => 'object', 'properties' => []]),
            outputSchema: null,
        );

        try {
            $cache->put([$definition]);

            $this->assertFileDoesNotExist("{$path}.tmp");
            $this->assertEquals([$definition], $cache->get());
        } finally {
            $cache->clear();
        }

        $this->assertFileDoesNotExist($path);
        $this->assertNull($cache->get());
    }

    public function testItFailsWhenTheCacheCantBeWritten(): void
    {
        $this->expectException(McpToolCacheException::class);

        new RouteToolCache(sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(4)) . '/mcp-tools.php')->put([]);
    }
}
