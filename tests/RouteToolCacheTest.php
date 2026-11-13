<?php

namespace Lier\McpTools\Tests;

use ErrorException;
use Lier\McpTools\Exceptions\McpToolCacheException;
use Lier\McpTools\McpTool;
use Lier\McpTools\OpenApi\Schema;
use Lier\McpTools\RouteToolCache;
use Lier\McpTools\RouteToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

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

    private function temporaryPath(): string
    {
        return sys_get_temp_dir() . '/mcp-tools-' . bin2hex(random_bytes(4)) . '.php';
    }

    /**
     * Mimic Laravel's error handler, which the cache relies on to turn failed file operations into exceptions.
     */
    private function raiseWarningsAsExceptions(): void
    {
        set_error_handler(fn (int $level, string $message) => throw new ErrorException($message, 0, $level));
    }

    /**
     * @param non-empty-string $name
     */
    private function definition(string $name = 'get-tasks'): RouteToolDefinition
    {
        return new RouteToolDefinition('A@b', 'tasks', 'GET', new McpTool($name), 'Tasks', 'Tasks.', new Schema(), null);
    }

    public function testItCachesAnEmptyList(): void
    {
        $path = $this->temporaryPath();
        $cache = new RouteToolCache($path);

        try {
            $cache->put([]);

            $this->assertSame([], $cache->get());
        } finally {
            $cache->clear();
        }
    }

    public function testItReplacesAnExistingCacheWithoutLeavingTemporaryFiles(): void
    {
        $path = $this->temporaryPath();
        $cache = new RouteToolCache($path);

        try {
            $cache->put([$this->definition('first')]);
            $cache->put([$this->definition('second')]);

            $names = array_map(fn (RouteToolDefinition $definition) => $definition->attribute->name, $cache->get() ?? []);

            $this->assertSame(['second'], $names);
            $this->assertSame([$path], glob($path . '*'), 'Only the cache file remains.');
        } finally {
            $cache->clear();
        }
    }

    public function testItWritesAFileTheWebServerCanRead(): void
    {
        $path = $this->temporaryPath();
        $cache = new RouteToolCache($path);

        try {
            $cache->put([]);

            $this->assertSame(0666 & ~umask(), fileperms($path) & 0777);
            $this->assertStringStartsWith('<?php return ', (string) file_get_contents($path));
        } finally {
            $cache->clear();
        }
    }

    public function testClearingAMissingCacheSucceeds(): void
    {
        new RouteToolCache($this->temporaryPath())->clear();

        $this->addToAssertionCount(1);
    }

    public function testItFailsToClearACacheThatIsADirectory(): void
    {
        $path = $this->temporaryPath();
        mkdir($path);
        $this->raiseWarningsAsExceptions();

        try {
            $this->expectException(McpToolCacheException::class);

            new RouteToolCache($path)->clear();
        } finally {
            restore_error_handler();
            rmdir($path);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidContents(): iterable
    {
        yield 'associative array' => ['<?php return ["a" => []];'];
        yield 'non list' => ['<?php return [1 => []];'];
        yield 'list of scalars' => ['<?php return ["x"];'];
        yield 'scalar' => ['<?php return "nope";'];
        yield 'null' => ['<?php return null;'];
        yield 'invalid definition' => ['<?php return [["action" => "a"]];'];
    }

    #[DataProvider('invalidContents')]
    public function testItRejectsAFileWithInvalidContents(string $contents): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, $contents);

        try {
            $this->expectException(Throwable::class);

            new RouteToolCache($path)->get();
        } finally {
            unlink($path);
        }
    }

    public function testItFailsWhenTheDirectoryIsNotWritable(): void
    {
        $directory = sys_get_temp_dir() . '/mcp-tools-dir-' . bin2hex(random_bytes(4));
        mkdir($directory, 0555);

        try {
            if (is_writable($directory)) {
                $this->markTestSkipped('Directory permissions are not enforced, e.g. when running as root.');
            }

            $this->expectException(McpToolCacheException::class);

            new RouteToolCache($directory . '/mcp-tools.php')->put([$this->definition()]);
        } finally {
            chmod($directory, 0755);
            rmdir($directory);
        }
    }

    public function testItFailsWhenTheTargetCantBeReplaced(): void
    {
        // Renaming a file onto a directory fails; the temporary file must not be left behind.
        $directory = sys_get_temp_dir() . '/mcp-tools-dir-' . bin2hex(random_bytes(4));
        mkdir($directory);
        mkdir($directory . '/target');
        $this->raiseWarningsAsExceptions();

        try {
            try {
                new RouteToolCache($directory . '/target')->put([]);

                $this->fail('Expected an exception.');
            } catch (McpToolCacheException) {
                $this->assertSame(['target'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
            }
        } finally {
            restore_error_handler();
            rmdir($directory . '/target');
            rmdir($directory);
        }
    }
}
