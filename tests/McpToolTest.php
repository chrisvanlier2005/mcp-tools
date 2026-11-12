<?php

namespace Lier\McpTools\Tests;

use Lier\McpTools\McpTool;
use PHPUnit\Framework\TestCase;

final class McpToolTest extends TestCase
{
    public function testItDerivesAnnotationsFromTheHttpMethod(): void
    {
        $tool = new McpTool('tool');

        $this->assertSame(['readOnlyHint' => true], $tool->annotations('GET'));
        $this->assertSame(['destructiveHint' => true], $tool->annotations('DELETE'));
        $this->assertSame([], $tool->annotations('POST'));
        $this->assertSame([], $tool->annotations('PUT'));
    }

    public function testItOverridesAnnotations(): void
    {
        $tool = new McpTool('tool', readOnly: true, destructive: false, idempotent: true, openWorld: false);

        $this->assertSame([
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ], $tool->annotations('DELETE'));

        $this->assertSame(['readOnlyHint' => false], new McpTool('tool', readOnly: false)->annotations('GET'));
    }

    public function testItCombinesTheAnnotationOverridesWithTheMethodDefaults(): void
    {
        $cases = [
            ['GET', new McpTool('tool', destructive: true), ['readOnlyHint' => true, 'destructiveHint' => true]],
            ['GET', new McpTool('tool', idempotent: true), ['readOnlyHint' => true, 'idempotentHint' => true]],
            ['GET', new McpTool('tool', openWorld: true), ['readOnlyHint' => true, 'openWorldHint' => true]],
            ['DELETE', new McpTool('tool', readOnly: true), ['readOnlyHint' => true, 'destructiveHint' => true]],
            ['DELETE', new McpTool('tool', destructive: false), ['destructiveHint' => false]],
            ['DELETE', new McpTool('tool', idempotent: true), ['destructiveHint' => true, 'idempotentHint' => true]],
            ['POST', new McpTool('tool', readOnly: true), ['readOnlyHint' => true]],
            ['POST', new McpTool('tool', destructive: true, openWorld: true), ['destructiveHint' => true, 'openWorldHint' => true]],
            ['PATCH', new McpTool('tool', idempotent: false), ['idempotentHint' => false]],
        ];

        foreach ($cases as $i => [$method, $tool, $expected]) {
            $this->assertSame($expected, $tool->annotations($method), "Case {$i} ({$method})");
        }
    }

    public function testItKeepsFalseHintsAndOmitsUnsetOnes(): void
    {
        $annotations = new McpTool('tool', readOnly: false, destructive: false, idempotent: false, openWorld: false)
            ->annotations('GET');

        $this->assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            $annotations,
        );
    }
}
