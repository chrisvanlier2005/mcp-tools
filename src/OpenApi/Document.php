<?php

namespace Lier\McpTools\OpenApi;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Lier\McpTools\Exceptions\InvalidApiDocumentationException;

/**
 * An OpenAPI document, e.g. exported by Scramble (`php artisan scramble:export`).
 */
final readonly class Document
{
    /**
     * Create a new document.
     *
     * @param \Lier\McpTools\OpenApi\Schema $document
     * @return void
     */
    public function __construct(
        private Schema $document,
    ) {
    }

    /**
     * Create a document from the OpenAPI JSON file at the given path, reading it once per request.
     *
     * @param string $path
     * @return self
     */
    public static function create(string $path): self
    {
        return once(function () use ($path): self {
            $contents = @file_get_contents($path);

            if ($contents === false) {
                throw InvalidApiDocumentationException::unreadable($path);
            }

            $document = Schema::from(json_decode($contents, true, flags: JSON_THROW_ON_ERROR));

            return new self(
                $document ?? throw InvalidApiDocumentationException::notAnObject($path),
            );
        });
    }

    /**
     * Find the operation for the given HTTP method and path.
     *
     * @param string $method
     * @param string $path
     * @return \Lier\McpTools\OpenApi\Operation
     */
    public function operation(string $method, string $path): Operation
    {
        return $this->findOperation($method, $path)
            ?? throw InvalidApiDocumentationException::undocumentedOperation($method, $path);
    }

    /**
     * Find the operation for the given HTTP method and path, if it is documented.
     *
     * @param string $method
     * @param string $path
     * @return \Lier\McpTools\OpenApi\Operation|null
     */
    public function findOperation(string $method, string $path): ?Operation
    {
        // Paths are relative to the server URL, e.g. "/api", which the route's URI includes.
        $url = ($this->document->schemas('servers')[0] ?? null)?->string('url');
        $prefix = is_null($url) ? '' : rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
            $path = substr($path, strlen($prefix));
        }

        $paths = $this->document->schema('paths');

        // Scramble names path parameters after the action's arguments, so "{project_phase}" is documented as "{projectPhase}".
        $camelPath = preg_replace_callback('/\{(\w+)(\??)\}/', fn (array $match) => '{' . Str::camel($match[1]) . $match[2] . '}', $path);

        // OpenAPI documents an optional parameter "{param?}" as "{param}".
        $requiredPath = str_replace('?}', '}', $path);
        $camelRequiredPath = str_replace('?}', '}', (string) $camelPath);

        $operation = ($paths?->schema($path)
            ?? $paths?->schema((string) $camelPath)
            ?? $paths?->schema($requiredPath)
            ?? $paths?->schema($camelRequiredPath))?->schema(strtolower($method));

        return is_null($operation) ? null : Operation::from($operation);
    }

    /**
     * Get a schema component by name.
     *
     * @param string $name
     * @return \Lier\McpTools\OpenApi\Schema
     */
    public function component(string $name): Schema
    {
        return $this->document->schema('components')?->schema('schemas')?->schema($name)
            ?? throw InvalidApiDocumentationException::missingComponent($name);
    }

    /**
     * Resolve the schema if it references a component, keeping its other keywords (e.g. a description).
     *
     * @param \Lier\McpTools\OpenApi\Schema $schema
     * @return \Lier\McpTools\OpenApi\Schema
     */
    public function dereference(Schema $schema): Schema
    {
        $name = $schema->componentName();

        return is_null($name)
            ? $schema
            : $this->component($name)->merge($schema->without('$ref'));
    }

    /**
     * Remove the property at the given path (`*` for list items) from the schema.
     *
     * References along the path are resolved, so other uses of the referenced component are unaffected.
     *
     * @param \Lier\McpTools\OpenApi\Schema $schema
     * @param list<string> $path
     * @return \Lier\McpTools\OpenApi\Schema
     */
    public function withoutProperty(Schema $schema, array $path): Schema
    {
        $segment = array_shift($path);

        if (is_null($segment)) {
            return $schema;
        }

        $schema = $this->dereference($schema);

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            $members = $schema->schemas($keyword);

            if ($members !== []) {
                $schema = $schema->withSchemas($keyword, array_map(
                    fn (Schema $member) => $this->withoutProperty($member, [$segment, ...$path]),
                    $members,
                ));
            }
        }

        if ($segment === '*') {
            $items = $schema->schema('items');

            return is_null($items) ? $schema : $schema->with('items', $this->withoutProperty($items, $path));
        }

        $properties = $schema->properties();

        if (!array_key_exists($segment, $properties)) {
            return $schema;
        }

        if ($path !== []) {
            $properties[$segment] = $this->withoutProperty($properties[$segment], $path);

            return $schema->withSchemas('properties', $properties);
        }

        $schema = $schema->withSchemas('properties', Arr::except($properties, $segment));

        return $schema->required() === []
            ? $schema
            : $schema->with('required', array_values(array_diff($schema->required(), [$segment])));
    }

    /**
     * Make the schema self-contained by adding the (transitively) referenced components as `$defs`.
     *
     * @param \Lier\McpTools\OpenApi\Schema $schema
     * @return \Lier\McpTools\OpenApi\Schema
     */
    public function resolve(Schema $schema): Schema
    {
        $definitions = [];
        $pending = $schema->references();

        while (!is_null($name = array_pop($pending))) {
            if (array_key_exists($name, $definitions)) {
                continue;
            }

            $definitions[$name] = $this->component($name);
            array_push($pending, ...$definitions[$name]->references());
        }

        return $definitions === [] ? $schema : $schema->withSchemas('$defs', $definitions);
    }
}
