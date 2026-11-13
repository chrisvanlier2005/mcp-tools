<?php

namespace Lier\McpTools\Tests\OpenApi;

use Lier\McpTools\OpenApi\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testItIsCreatedFromObjectsOnly(): void
    {
        $this->assertNull(Schema::from('string'));
        $this->assertNull(Schema::from(null));
        $this->assertSame(['type' => 'string'], Schema::from(['type' => 'string'])?->schema);
    }

    public function testItReadsKeywords(): void
    {
        $schema = new Schema([
            '$ref' => '#/components/schemas/Task',
            'title' => 'Task',
            'items' => ['type' => 'string'],
            'properties' => ['id' => ['type' => 'string'], 'invalid' => true],
            'required' => ['id', 1],
            'allOf' => [['type' => 'object'], ['title' => 'Second']],
        ]);

        $this->assertSame('Task', $schema->componentName());
        $this->assertSame('Task', $schema->string('title'));
        $this->assertNull($schema->string('items'));
        $this->assertSame(['type' => 'string'], $schema->schema('items')?->schema);
        $this->assertSame(['id'], array_keys($schema->properties()));
        $this->assertSame(['id'], $schema->required());
        $this->assertSame([0, 1], array_keys($schema->schemas('allOf')));
        $this->assertSame([], $schema->schemas('missing'));
    }

    public function testItIgnoresReferencesOutsideTheComponents(): void
    {
        $this->assertNull(new Schema(['$ref' => '#/$defs/Task'])->componentName());
        $this->assertNull(new Schema(['type' => 'string'])->componentName());
    }

    public function testItIsImmutable(): void
    {
        $schema = new Schema(['type' => 'object', 'title' => 'Task']);

        $this->assertSame(['type' => 'object', 'title' => 'Task', 'items' => ['type' => 'string']], $schema
            ->with('items', new Schema(['type' => 'string']))
            ->schema);
        $this->assertSame(['type' => 'object'], $schema->without('title')->schema);
        $this->assertSame(
            ['type' => 'array', 'title' => 'Task'],
            $schema->merge(new Schema(['type' => 'array']))->schema,
        );
        $this->assertSame(['type' => 'object', 'title' => 'Task'], $schema->schema);
    }

    public function testItFindsNestedReferences(): void
    {
        $schema = new Schema([
            'properties' => [
                'task' => ['$ref' => '#/components/schemas/Task'],
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'local' => ['$ref' => '#/$defs/Local'],
            ],
        ]);

        $this->assertSame(['Task', 'Tag'], $schema->references());
    }

    public function testItExportsReferencesAsDefinitions(): void
    {
        $schema = new Schema(['items' => ['$ref' => '#/components/schemas/Task']]);

        $this->assertSame('{"items":{"$ref":"#/$defs/Task"}}', json_encode($schema->toArray(), JSON_UNESCAPED_SLASHES));
    }

    public function testItExportsEmptySchemasAsObjects(): void
    {
        $schema = new Schema([
            'type' => 'object',
            'properties' => [
                'any' => [],
                'list' => ['type' => 'array', 'items' => [], 'default' => []],
                'properties' => ['type' => 'object', 'properties' => []],
            ],
            'allOf' => [],
            'enum' => [],
        ]);

        $this->assertSame(
            '{"type":"object","properties":{"any":{},"list":{"type":"array","items":{},"default":[]},'
            . '"properties":{"type":"object","properties":{}}},"allOf":[],"enum":[]}',
            json_encode($schema->toArray()),
        );
    }

    public function testItDoesNotMutateWhenSettingSubSchemas(): void
    {
        $schema = new Schema(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);

        $changed = $schema->withSchemas('properties', ['b' => new Schema(['type' => 'integer'])]);

        $this->assertSame(['b' => ['type' => 'integer']], $changed->schema['properties']);
        $this->assertSame(['a' => ['type' => 'string']], $schema->schema['properties']);
    }

    public function testItLetsTheMergedSchemaWin(): void
    {
        $merged = new Schema(['type' => 'object', 'title' => 'A'])->merge(new Schema(['title' => 'B', 'format' => 'x']));

        $this->assertSame(['type' => 'object', 'title' => 'B', 'format' => 'x'], $merged->schema);
        $this->assertSame(['a' => 1], new Schema(['a' => 1])->without('missing')->schema);
    }

    public function testItReadsSubSchemasPreservingKeysAndSkippingScalars(): void
    {
        $schema = new Schema(['properties' => ['a' => ['type' => 'string'], 'b' => 'nope', 'c' => []], 'allOf' => 'nope']);

        $this->assertSame(['a', 'c'], array_keys($schema->properties()));
        $this->assertSame([], $schema->schemas('allOf'));
        $this->assertSame([], $schema->schemas('missing'));
        $this->assertNull($schema->schema('missing'));
        $this->assertSame([], $schema->schema('properties')?->schema('c')?->schema);
    }

    public function testItReadsOnlyStringRequiredNames(): void
    {
        $this->assertSame(['a', 'c'], new Schema(['required' => ['a', 1, 'c', null]])->required());
        $this->assertSame([], new Schema(['required' => 'a'])->required());
        $this->assertSame([], new Schema()->required());
    }

    public function testItExportsEveryKeywordTable(): void
    {
        $ref = ['$ref' => '#/components/schemas/Task'];

        $schema = new Schema([
            'items' => $ref,
            'additionalProperties' => [],
            'not' => $ref,
            'contains' => [],
            'propertyNames' => $ref,
            'if' => [],
            'then' => $ref,
            'else' => [],
            'properties' => ['a' => $ref, 'b' => []],
            'patternProperties' => ['^x' => $ref],
            'dependentSchemas' => [],
            '$defs' => ['Local' => $ref],
            'allOf' => [$ref],
            'anyOf' => [[], $ref],
            'oneOf' => [$ref],
            'prefixItems' => [$ref, []],
            'additionalItems' => true,
            'examples' => [['$ref' => 'x'], []],
            'default' => ['nested' => []],
        ]);

        $this->assertSame(
            '{"items":{"$ref":"#\/$defs\/Task"},"additionalProperties":{},"not":{"$ref":"#\/$defs\/Task"},"contains":{},'
            . '"propertyNames":{"$ref":"#\/$defs\/Task"},"if":{},"then":{"$ref":"#\/$defs\/Task"},"else":{},'
            . '"properties":{"a":{"$ref":"#\/$defs\/Task"},"b":{}},"patternProperties":{"^x":{"$ref":"#\/$defs\/Task"}},'
            . '"dependentSchemas":{},"$defs":{"Local":{"$ref":"#\/$defs\/Task"}},"allOf":[{"$ref":"#\/$defs\/Task"}],'
            . '"anyOf":[{},{"$ref":"#\/$defs\/Task"}],"oneOf":[{"$ref":"#\/$defs\/Task"}],'
            . '"prefixItems":[{"$ref":"#\/$defs\/Task"},{}],"additionalItems":true,'
            . '"examples":[{"$ref":"x"},[]],"default":{"nested":[]}}',
            json_encode($schema->toArray()),
        );
    }

    public function testItFindsReferencesInsideListsAndIgnoresNonLocalReferences(): void
    {
        $schema = new Schema([
            'allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => 'https://example.com/schema.json']],
            'properties' => ['b' => ['oneOf' => [['$ref' => '#/components/schemas/B']]]],
            '$ref' => '#/components/schemas/C',
            'example' => ['$ref' => 42],
        ]);

        $this->assertSame(['A', 'B', 'C'], $schema->references());
        $this->assertSame([], new Schema()->references());
    }

    public function testItReadsSchemasFromListsAndStringifiesKeys(): void
    {
        $schema = Schema::from(['a', 'b']);

        $this->assertSame(['0' => 'a', '1' => 'b'], $schema?->schema);
        $this->assertSame([], Schema::from([])?->schema);
        $this->assertNull(Schema::from('x'));
        $this->assertNull(Schema::from(null));
    }

    public function testItReadsStringsOnly(): void
    {
        $schema = new Schema(['a' => 'x', 'b' => 1]);

        $this->assertSame('x', $schema->string('a'));
        $this->assertNull($schema->string('b'));
        $this->assertNull($schema->string('c'));
    }
}
