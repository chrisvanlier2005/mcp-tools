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

    public function testItHasNoResponseWithoutASuccessfulJsonResponse(): void
    {
        $operation = Operation::from(new Schema([
            'responses' => [
                '200' => ['content' => ['text/plain' => ['schema' => ['type' => 'string']]]],
                '204' => ['description' => 'No content'],
                '422' => ['content' => ['application/json' => ['schema' => ['title' => 'Error']]]],
                'default' => ['content' => ['application/json' => ['schema' => ['title' => 'Default']]]],
            ],
        ]));

        $this->assertNull($operation->response);
        $this->assertNull($operation->requestBody);
        $this->assertSame([], $operation->parameters);
        $this->assertNull($operation->summary);
        $this->assertNull(new Operation(null, null, [], null, null)->outputSchema(new Document(new Schema())));
    }

    public function testItTakesTheFirstSuccessfulJsonResponse(): void
    {
        $operation = Operation::from(new Schema([
            'responses' => [
                '201' => ['content' => ['application/json' => ['schema' => ['title' => 'Created']]]],
                '200' => ['content' => ['application/json' => ['schema' => ['title' => 'Ok']]]],
            ],
        ]));

        $this->assertSame(['title' => 'Created'], $operation->response?->schema);
    }

    public function testItIgnoresANonJsonRequestBody(): void
    {
        $operation = Operation::from(new Schema([
            'requestBody' => ['content' => ['multipart/form-data' => ['schema' => ['type' => 'object']]]],
        ]));

        $this->assertNull($operation->requestBody);
    }

    public function testItIgnoresHeaderAndCookieParameters(): void
    {
        $operation = Operation::from(new Schema(['parameters' => [
            ['name' => 'X-Trace', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'session', 'in' => 'cookie', 'schema' => ['type' => 'string']],
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ]]));

        $input = $operation->inputSchema(new Document(new Schema()));

        $this->assertSame(['page'], array_keys($input->properties()));
        $this->assertSame([], $input->required());
    }

    public function testItHidesInputParametersAndBodyProperties(): void
    {
        $document = new Document(new Schema(['components' => ['schemas' => ['Body' => [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'secret' => ['type' => 'string'], 'note' => ['type' => 'string']],
            'required' => ['name', 'secret'],
        ]]]]));

        $operation = Operation::from(new Schema([
            'parameters' => [
                ['name' => 'household', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'include', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
            'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Body']]]],
        ]));

        $input = $operation->inputSchema($document, ['household', 'secret']);

        $this->assertSame(['include', 'name', 'note'], array_keys($input->properties()));
        $this->assertSame(['name'], $input->required());
    }

    public function testItLetsABodyPropertyOverrideAParameterOfTheSameName(): void
    {
        $operation = Operation::from(new Schema([
            'parameters' => [
                ['name' => 'name', 'in' => 'query', 'required' => true, 'description' => 'Query', 'schema' => ['type' => 'integer']],
            ],
            'requestBody' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ]]]],
        ]));

        $input = $operation->inputSchema(new Document(new Schema()));

        $this->assertSame(['name' => ['type' => 'string']], $input->schema['properties']);
        $this->assertSame(['name'], $input->required(), 'A name required twice is listed once.');
    }

    public function testItKeepsTheRequiredParametersOfAnOperationWithoutBodyProperties(): void
    {
        $operation = Operation::from(new Schema([
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'The id']],
            'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        ]));

        $input = $operation->inputSchema(new Document(new Schema()));

        $this->assertSame(['id' => ['description' => 'The id']], $input->schema['properties']);
        $this->assertSame(['id'], $input->required());
    }

    public function testItHidesOutputPropertiesAlongPaths(): void
    {
        $document = new Document(new Schema());
        $operation = new Operation(null, null, [], null, new Schema([
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => [], 'b' => []]]],
                'meta' => ['type' => 'object'],
            ],
        ]));

        $output = $operation->outputSchema($document, ['data.*.a', 'meta']);

        $this->assertSame(['data'], array_keys($output?->properties() ?? []));
        $this->assertSame(['b'], array_keys($output?->properties()['data']->schema('items')?->properties() ?? []));
    }
}
