<?php

namespace Lier\McpTools;

use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lier\McpTools\OpenApi\Document;
use Lier\McpTools\OpenApi\Schema;
use Webmozart\Assert\Assert;

/**
 * Everything needed to expose a route as an MCP tool, resolved from the API documentation.
 */
final readonly class RouteToolDefinition
{
    /**
     * Create a new route tool definition.
     *
     * @param string $action
     * @param string $uri
     * @param string $method
     * @param \Lier\McpTools\McpTool $attribute
     * @param string $title
     * @param string $description
     * @param \Lier\McpTools\OpenApi\Schema $inputSchema
     * @param \Lier\McpTools\OpenApi\Schema|null $outputSchema
     * @return void
     */
    public function __construct(
        public string $action,
        public string $uri,
        public string $method,
        public McpTool $attribute,
        public string $title,
        public string $description,
        public Schema $inputSchema,
        public ?Schema $outputSchema,
    ) {
    }

    /**
     * Make the definition for the route from the API documentation.
     *
     * @param \Illuminate\Routing\Route $route
     * @param \Lier\McpTools\McpTool $attribute
     * @param \Lier\McpTools\OpenApi\Document $document
     * @return self
     */
    public static function make(Route $route, McpTool $attribute, Document $document): self
    {
        $path = '/' . ltrim($route->uri(), '/');
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        // A route can accept several methods (e.g. PUT and PATCH), of which the documentation may describe only one.
        $method = Arr::first($methods, fn (string $method) => !is_null($document->findOperation($method, $path)))
            ?? $methods[0]
            ?? 'GET';

        $operation = $document->operation($method, $path);
        $title = $attribute->title ?: $operation->summary ?: Str::headline($attribute->name);
        $outputSchema = $operation->outputSchema($document, $attribute->hideOutput);

        return new self(
            action: $route->getActionName(),
            uri: $route->uri(),
            method: $method,
            attribute: $attribute,
            title: $title,
            description: $attribute->description ?: $operation->description ?: $title,
            inputSchema: $document->resolve($operation->inputSchema($document, $attribute->hideInput)),
            outputSchema: is_null($outputSchema) ? null : $document->resolve($outputSchema),
        );
    }

    /**
     * Restore a definition from its array representation.
     *
     * @param array<string, mixed> $definition
     * @return self
     */
    public static function fromArray(array $definition): self
    {
        $attribute = Assert::isMap($definition['attribute'] ?? null);

        return new self(
            action: Assert::string($definition['action'] ?? null),
            uri: Assert::string($definition['uri'] ?? null),
            method: Assert::string($definition['method'] ?? null),
            attribute: new McpTool(...$attribute),
            title: Assert::string($definition['title'] ?? null),
            description: Assert::string($definition['description'] ?? null),
            inputSchema: Schema::from($definition['inputSchema'] ?? null) ?? new Schema(),
            outputSchema: Schema::from($definition['outputSchema'] ?? null),
        );
    }

    /**
     * Get the definition as an array of scalars and arrays, e.g. to cache it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'uri' => $this->uri,
            'method' => $this->method,
            'attribute' => get_object_vars($this->attribute),
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema->schema,
            'outputSchema' => $this->outputSchema?->schema,
        ];
    }
}
