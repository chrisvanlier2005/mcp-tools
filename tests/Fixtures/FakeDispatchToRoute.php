<?php

namespace Lier\McpTools\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Lier\McpTools\DispatchToRoute;
use Override;
use Symfony\Component\HttpFoundation\Response;

final class FakeDispatchToRoute extends DispatchToRoute
{
    public string $body = '';

    #[Override]
    public function __invoke(Route $route, string $method, array $arguments, ?Authenticatable $user): Response
    {
        return new Response($this->body);
    }
}
