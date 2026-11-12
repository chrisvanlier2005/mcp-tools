<?php

namespace Lier\McpTools;

use Illuminate\Auth\AuthManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Dispatches a tool call to its route. Bind a subclass in the container to customize it.
 */
class DispatchToRoute
{
    /**
     * The request attribute that marks a request dispatched for an MCP tool call, e.g. to skip rate limiting.
     */
    public const string REQUEST_ATTRIBUTE = 'mcp_tool_call';

    /**
     * Create a new action instance.
     *
     * @param \Illuminate\Routing\Router $router
     * @param \Illuminate\Auth\AuthManager $auth
     * @param \Illuminate\Contracts\Routing\UrlGenerator $url
     * @param \Illuminate\Contracts\Validation\Factory $validator
     * @param \Illuminate\Contracts\Config\Repository $config
     * @return void
     */
    public function __construct(
        protected Router $router,
        protected AuthManager $auth,
        protected UrlGenerator $url,
        protected ValidatorFactory $validator,
        protected Config $config,
    ) {
    }

    /**
     * Dispatch an internal JSON request to the route as the given user, through the route's middleware.
     *
     * Arguments matching the route's parameters fill in the URI; the rest is sent as the query string
     * (GET and DELETE) or as the JSON body.
     *
     * @param \Illuminate\Routing\Route $route
     * @param string $method
     * @param array<string, mixed> $arguments
     * @param \Illuminate\Contracts\Auth\Authenticatable|null $user
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Illuminate\Validation\ValidationException
     * @throws \JsonException
     */
    public function __invoke(Route $route, string $method, array $arguments, ?Authenticatable $user): Response
    {
        // The documented path parameters are camel cased, e.g. "projectPhase" for "{project_phase}".
        foreach ($this->parameterNames($route) as $name) {
            $camel = Str::camel($name);

            if ($camel !== $name && array_key_exists($camel, $arguments)) {
                $arguments[$name] = $arguments[$camel];
                unset($arguments[$camel]);
            }
        }

        $url = $this->url($route, $arguments);
        $request = $this->makeRequest($url, $method, Arr::except($arguments, $this->parameterNames($route)));

        // The URL is matched again by the router, so make sure it still reaches this route.
        if (!$this->reaches($route, $request)) {
            return new JsonResponse(['message' => 'The route could not be matched.'], 404);
        }

        return $this->dispatchAs($request, $user);
    }

    /**
     * Determine whether the request matches the given route, and not e.g. a sibling route registered before it.
     *
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    protected function reaches(Route $route, Request $request): bool
    {
        try {
            $matched = $this->router->getRoutes()->match($request);
        } catch (HttpException) {
            return false;
        }

        return $matched->getActionName() === $route->getActionName() && $matched->uri() === $route->uri();
    }

    /**
     * Get the route's URL with the arguments filled in for its parameters.
     *
     * The arguments come from an LLM, so they are validated: the router decodes the path before matching it,
     * so a value containing a slash could otherwise reach a different route. Values are fully encoded for the
     * same reason, which is why the URL generator isn't used: it leaves e.g. "/", "?" and "&" unencoded.
     *
     * @param \Illuminate\Routing\Route $route
     * @param array<string, mixed> $arguments
     * @return string
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function url(Route $route, array $arguments): string
    {
        $uri = $route->uri();
        $rules = [];

        foreach ($this->parameterNames($route) as $name) {
            $rules[$name] = [str_contains($uri, "{{$name}?}") ? 'nullable' : 'required', 'not_regex:#/#'];
        }

        $this->validator->make($arguments, $rules)->validate();

        foreach (array_keys($rules) as $name) {
            $value = $arguments[$name] ?? '';
            $value = is_scalar($value) ? (string) $value : '';

            $uri = str_replace(["{{$name}}", "{{$name}?}"], rawurlencode($value), $uri);
        }

        return $this->url->to($uri);
    }

    /**
     * Make an internal JSON request, marked as an MCP tool call.
     *
     * @param string $url
     * @param string $method
     * @param array<string, mixed> $parameters
     * @return \Illuminate\Http\Request
     * @throws \JsonException
     */
    protected function makeRequest(string $url, string $method, array $parameters): Request
    {
        $inQuery = in_array($method, [Request::METHOD_GET, Request::METHOD_DELETE], true);

        // Symfony puts the parameters of a DELETE request in the form body, which JSON requests discard.
        if ($inQuery && $parameters !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($parameters);
        }

        $request = Request::create(
            uri: $url,
            method: $method,
            parameters: [],
            server: [...$this->clientServer(), 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            // An empty string, as null makes Laravel read the real php://input, i.e. the MCP request.
            content: $inQuery ? '' : json_encode($parameters, JSON_THROW_ON_ERROR),
        );

        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        return $request;
    }

    /**
     * Get the client's address, user agent and language from the current request, e.g. for logging and throttling.
     *
     * @return array<string, string>
     */
    protected function clientServer(): array
    {
        $server = Container::getInstance()->make('request')->server;

        return array_filter(
            [
                'REMOTE_ADDR' => $server->get('REMOTE_ADDR'),
                'HTTP_USER_AGENT' => $server->get('HTTP_USER_AGENT'),
                'HTTP_ACCEPT_LANGUAGE' => $server->get('HTTP_ACCEPT_LANGUAGE'),
            ],
            is_string(...),
        );
    }

    /**
     * Dispatch the request to its route as the given user, restoring the application's state afterwards.
     *
     * The user is set on the `mcp-tools.guard` guard, or the default guard if not configured.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Contracts\Auth\Authenticatable|null $user
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function dispatchAs(Request $request, ?Authenticatable $user): Response
    {
        // Auth middleware makes the route's guard the default guard; restore that afterwards.
        $defaultGuard = $this->auth->getDefaultDriver();

        if (!is_null($user)) {
            $guard = $this->config->get('mcp-tools.guard');
            $this->auth->guard(is_string($guard) ? $guard : null)->setUser($user);
        }

        // Form requests are resolved from the container's request, so swap it for the duration of the dispatch.
        $container = Container::getInstance();
        $originalRequest = $container->make('request');
        $originalRoute = $container->bound(Route::class) ? $container->make(Route::class) : null;
        $container->instance('request', $request);
        Facade::clearResolvedInstance('request');

        try {
            return $this->router->dispatch($request);
        } finally {
            $container->instance('request', $originalRequest);
            Facade::clearResolvedInstance('request');

            // The router has no setter for its current route, which stays the tool's route.
            if (is_null($originalRoute)) {
                $container->forgetInstance(Route::class);
            } else {
                $container->instance(Route::class, $originalRoute);
            }

            $this->auth->shouldUse($defaultGuard);
        }
    }

    /**
     * Get the names of the route's parameters.
     *
     * @param \Illuminate\Routing\Route $route
     * @return list<string>
     */
    protected function parameterNames(Route $route): array
    {
        return array_values(array_filter($route->parameterNames(), is_string(...)));
    }
}
