<?php

namespace Lier\McpTools\Tests\Fixtures;

use Laravel\Mcp\Request;
use Lier\McpTools\RouteTool;
use Override;

final class RestrictedRouteTool extends RouteTool
{
    #[Override]
    public function shouldRegister(Request $request): bool
    {
        return $this->isReadOnly() || $request->user()?->getAuthIdentifier() === 1;
    }
}
