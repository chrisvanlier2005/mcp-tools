<?php

namespace Lier\McpTools;

use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Override;

/**
 * An MCP tool that dispatches its calls to a route.
 *
 * Extend it and set the `mcp-tools.tool` config to customize the tool, e.g. to override shouldRegister().
 */
class RouteTool extends Tool
{
    /**
     * Create a new tool for the given route.
     *
     * @param \Illuminate\Routing\Route $route
     * @param \Lier\McpTools\RouteToolDefinition $definition
     * @return void
     */
    final public function __construct(
        protected Route $route,
        protected RouteToolDefinition $definition,
    ) {
        $this->name = $definition->attribute->name;
        $this->title = $definition->title;
        $this->description = $definition->description;
    }

    /**
     * Handle the tool call by dispatching it to the route.
     *
     * @param \Laravel\Mcp\Request $request
     * @param \Lier\McpTools\DispatchToRoute $dispatchToRoute
     * @return \Laravel\Mcp\Response|\Laravel\Mcp\ResponseFactory
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Illuminate\Validation\ValidationException
     * @throws \JsonException
     */
    public function handle(Request $request, DispatchToRoute $dispatchToRoute): Response|ResponseFactory
    {
        $response = $dispatchToRoute(
            $this->route,
            $this->definition->method,
            Arr::except($request->all(), $this->definition->attribute->hideInput),
            $request->user(),
        );

        $content = (string) $response->getContent();

        if (!$response->isSuccessful()) {
            return Response::error($content !== '' ? $content : "Request failed with {$response->getStatusCode()}.");
        }

        if ($content === '') {
            return Response::text('Done.');
        }

        $data = json_decode($content, true);

        // Not a JSON object or list, e.g. plain text or a scalar.
        if (!is_array($data)) {
            return Response::text($content);
        }

        foreach ($this->definition->attribute->hideOutput as $path) {
            data_forget($data, $path);
        }

        if ($data === []) {
            return Response::text('Done.');
        }

        // The structured content must be an object.
        if (array_is_list($data)) {
            return Response::text(json_encode($data, JSON_THROW_ON_ERROR));
        }

        return Response::structured($data);
    }

    /**
     * Get the tool's array representation, with schemas from the API documentation.
     *
     * @return array{
     *     name: string,
     *     title?: string|null,
     *     description?: string|null,
     *     inputSchema?: array<string, mixed>,
     *     outputSchema?: array<string, mixed>,
     *     annotations?: array<string, mixed>|object,
     *     _meta?: array<string, mixed>
     * }
     */
    #[Override]
    public function toArray(): array
    {
        $tool = [...parent::toArray(), 'inputSchema' => $this->definition->inputSchema->toArray()];

        if (!is_null($this->definition->outputSchema)) {
            $tool['outputSchema'] = $this->definition->outputSchema->toArray();
        }

        return $tool;
    }

    /**
     * Determine whether the tool should be registered for the request; override to restrict tools.
     *
     * @param \Laravel\Mcp\Request $request
     * @return bool
     */
    public function shouldRegister(Request $request): bool
    {
        return true;
    }

    /**
     * Get the tool's annotations.
     *
     * @return array<string, bool>
     */
    #[Override]
    public function annotations(): array
    {
        return $this->definition->attribute->annotations($this->definition->method);
    }

    /**
     * Determine whether the tool does not modify its environment.
     *
     * @return bool
     */
    public function isReadOnly(): bool
    {
        return ($this->annotations()['readOnlyHint'] ?? false) === true;
    }
}
