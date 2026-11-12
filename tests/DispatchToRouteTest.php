<?php

namespace Lier\McpTools\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Lier\McpTools\DispatchToRoute;
use Lier\McpTools\Tests\Fixtures\ExposedDispatchToRoute;
use Lier\McpTools\Tests\Fixtures\WidgetController;
use RuntimeException;

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

    public function testTheRequestFacadeFollowsTheSwappedRequest(): void
    {
        $original = app('request');
        $this->assertSame($original, RequestFacade::instance());

        $facadeRoute = Arr::first(app(Router::class)->getRoutes()->getRoutes(), fn ($route) => $route->uri() === 'facade');

        $this->assertNotNull($facadeRoute);

        TestResponse::fromBaseResponse(app(DispatchToRoute::class)($facadeRoute, 'GET', [], null))
            ->assertExactJson(['marked' => true]);

        $this->assertSame($original, RequestFacade::instance());
    }

    public function testItKeepsTheClientsAddressAndUserAgent(): void
    {
        app()->instance('request', Request::create('http://localhost/mcp', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'McpClient/1.0',
        ]));

        $request = app(ExposedDispatchToRoute::class)->request('http://localhost/widgets', 'GET', []);

        $this->assertSame('203.0.113.9', $request->ip());
        $this->assertSame('McpClient/1.0', $request->userAgent());
    }

    private function route(string $uri, string $method = 'GET'): Route
    {
        $route = Arr::first(
            app(Router::class)->getRoutes()->getRoutes(),
            fn (Route $route) => $route->uri() === $uri && in_array($method, $route->methods(), true),
        );

        $this->assertNotNull($route);

        return $route;
    }

    public function testItRenamesCamelCasedArgumentsToTheRouteParameters(): void
    {
        $response = app(DispatchToRoute::class)(
            $this->route('phases/{project_phase}/tasks/{task_id?}'),
            'GET',
            ['projectPhase' => 'p1', 'taskId' => 't 2'],
            null,
        );

        TestResponse::fromBaseResponse($response)->assertExactJson(['phase' => 'p1', 'task' => 't 2']);
    }

    public function testItPrefersTheSnakeCasedArgumentOverNothingAndKeepsItWhenBothAreGiven(): void
    {
        $response = app(DispatchToRoute::class)(
            $this->route('phases/{project_phase}/tasks/{task_id?}'),
            'GET',
            ['project_phase' => 'snake'],
            null,
        );

        TestResponse::fromBaseResponse($response)->assertExactJson(['phase' => 'snake', 'task' => null]);
    }

    public function testItDoesNotPassRouteParametersOnAsQuery(): void
    {
        $request = app(ExposedDispatchToRoute::class)->request('http://localhost/echo', 'GET', ['a' => 'b']);

        $this->assertSame(['a' => 'b'], $request->query());
    }

    public function testItLeavesOptionalParametersOut(): void
    {
        $route = $this->route('optional/{page?}');
        $dispatch = app(DispatchToRoute::class);

        TestResponse::fromBaseResponse($dispatch($route, 'GET', [], null))->assertExactJson(['page' => null]);
        TestResponse::fromBaseResponse($dispatch($route, 'GET', ['page' => null], null))->assertExactJson(['page' => null]);
        TestResponse::fromBaseResponse($dispatch($route, 'GET', ['page' => 3], null))->assertExactJson(['page' => '3']);
        TestResponse::fromBaseResponse($dispatch($route, 'GET', ['page' => 'a b'], null))->assertExactJson(['page' => 'a b']);
    }

    public function testItRejectsAnOptionalParameterWithASlashOrAnArrayValue(): void
    {
        $route = $this->route('optional/{page?}');

        foreach (['1/2', ['x']] as $value) {
            try {
                app(DispatchToRoute::class)($route, 'GET', ['page' => $value], null);

                $this->fail('Expected a validation exception.');
            } catch (ValidationException $exception) {
                $this->assertSame(['page'], array_keys($exception->errors()));
            }
        }
    }

    public function testItEncodesReservedCharactersInScalarValues(): void
    {
        $response = app(DispatchToRoute::class)($this->route('widgets/{widget}'), 'GET', ['widget' => '?a=b&c#d%2F'], null);

        TestResponse::fromBaseResponse($response)->assertOk()->assertJsonPath('id', '?a=b&c#d%2F');
    }

    public function testItSendsNumbersAsRouteValues(): void
    {
        $dispatch = app(DispatchToRoute::class);
        $route = $this->route('widgets/{widget}');

        TestResponse::fromBaseResponse($dispatch($route, 'GET', ['widget' => 12], null))->assertJsonPath('id', '12');
        TestResponse::fromBaseResponse($dispatch($route, 'GET', ['widget' => 1.5], null))->assertJsonPath('id', '1.5');
    }

    public function testItSendsUnknownAndNestedArgumentsAsTheQueryOrBody(): void
    {
        $dispatch = app(DispatchToRoute::class);
        $arguments = ['filter' => ['a' => ['b', 'c']], 'flag' => true, 'n' => 5, 'text' => 'x y'];

        TestResponse::fromBaseResponse($dispatch($this->route('echo'), 'GET', $arguments, null))
            ->assertExactJson(['filter' => ['a' => ['b', 'c']], 'flag' => '1', 'n' => '5', 'text' => 'x y']);

        TestResponse::fromBaseResponse($dispatch($this->route('echo', 'POST'), 'POST', $arguments, null))
            ->assertExactJson($arguments);
    }

    public function testItSendsAnEmptyJsonObjectBodyWithoutArguments(): void
    {
        $request = app(ExposedDispatchToRoute::class)->request('http://localhost/echo', 'POST', []);

        $this->assertSame('[]', $request->getContent());
        $this->assertSame([], $request->json()->all());
        $this->assertTrue($request->expectsJson());
        $this->assertSame('application/json', $request->headers->get('CONTENT_TYPE'));
    }

    public function testItAppendsTheQueryToAnExistingQueryString(): void
    {
        $request = app(ExposedDispatchToRoute::class)->request('http://localhost/echo?a=1', 'GET', ['b' => '2']);

        $this->assertSame(['a' => '1', 'b' => '2'], $request->query());
    }

    private function throwingDispatch(): DispatchToRoute
    {
        $routes = app(Router::class)->getRoutes();

        // The router turns exceptions of routes into responses, so make the dispatch itself throw.
        $router = new class (app('events'), app(), $routes) extends Router {
            public function __construct($events, $container, private readonly RouteCollectionInterface $collection)
            {
                parent::__construct($events, $container);
            }

            public function getRoutes(): RouteCollectionInterface
            {
                return $this->collection;
            }

            public function dispatch(Request $request): never
            {
                throw new RuntimeException('Boom.');
            }
        };

        return new DispatchToRoute($router, app('auth'), app('url'), app('validator'), app('config'));
    }

    public function testItRestoresTheGuardAndRequestWhenTheDispatchThrows(): void
    {
        $original = app('request');
        Auth::shouldUse('web');

        try {
            $this->throwingDispatch()($this->route('closure'), 'GET', [], new GenericUser(['id' => 1]));

            $this->fail('Expected the dispatch to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Boom.', $exception->getMessage());
        }

        $this->assertSame($original, app('request'));
        $this->assertSame($original, RequestFacade::instance());
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public function testItRestoresTheRouteBindingWhenTheDispatchThrows(): void
    {
        $other = $this->route('widgets');
        app()->instance(Route::class, $other);

        try {
            $this->throwingDispatch()($this->route('closure'), 'GET', [], null);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame($other, app(Route::class));

        app()->forgetInstance(Route::class);

        try {
            $this->throwingDispatch()($this->route('closure'), 'GET', [], null);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertFalse(app()->resolved(Route::class));
    }

    public function testItTurnsAnExceptionOfTheRouteIntoAResponseAndRestoresTheGuard(): void
    {
        Auth::shouldUse('web');

        $response = app(DispatchToRoute::class)($this->route('boom'), 'GET', [], new GenericUser(['id' => 1]));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public function testItSetsNoUserWhenNoneIsGiven(): void
    {
        Auth::shouldUse('web');

        app(DispatchToRoute::class)($this->route('closure'), 'GET', [], null);

        $this->assertNull(Auth::guard('web')->user());
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public function testItFallsBackToTheDefaultGuardForANonStringGuardConfig(): void
    {
        config(['mcp-tools.guard' => 123]);

        app(DispatchToRoute::class)($this->route('me'), 'GET', [], new GenericUser(['id' => 9]));

        $this->assertSame(9, Auth::guard('web')->id());
    }

    public function testItKeepsTheClientsLanguage(): void
    {
        app()->instance('request', Request::create('http://localhost/mcp', 'POST', server: ['HTTP_ACCEPT_LANGUAGE' => 'nl-NL']));

        $request = app(ExposedDispatchToRoute::class)->request('http://localhost/echo', 'GET', []);

        $this->assertSame('nl-NL', $request->headers->get('Accept-Language'));
    }

    public function testItAnswers404ForAnUnmatchedMethod(): void
    {
        // The widgets URI exists for GET and POST only, so a DELETE can't reach it.
        $response = app(DispatchToRoute::class)($this->route('widgets'), 'DELETE', [], null);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('The route could not be matched.', json_decode((string) $response->getContent(), true)['message'] ?? null);
    }
}
