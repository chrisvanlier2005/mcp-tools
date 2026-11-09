# lier/mcp-tools

Expose Laravel controller actions as [Laravel MCP](https://github.com/laravel/mcp) tools with one attribute.

```php
#[McpTool('get-tasks')]
public function index(IndexTaskRequest $request): TaskCollection
```

The tool's input and output schemas come from your OpenAPI document, and every call is dispatched through the real
route, so its middleware, form request validation, policies and API resources all apply. No duplicate tool classes.

## Requirements

- PHP 8.4+
- Laravel 13
- [laravel/mcp](https://github.com/laravel/mcp) 0.9
- An OpenAPI 3 JSON document describing your routes, e.g. exported by [Scramble](https://scramble.dedoc.co)
  (`php artisan scramble:export`). Only documented actions become tools.

## Setup

### 1. Install

```bash
composer require lier/mcp-tools
```

The service provider is registered automatically.

### 2. Create an MCP server

If you don't have one yet, follow the [Laravel MCP docs](https://laravel.com/docs/mcp), e.g.:

```bash
php artisan make:mcp-server AppServer
```

```php
// routes/ai.php
use App\Mcp\Servers\AppServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', AppServer::class)->middleware('auth:sanctum');
```

### 3. Register the route tools on the server

```php
use Lier\McpTools\RouteToolDiscovery;

final class AppServer extends Server
{
    protected function boot(): void
    {
        array_push($this->tools, ...app(RouteToolDiscovery::class)->tools());
    }
}
```

### 4. Annotate controller actions

```php
use Lier\McpTools\McpTool;

final class TaskController
{
    #[McpTool('get-tasks', hideInput: ['include'], hideOutput: ['data.*.internal_notes'])]
    public function index(IndexTaskRequest $request, Household $household): TaskCollection
    {
        // ...
    }

    #[McpTool('create-task', description: 'Create a task for the given household.')]
    public function store(StoreTaskRequest $request, Household $household): TaskResource
    {
        // ...
    }
}
```

### 5. Export the API documentation

```bash
php artisan scramble:export
```

The package reads Scramble's `scramble.export_path` by default. Using another generator? Set `MCP_TOOLS_OPENAPI_PATH`
or publish the config (see below).

### 6. Configure the guard

Tool calls are dispatched as the MCP user. Set the guard your API routes authenticate with:

```dotenv
MCP_TOOLS_GUARD=sanctum
```

That's it: connect an MCP client to `/mcp` and the annotated actions are listed as tools.

## Attribute options

| Option | Description |
| --- | --- |
| `name` | The tool name, e.g. `get-tasks`. |
| `title`, `description` | Override the summary and description from the OpenAPI document. |
| `hideInput` | Input parameters to leave out of the tool, e.g. `include`. |
| `hideOutput` | Response fields to leave out, in dot notation with `*` for list items, e.g. `data.*.secret`. |
| `readOnly`, `destructive`, `idempotent`, `openWorld` | [Annotation hints](https://modelcontextprotocol.io/specification/2025-06-18/server/tools#tool-annotations). GET routes are read-only and DELETE routes destructive unless overridden. |

Arguments matching route parameters fill in the URL (validated, so they can't contain a slash); the rest is sent as
the query string for GET and DELETE, or as the JSON body otherwise. When a route accepts several methods (e.g. PUT
and PATCH), the documented one is used.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=mcp-tools-config
```

| Key | Default | Description |
| --- | --- | --- |
| `openapi_path` | Scramble's export path | The OpenAPI JSON document. |
| `guard` | the default guard | The guard the MCP user is set on when dispatching to the route. |
| `tool` | `Lier\McpTools\RouteTool` | The tool class; extend it to customize the tools. |

## Customizing

### Which tools a user gets

Extend `RouteTool`, override `shouldRegister()`, and set it as the `tool` class:

```php
use Laravel\Mcp\Request;
use Lier\McpTools\RouteTool as BaseRouteTool;

final class RouteTool extends BaseRouteTool
{
    public function shouldRegister(Request $request): bool
    {
        // e.g. only users with write access get tools that modify data.
        return $this->isReadOnly() || $request->user()?->can_write === true;
    }
}
```

```php
// config/mcp-tools.php
'tool' => App\Mcp\RouteTool::class,
```

The same subclass can override `handle()`, `toArray()` or `annotations()`; `$this->route` and `$this->definition` are
available.

### How calls are dispatched

Bind a subclass of `Lier\McpTools\DispatchToRoute` in the container to change how the internal request is made or
dispatched; its `url()`, `makeRequest()` and `dispatchAs()` methods are protected.

Dispatched requests carry the `DispatchToRoute::REQUEST_ATTRIBUTE` request attribute, e.g. to exempt tool calls from
your API rate limiter when the MCP endpoint is already rate limited:

```php
RateLimiter::for('api', function (Request $request) {
    if ($request->attributes->get(DispatchToRoute::REQUEST_ATTRIBUTE) === true) {
        return Limit::none();
    }

    // ...
});
```

## Caching

Discovering the tools scans the routes and parses the OpenAPI document. In production, cache the definitions:

```bash
php artisan mcp-tools:cache
```

`php artisan optimize` runs this for you. If a tool can't be made (e.g. its route is undocumented), the command fails
and keeps the previous cache. Clear it with `php artisan mcp-tools:clear`. The cache is ignored when running tests.

Without a cache, tools that can't be made are skipped and reported to your exception handler.

## Testing

```bash
composer install
vendor/bin/phpunit tests
vendor/bin/phpstan
```
