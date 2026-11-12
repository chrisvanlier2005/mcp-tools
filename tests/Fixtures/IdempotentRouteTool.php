<?php

namespace Lier\McpTools\Tests\Fixtures;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Lier\McpTools\RouteTool;

#[IsIdempotent]
final class IdempotentRouteTool extends RouteTool
{
}
