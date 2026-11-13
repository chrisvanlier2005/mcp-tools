<?php

namespace Lier\McpTools\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Lier\McpTools\McpTool;

final class InvokableController
{
    #[McpTool('ping')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['pong' => true]);
    }
}
