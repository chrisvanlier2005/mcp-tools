<?php

namespace Lier\McpTools\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Lier\McpTools\DispatchToRoute;
use Lier\McpTools\Tests\Fixtures\ExposedDispatchToRoute;
use Lier\McpTools\Tests\Fixtures\WidgetController;

final class DispatchToRouteTest extends TestCase
{
    public function testItDispatchesAsTheUserAndRestoresTheRequestAndGuard(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@me');

        $this->assertNotNull($route);

        $original = app('request');
        Auth::shouldUse('web');

        $response = app(DispatchToRoute::class)($route, 'GET', [], new GenericUser(['id' => 42]));

        TestResponse::fromBaseResponse($response)->assertOk()->assertExactJson(['id' => 42]);

        $this->assertSame($original, app('request'));
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public function testItDispatchesWithoutAUser(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@me');

        $this->assertNotNull($route);

        $response = app(DispatchToRoute::class)($route, 'GET', [], null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testItFillsInRouteParametersAndPassesTheRestAsQuery(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@show');

        $this->assertNotNull($route);

        $response = app(DispatchToRoute::class)($route, 'GET', ['widget' => 'a b', 'unknown' => 'ignored'], null);

        TestResponse::fromBaseResponse($response)->assertOk()->assertJsonPath('id', 'a b');

        $index = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@index');

        $this->assertNotNull($index);

        TestResponse::fromBaseResponse(app(DispatchToRoute::class)($index, 'GET', ['search' => 'foo'], null))
            ->assertJsonPath('data.0.name', 'foo');
    }

    public function testItPassesArgumentsAsTheJsonBody(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@store');

        $this->assertNotNull($route);

        $response = app(DispatchToRoute::class)($route, 'POST', ['name' => 'Gizmo'], null);

        TestResponse::fromBaseResponse($response)->assertCreated()->assertExactJson(['id' => '2', 'name' => 'Gizmo']);
    }

    public function testItRequiresRouteParameters(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@show');

        $this->assertNotNull($route);

        try {
            app(DispatchToRoute::class)($route, 'GET', [], null);

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(['widget' => ['The widget field is required.']], $exception->errors());
        }
    }

    public function testItRejectsSlashesInRouteParameters(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@show');

        $this->assertNotNull($route);

        try {
            // The router decodes the path before matching, so a slash could otherwise reach a different route.
            app(DispatchToRoute::class)($route, 'GET', ['widget' => '1/other'], null);

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(['widget' => ['The widget field format is invalid.']], $exception->errors());
        }
    }

    public function testItRejectsRouteParametersThatArentScalars(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@show');

        $this->assertNotNull($route);

        try {
            app(DispatchToRoute::class)($route, 'GET', ['widget' => ['not', 'a', 'string']], null);

            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(['widget' => ['The widget field format is invalid.']], $exception->errors());
        }
    }

    public function testItMarksTheRequestAsAToolCall(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@marker');

        $this->assertNotNull($route);

        TestResponse::fromBaseResponse(app(DispatchToRoute::class)($route, 'GET', [], null))
            ->assertExactJson(['marked' => true]);
    }

    public function testItUsesTheConfiguredGuard(): void
    {
        config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
        config(['mcp-tools.guard' => 'api']);

        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@me');

        $this->assertNotNull($route);

        app(DispatchToRoute::class)($route, 'GET', [], new GenericUser(['id' => 7]));

        $this->assertSame(7, Auth::guard('api')->id());
        $this->assertNull(Auth::guard('web')->id());
    }

    public function testItPassesDeleteArgumentsAsTheQueryString(): void
    {
        $request = app(ExposedDispatchToRoute::class)->request('http://localhost/widgets/1?a=1', 'DELETE', ['b' => 'x y']);

        $this->assertSame('x y', $request->query('b'));
        $this->assertSame('1', $request->query('a'));
        $this->assertSame('x y', $request->input('b'));
    }

    public function testBodilessRequestsHaveAnEmptyBody(): void
    {
        $request = app(ExposedDispatchToRoute::class)->request('http://localhost/widgets', 'GET', []);

        $this->assertSame('', $request->getContent());
        $this->assertSame([], $request->json()->all());
    }

    public function testItDoesNotDispatchToASiblingRoute(): void
    {
        $route = app(Router::class)->getRoutes()->getByAction(WidgetController::class . '@destroy');

        $this->assertNotNull($route);

        // "widgets/purge" is registered before "widgets/{widget}" and would be matched instead.
        $response = app(DispatchToRoute::class)($route, 'DELETE', ['widget' => 'purge'], null);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(204, app(DispatchToRoute::class)($route, 'DELETE', ['widget' => '1'], null)->getStatusCode());
    }
}
