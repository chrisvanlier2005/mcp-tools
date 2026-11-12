<?php

namespace Lier\McpTools\Tests\Fixtures;

use Illuminate\Http\Request;
use Lier\McpTools\DispatchToRoute;

final class ExposedDispatchToRoute extends DispatchToRoute
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function request(string $url, string $method, array $parameters): Request
    {
        return $this->makeRequest($url, $method, $parameters);
    }
}
