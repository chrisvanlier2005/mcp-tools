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
}
