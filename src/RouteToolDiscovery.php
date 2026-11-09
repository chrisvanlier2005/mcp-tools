<?php

namespace Lier\McpTools;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use JsonException;
use Lier\McpTools\Exceptions\InvalidApiDocumentationException;
use Lier\McpTools\Exceptions\McpToolCacheException;
use Lier\McpTools\OpenApi\Document;
use ReflectionMethod;

/**
 * Finds the controller actions annotated with #[McpTool] and makes them into MCP tools.
 */
final readonly class RouteToolDiscovery
{
    /**
     * Create a new route tool discovery instance.
     *
     * @param \Illuminate\Routing\Router $router
     * @param \Lier\McpTools\RouteToolCache $cache
     * @param string $documentPath
     * @param bool $useCache
     * @param class-string<\Lier\McpTools\RouteTool> $toolClass The tool class, to customize e.g. shouldRegister().
     * @return void
     */
    public function __construct(
        private Router $router,
        private RouteToolCache $cache,
        private string $documentPath,
        private bool $useCache = true,
        private string $toolClass = RouteTool::class,
    ) {
    }

    /**
     * Make a tool for every route whose controller action is annotated with the McpTool attribute.
     *
     * Uses the cached definitions when they exist (`php artisan mcp-tools:cache`), so MCP requests neither scan the
     * routes nor parse the API documentation. Tools that can't be made, e.g. because their route is missing
     * from the API documentation, are skipped and reported.
     *
     * @return list<\Lier\McpTools\RouteTool>
     */
    public function tools(): array
    {
        $definitions = $this->useCache ? $this->cache->get() : null;

        if (is_null($definitions)) {
            ['definitions' => $definitions, 'errors' => $errors] = $this->discover();

            foreach ($errors as $error) {
                report($error);
            }
        }

        $tools = [];

        foreach ($definitions as $definition) {
            $route = $this->route($definition);

            if (is_null($route)) {
                report(McpToolCacheException::staleRoute($definition->attribute->name));

                continue;
            }

            $tools[] = new $this->toolClass($route, $definition);
        }

        return $tools;
    }

    /**
     * Discover the route tools and cache their definitions.
     *
     * Nothing is cached when any tool can't be made, so a failed rebuild keeps the previous cache.
     *
     * @return int
     * @throws \Lier\McpTools\Exceptions\McpToolCacheException
     */
    public function cache(): int
    {
        ['definitions' => $definitions, 'errors' => $errors] = $this->discover();

        if ($errors !== []) {
            throw McpToolCacheException::buildFailed($errors);
        }

        $this->cache->put($definitions);

        return count($definitions);
    }

    /**
     * Discover the annotated routes and make their tool definitions from the API documentation.
     *
     * @return array{
     *     definitions: list<\Lier\McpTools\RouteToolDefinition>,
     *     errors: list<\Throwable>,
     * }
     */
    private function discover(): array
    {
        try {
            $document = Document::create($this->documentPath);
        } catch (JsonException | InvalidApiDocumentationException $exception) {
            return ['definitions' => [], 'errors' => [$exception]];
        }

        $definitions = [];
        $errors = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $attribute = $this->attribute($route);

            if (is_null($attribute)) {
                continue;
            }

            try {
                $definitions[] = RouteToolDefinition::make($route, $attribute, $document);
            } catch (InvalidApiDocumentationException $exception) {
                $errors[] = $exception;
            }
        }

        return ['definitions' => $definitions, 'errors' => $errors];
    }

    /**
     * Get the McpTool attribute of the route's controller action, if any.
     *
     * @param \Illuminate\Routing\Route $route
     * @return \Lier\McpTools\McpTool|null
     */
    private function attribute(Route $route): ?McpTool
    {
        // Null for closure routes, which are serialized strings when routes are cached.
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();

        // The action method of an invokable controller is the controller's class name.
        if ($method === $controller) {
            $method = '__invoke';
        }

        if (is_null($controller) || !method_exists($controller, $method)) {
            return null;
        }

        $attribute = new ReflectionMethod($controller, $method)->getAttributes(McpTool::class)[0] ?? null;

        return $attribute?->newInstance();
    }

    /**
     * Find the route the tool was defined for, if it still exists.
     *
     * @param \Lier\McpTools\RouteToolDefinition $definition
     * @return \Illuminate\Routing\Route|null
     */
    private function route(RouteToolDefinition $definition): ?Route
    {
        $routes = $this->router->getRoutes();
        $route = $routes->getByAction($definition->action);

        if ($route?->uri() === $definition->uri) {
            return $route;
        }

        // An action can be registered on several routes, of which getByAction() only returns one.
        return Arr::first(
            $routes->getRoutes(),
            fn (Route $route) => $route->getActionName() === $definition->action && $route->uri() === $definition->uri,
        );
    }
}
