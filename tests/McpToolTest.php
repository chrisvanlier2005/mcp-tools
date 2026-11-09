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
}
