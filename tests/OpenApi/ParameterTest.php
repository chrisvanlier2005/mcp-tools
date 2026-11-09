<?php

namespace Lier\McpTools\Tests\OpenApi;

use Lier\McpTools\OpenApi\Parameter;
use Lier\McpTools\OpenApi\Schema;
use PHPUnit\Framework\TestCase;

final class ParameterTest extends TestCase
{
    public function testItIsCreatedFromItsDefinition(): void
    {
        $parameter = Parameter::from(new Schema([
            'name' => 'household',
            'in' => 'path',
            'required' => true,
            'description' => 'The household UUID',
            'schema' => ['type' => 'string'],
        ]));

        $this->assertSame('household', $parameter->name);
        $this->assertSame('path', $parameter->in);
        $this->assertTrue($parameter->required);
        $this->assertSame('The household UUID', $parameter->description);
        $this->assertSame(['type' => 'string'], $parameter->schema->schema);
    }

    public function testItDefaultsMissingFields(): void
    {
        $parameter = Parameter::from(new Schema(['name' => 'page']));

        $this->assertSame('', $parameter->in);
        $this->assertFalse($parameter->required);
        $this->assertNull($parameter->description);
        $this->assertSame([], $parameter->schema->schema);
    }

    public function testItAddsTheDescriptionToTheSchema(): void
    {
        $parameter = new Parameter('page', 'query', false, 'The page', new Schema(['type' => 'integer']));

        $this->assertSame(['type' => 'integer', 'description' => 'The page'], $parameter->describedSchema()->schema);
    }

    public function testItKeepsTheSchemasOwnDescription(): void
    {
        $schema = new Schema(['type' => 'integer', 'description' => 'Own description']);

        $this->assertSame($schema, new Parameter('page', 'query', false, 'The page', $schema)->describedSchema());
        $this->assertSame($schema, new Parameter('page', 'query', false, null, $schema)->describedSchema());
    }
}
