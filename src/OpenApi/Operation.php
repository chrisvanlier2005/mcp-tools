<?php

namespace Lier\McpTools\OpenApi;

use Illuminate\Support\Arr;

final readonly class Operation
{
    /**
     * Create a new API operation.
     *
     * @param string|null $summary
     * @param string|null $description
     * @param list<\Lier\McpTools\OpenApi\Parameter> $parameters
     * @param \Lier\McpTools\OpenApi\Schema|null $requestBody
     * @param \Lier\McpTools\OpenApi\Schema|null $response
     * @return void
     */
    public function __construct(
        public ?string $summary,
        public ?string $description,
        public array $parameters,
        public ?Schema $requestBody,
        public ?Schema $response,
    ) {
    }

    /**
     * Create an operation from its OpenAPI definition.
     *
     * @param \Lier\McpTools\OpenApi\Schema $operation
     * @return self
     */
    public static function from(Schema $operation): self
    {
        $response = null;

        foreach ($operation->schemas('responses') as $status => $definition) {
            $schema = self::jsonSchema($definition);

            if (str_starts_with((string) $status, '2') && !is_null($schema)) {
                $response = $schema;

                break;
            }
        }

        return new self(
            summary: $operation->string('summary'),
            description: $operation->string('description'),
            parameters: array_values(array_map(Parameter::from(...), $operation->schemas('parameters'))),
            requestBody: self::jsonSchema($operation->schema('requestBody')),
            response: $response,
        );
    }

    /**
     * Get the JSON schema from a request body or response definition.
     *
     * @param \Lier\McpTools\OpenApi\Schema|null $definition
     * @return \Lier\McpTools\OpenApi\Schema|null
     */
    private static function jsonSchema(?Schema $definition): ?Schema
    {
        return $definition?->schema('content')?->schema('application/json')?->schema('schema');
    }

    /**
     * Build the input schema from the path & query parameters and the JSON request body.
     *
     * @param \Lier\McpTools\OpenApi\Document $document
     * @param list<string> $hide
     * @return \Lier\McpTools\OpenApi\Schema
     */
    public function inputSchema(Document $document, array $hide = []): Schema
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $parameter) {
            if (!in_array($parameter->in, ['path', 'query'], true) || in_array($parameter->name, $hide, true)) {
                continue;
            }

            $properties[$parameter->name] = $parameter->describedSchema();

            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        if (!is_null($this->requestBody)) {
            $body = $document->dereference($this->requestBody);

            $properties = [...$properties, ...Arr::except($body->properties(), $hide)];
            $required = [...$required, ...array_diff($body->required(), $hide)];
        }

        $schema = new Schema(['type' => 'object'])->withSchemas('properties', $properties);

        return $required === [] ? $schema : $schema->with('required', array_values(array_unique($required)));
    }

    /**
     * Build the output schema from the successful JSON response, if there is one.
     *
     * @param \Lier\McpTools\OpenApi\Document $document
     * @param list<string> $hide
     * @return \Lier\McpTools\OpenApi\Schema|null
     */
    public function outputSchema(Document $document, array $hide = []): ?Schema
    {
        if (is_null($this->response)) {
            return null;
        }

        $schema = $document->dereference($this->response);

        foreach ($hide as $path) {
            $schema = $document->withoutProperty($schema, explode('.', $path));
        }

        // MCP requires the output schema to describe an object.
        return new Schema(['type' => 'object'])->merge($schema)->with('type', 'object');
    }
}
