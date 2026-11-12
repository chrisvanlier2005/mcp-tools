<?php

namespace Lier\McpTools\Tests;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Lier\McpTools\McpToolsServiceProvider;
use Lier\McpTools\RouteTool;
use Lier\McpTools\RouteToolCache;
use Lier\McpTools\RouteToolDiscovery;
use Lier\McpTools\Tests\Fixtures\RestrictedRouteTool;
use ReflectionProperty;
use stdClass;

final class McpToolsServiceProviderTest extends TestCase
{
    private function property(object $object, string $name): mixed
    {
        return new ReflectionProperty($object, $name)->getValue($object);
    }

    private function discovery(): RouteToolDiscovery
    {
        app()->forgetInstance(RouteToolDiscovery::class);

        return app(RouteToolDiscovery::class);
    }

    public function testItMergesTheDefaultConfig(): void
    {
        $this->assertSame(RouteTool::class, config('mcp-tools.tool'));
        $this->assertArrayHasKey('guard', (array) config('mcp-tools'));
    }

    public function testItBindsOneCacheAtTheBootstrapPath(): void
    {
        $this->assertSame(app(RouteToolCache::class), app(RouteToolCache::class));
        $this->assertSame(app()->bootstrapPath('cache/mcp-tools.php'), $this->property(app(RouteToolCache::class), 'path'));
    }

    public function testItRejectsAToolClassThatIsNotARouteTool(): void
    {
        config(['mcp-tools.tool' => stdClass::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The mcp-tools.tool class must extend RouteTool.');

        $this->discovery();
    }

    public function testItRejectsAToolClassThatIsNotAString(): void
    {
        config(['mcp-tools.tool' => 42]);

        $this->expectException(InvalidArgumentException::class);

        $this->discovery();
    }

    public function testItAcceptsSubclassesAndDefaultsToRouteTool(): void
    {
        config(['mcp-tools.tool' => RestrictedRouteTool::class]);
        $this->assertSame(RestrictedRouteTool::class, $this->property($this->discovery(), 'toolClass'));

        config(['mcp-tools.tool' => null]);
        $this->assertSame(RouteTool::class, $this->property($this->discovery(), 'toolClass'));
    }

    public function testItUsesTheConfiguredDocumentPath(): void
    {
        $this->assertSame(__DIR__ . '/Fixtures/openapi.json', $this->property($this->discovery(), 'documentPath'));
    }

    public function testItFallsBackToScramblesExportPath(): void
    {
        foreach ([null, ''] as $path) {
            config(['mcp-tools.openapi_path' => $path, 'scramble.export_path' => 'docs/scramble.json']);

            $this->assertSame(app()->basePath('docs/scramble.json'), $this->property($this->discovery(), 'documentPath'));
        }
    }

    public function testItFallsBackToApiJsonWithoutScramble(): void
    {
        config(['mcp-tools.openapi_path' => null]);

        $this->assertSame(app()->basePath('api.json'), $this->property($this->discovery(), 'documentPath'));
    }

    public function testItIgnoresTheCacheWhileTesting(): void
    {
        $this->assertFalse($this->property($this->discovery(), 'useCache'));
    }

    public function testItPublishesTheConfigUnderItsTag(): void
    {
        $paths = ServiceProvider::pathsToPublish(McpToolsServiceProvider::class, 'mcp-tools-config');

        $this->assertSame([config_path('mcp-tools.php')], array_values($paths));
        $this->assertFileExists((string) array_key_first($paths));
        $this->assertSame([], ServiceProvider::pathsToPublish(McpToolsServiceProvider::class, 'other-tag'));
    }

    public function testItHooksIntoOptimize(): void
    {
        $this->assertSame('mcp-tools:cache', ServiceProvider::$optimizeCommands['mcp-tools'] ?? null);
        $this->assertSame('mcp-tools:clear', ServiceProvider::$optimizeClearCommands['mcp-tools'] ?? null);
    }
}
