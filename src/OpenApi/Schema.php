<?php

namespace Lier\McpTools\OpenApi;

use ArrayObject;
use Illuminate\Support\Arr;

final readonly class Schema
{
    private const string REF_PREFIX = '#/components/schemas/';
    private const string DEFS_PREFIX = '#/$defs/';

    /**
     * Keywords whose value is a single schema.
     */
    private const array SCHEMA_KEYWORDS = [
        'items', 'additionalProperties', 'not', 'contains', 'propertyNames', 'if', 'then', 'else',
    ];

    /**
     * Keywords whose value is a map of schemas.
     */
    private const array SCHEMA_MAP_KEYWORDS = ['properties', 'patternProperties', 'dependentSchemas', '$defs'];

    /**
     * Keywords whose value is a list of schemas.
     */
    private const array SCHEMA_LIST_KEYWORDS = ['allOf', 'anyOf', 'oneOf', 'prefixItems'];

    /**
     * Create a new schema.
     *
     * @param array<string, mixed> $schema
     * @return void
     */
    public function __construct(
        public array $schema = [],
    ) {
    }

    /**
     * Create a schema from a decoded JSON value, if it is an object.
     *
     * @param mixed $value
     * @return self|null
     */
    public static function from(mixed $value): ?self
    {
        if (!is_array($value)) {
            return null;
        }

        $schema = [];

        foreach ($value as $key => $item) {
            $schema[(string) $key] = $item;
        }

        return new self($schema);
    }

    /**
     * Get the referenced component names in the given decoded JSON value.
     *
     * @param array<array-key, mixed> $node
     * @return list<string>
     */
    private static function referencesIn(array $node): array
    {
        $references = [];

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, self::REF_PREFIX)) {
                $references[] = substr($value, strlen(self::REF_PREFIX));
            } elseif (is_array($value)) {
                $references = [...$references, ...self::referencesIn($value)];
            }
        }

        return $references;
    }

    /**
     * Export a schema, pointing references to `$defs` and restoring empty schemas as objects.
     *
     * Decoding JSON to arrays turns `{}` into `[]`, so empty arrays in schema positions are exported as objects.
     * Values of other keywords are exported as schemas too, so references nested in them are also rewritten.
     *
     * @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    private static function exportSchema(array $schema): array
    {
        $exported = [];

        foreach ($schema as $keyword => $value) {
            $exported[$keyword] = match (true) {
                $keyword === '$ref' && is_string($value) => str_replace(self::REF_PREFIX, self::DEFS_PREFIX, $value),
                !is_array($value) => $value,
                in_array($keyword, self::SCHEMA_KEYWORDS, true) => self::exportSubschema($value),
                in_array($keyword, self::SCHEMA_MAP_KEYWORDS, true) && $value === [] => new ArrayObject(),
                in_array($keyword, self::SCHEMA_MAP_KEYWORDS, true),
                in_array($keyword, self::SCHEMA_LIST_KEYWORDS, true) => array_map(self::exportSubschema(...), $value),
                default => self::exportSchema($value),
            };
        }

        return $exported;
    }

    /**
     * Export a value in a schema position, e.g. `items` or a property.
     *
     * @param mixed $schema
     * @return mixed
     */
    private static function exportSubschema(mixed $schema): mixed
    {
        if (!is_array($schema)) {
            return $schema;
        }

        return $schema === [] ? new ArrayObject() : self::exportSchema($schema);
    }

    /**
     * Get the name of the component this schema references, if any.
     *
     * @return string|null
     */
    public function componentName(): ?string
    {
        $ref = $this->schema['$ref'] ?? null;

        return is_string($ref) && str_starts_with($ref, self::REF_PREFIX)
            ? substr($ref, strlen(self::REF_PREFIX))
            : null;
    }

    /**
     * Get a string value.
     *
     * @param string $key
     * @return string|null
     */
    public function string(string $key): ?string
    {
        $value = $this->schema[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Get a sub-schema.
     *
     * @param string $key
     * @return self|null
     */
    public function schema(string $key): ?self
    {
        return self::from($this->schema[$key] ?? null);
    }

    /**
     * Get the property schemas, keyed by property name.
     *
     * @return array<array-key, self>
     */
    public function properties(): array
    {
        return $this->schemas('properties');
    }

    /**
     * Get the required property names.
     *
     * @return list<string>
     */
    public function required(): array
    {
        $required = $this->schema['required'] ?? [];

        return is_array($required) ? array_values(array_filter($required, is_string(...))) : [];
    }

    /**
     * Get the sub-schemas under the given keyword, e.g. `allOf` or `properties`, preserving keys.
     *
     * @param string $keyword
     * @return array<array-key, self>
     */
    public function schemas(string $keyword): array
    {
        $value = $this->schema[$keyword] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $schemas = [];

        foreach ($value as $key => $item) {
            $schema = self::from($item);

            if (!is_null($schema)) {
                $schemas[$key] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * Get the names of the components referenced anywhere in this schema.
     *
     * @return list<string>
     */
    public function references(): array
    {
        return self::referencesIn($this->schema);
    }

    /**
     * Get a copy with the given keyword set.
     *
     * @param string $key
     * @param \Lier\McpTools\OpenApi\Schema|array<array-key, mixed>|string|bool $value
     * @return self
     */
    public function with(string $key, self|array|string|bool $value): self
    {
        return new self([...$this->schema, $key => $value instanceof self ? $value->schema : $value]);
    }

    /**
     * Get a copy with the given sub-schemas set under the keyword.
     *
     * @param string $keyword
     * @param array<array-key, \Lier\McpTools\OpenApi\Schema> $schemas
     * @return self
     */
    public function withSchemas(string $keyword, array $schemas): self
    {
        return $this->with($keyword, array_map(fn (self $schema) => $schema->schema, $schemas));
    }

    /**
     * Get a copy without the given keyword.
     *
     * @param string $key
     * @return self
     */
    public function without(string $key): self
    {
        return new self(Arr::except($this->schema, $key));
    }

    /**
     * Get a copy with the keywords of the given schema merged in.
     *
     * @param \Lier\McpTools\OpenApi\Schema $schema
     * @return self
     */
    public function merge(self $schema): self
    {
        return new self([...$this->schema, ...$schema->schema]);
    }

    /**
     * Get the schema as an array that serializes to valid JSON Schema, with references pointing to `$defs`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $exported = [];

        foreach (self::exportSchema($this->schema) as $key => $value) {
            $exported[(string) $key] = $value;
        }

        return $exported;
    }
}
