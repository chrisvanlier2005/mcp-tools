<?php

namespace Lier\McpTools\Tests\OpenApi;

use Lier\McpTools\OpenApi\Document;
use Lier\McpTools\OpenApi\Operation;
use Lier\McpTools\OpenApi\Parameter;
use Lier\McpTools\OpenApi\Schema;
use PHPUnit\Framework\TestCase;

final class OperationTest extends TestCase
{
    public function testItIsCreatedFromItsDefinition(): void
    {
        $operation = Operation::from(new Schema([
            'summary' => 'List tasks',
            'description' => 'Displays the tasks.',
            'parameters' => [
                ['name' => 'household', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            'responses' => [
                '401' => ['content' => ['application/json' => ['schema' => ['title' => 'Error']]]],
                '204' => ['description' => 'No content'],
                '200' => ['content' => ['application/json' => ['schema' => ['title' => 'Success']]]],
            ],
        ]));

        $this->assertSame('List tasks', $operation->summary);
        $this->assertSame('Displays the tasks.', $operation->description);
        $this->assertSame(
            ['household'],
            array_map(fn (Parameter $parameter) => $parameter->name, $operation->parameters),
        );
        $this->assertSame(['type' => 'object'], $operation->requestBody?->schema);
        $this->assertSame(['title' => 'Success'], $operation->response?->schema);
    }

    public function testItBuildsTheInputSchemaFromPathAndQueryParametersAndTheBody(): void
    {
        $document = new Document(new Schema([
            'components' => [
                'schemas' => [
                    'TaskInput' => [
                        'type' => 'object',
                        'properties' => [
                            'subject' => ['type' => 'string'],
                            'secret' => ['type' => 'string'],
                        ],
                        'required' => ['subject', 'secret'],
                    ],
                ],
            ],
        ]));

        $operation = new Operation(
            summary: null,
            description: null,
            parameters: [
                new Parameter('household', 'path', true, 'The household', new Schema(['type' => 'string'])),
                new Parameter('page', 'query', false, null, new Schema(['type' => 'integer'])),
                new Parameter('include', 'query', false, null, new Schema(['type' => 'array'])),
                new Parameter('Preset', 'header', false, null, new Schema(['type' => 'string'])),
            ],
            requestBody: new Schema(['$ref' => '#/components/schemas/TaskInput']),
            response: null,
        );

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'household' => ['type' => 'string', 'description' => 'The household'],
                'page' => ['type' => 'integer'],
                'subject' => ['type' => 'string'],
            ],
            'required' => ['household', 'subject'],
        ], $operation->inputSchema($document, hide: ['include', 'secret'])->schema);
    }

    public function testItLeavesOutRequiredWhenNothingIsRequired(): void
    {
        $operation = new Operation(null, null, [], null, null);

        $this->assertSame(
            ['type' => 'object', 'properties' => []],
            $operation->inputSchema(new Document(new Schema()))->schema,
        );
    }

    public function testItBuildsTheOutputSchemaAsAnObject(): void
    {
        $document = new Document(new Schema());

        $operation = new Operation(null, null, [], null, new Schema([
            'properties' => ['data' => ['type' => 'array'], 'links' => ['type' => 'object']],
            'required' => ['data', 'links'],
        ]));

        $this->assertSame([
            'type' => 'object',
            'properties' => ['data' => ['type' => 'array']],
            'required' => ['data'],
        ], $operation->outputSchema($document, hide: ['links'])?->schema);
    }

    public function testItKeepsTheOutputSchemaAnObjectForDocumentedArrays(): void
    {
        $operation = new Operation(null, null, [], null, new Schema(['type' => 'array', 'items' => ['type' => 'string']]));

        $this->assertSame('object', $operation->outputSchema(new Document(new Schema()))?->schema['type'] ?? null);
    }

    public function testItHasNoOutputSchemaWithoutAJsonResponse(): void
    {
        $this->assertNull(new Operation(null, null, [], null, null)->outputSchema(new Document(new Schema())));
    }
}
