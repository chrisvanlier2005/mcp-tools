<?php

namespace Lier\McpTools;

use Attribute;

/**
 * Expose the controller action as an MCP tool.
 *
 * The tool's input and output schemas are taken from the OpenAPI document,
 * so the action must be documented there.
 *
 * @see \Lier\McpTools\RouteTool
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class McpTool
{
    /**
     * Create a new attribute instance.
     *
     * Hidden output fields use dot notation, with `*` for list items, e.g. "data.*.description".
     *
     * @param non-empty-string $name
     * @param string|null $title
     * @param string|null $description
     * @param list<string> $hideInput
     * @param list<string> $hideOutput
     * @param bool|null $readOnly
     * @param bool|null $destructive
     * @param bool|null $idempotent
     * @param bool|null $openWorld
     * @return void
     */
    public function __construct(
        public string $name,
        public ?string $title = null,
        public ?string $description = null,
        public array $hideInput = [],
        public array $hideOutput = [],
        public ?bool $readOnly = null,
        public ?bool $destructive = null,
        public ?bool $idempotent = null,
        public ?bool $openWorld = null,
    ) {
    }

    /**
     * Get the MCP annotation hints for a route with the given HTTP method.
     *
     * GET routes are read-only and DELETE routes destructive, unless overridden.
     *
     * @param string $httpMethod
     * @return array<string, bool>
     */
    public function annotations(string $httpMethod): array
    {
        return array_filter([
            'readOnlyHint' => $this->readOnly ?? ($httpMethod === 'GET' ? true : null),
            'destructiveHint' => $this->destructive ?? ($httpMethod === 'DELETE' ? true : null),
            'idempotentHint' => $this->idempotent,
            'openWorldHint' => $this->openWorld,
        ], fn (?bool $hint) => !is_null($hint));
    }
}
