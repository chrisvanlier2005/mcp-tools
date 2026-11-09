<?php

namespace Lier\McpTools\OpenApi;

final readonly class Parameter
{
    /**
     * Create a new operation parameter.
     *
     * @param string $name
     * @param string $in
     * @param bool $required
     * @param string|null $description
     * @param \Lier\McpTools\OpenApi\Schema $schema
     * @return void
     */
    public function __construct(
        public string $name,
        public string $in,
        public bool $required,
        public ?string $description,
        public Schema $schema,
    ) {
    }

    /**
     * Create a parameter from its OpenAPI definition.
     *
     * @param \Lier\McpTools\OpenApi\Schema $parameter
     * @return self
     */
    public static function from(Schema $parameter): self
    {
        return new self(
            name: $parameter->string('name') ?? '',
            in: $parameter->string('in') ?? '',
            required: ($parameter->schema['required'] ?? false) === true,
            description: $parameter->string('description'),
            schema: $parameter->schema('schema') ?? new Schema(),
        );
    }

    /**
     * Get the parameter's schema, including the parameter's description.
     *
     * @return \Lier\McpTools\OpenApi\Schema
     */
    public function describedSchema(): Schema
    {
        return is_null($this->description) || !is_null($this->schema->string('description'))
            ? $this->schema
            : $this->schema->with('description', $this->description);
    }
}
