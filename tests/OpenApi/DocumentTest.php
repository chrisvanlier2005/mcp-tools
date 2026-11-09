<?php

namespace Lier\McpTools\Tests\OpenApi;

use Lier\McpTools\Exceptions\InvalidApiDocumentationException;
use Lier\McpTools\OpenApi\Document;
use Lier\McpTools\OpenApi\Schema;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private const array DOCUMENT = [
        'paths' => [
            '/tasks' => [
                'get' => ['summary' => 'List tasks'],
            ],
        ],
        'components' => [
            'schemas' => [
                'Task' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'subject' => ['type' => 'string'],
                        'tags' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/Tag'],
                        ],
                    ],
                    'required' => ['id', 'subject'],
                ],
                'Tag' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    public function testItIsCreatedFromAFileOncePerPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');

        try {
            file_put_contents($path, json_encode([
                'paths' => [
                    '/tasks' => [
                        'get' => ['summary' => 'List tasks'],
                    ],
                ],
            ]));

            $document = Document::create($path);

            $this->assertSame('List tasks', $document->operation('GET', '/tasks')->summary);
            $this->assertSame($document, Document::create($path));
        } finally {
            unlink($path);
        }
    }

    public function testItRejectsAFileThatIsNotAJsonObject(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');

        try {
            file_put_contents($path, '"not an object"');

            $this->expectException(InvalidApiDocumentationException::class);

            Document::create($path);
        } finally {
            unlink($path);
        }
    }

    public function testItFailsForUndocumentedOperations(): void
    {
        try {
            new Document(new Schema(self::DOCUMENT))->operation('POST', '/tasks');

            $this->fail('Expected an exception.');
        } catch (InvalidApiDocumentationException $exception) {
            $this->assertStringStartsWith('No API documentation found for [POST /tasks].', $exception->getMessage());
        }
    }

    public function testItFailsForMissingComponents(): void
    {
        try {
            new Document(new Schema(self::DOCUMENT))->component('Missing');

            $this->fail('Expected an exception.');
        } catch (InvalidApiDocumentationException $exception) {
            $this->assertSame('Schema component [Missing] does not exist.', $exception->getMessage());
        }
    }

    public function testItDereferencesComponentsKeepingOtherKeywords(): void
    {
        $document = new Document(new Schema(self::DOCUMENT));
        $plain = new Schema(['type' => 'string']);

        $this->assertSame($plain, $document->dereference($plain));

        $dereferenced = $document->dereference(new Schema([
            '$ref' => '#/components/schemas/Tag',
            'description' => 'The tag',
        ]));

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'description' => 'The tag',
        ], $dereferenced->schema);
    }

    public function testItRemovesPropertiesAlongAPath(): void
    {
        $document = new Document(new Schema(self::DOCUMENT));

        $schema = new Schema([
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/Task'],
                            ['type' => 'object'],
                        ],
                    ],
                ],
                'links' => ['type' => 'object'],
            ],
            'required' => ['data', 'links'],
        ]);

        $schema = $document->withoutProperty($schema, ['links']);
        $schema = $document->withoutProperty($schema, ['data', '*', 'subject']);
        $schema = $document->withoutProperty($schema, ['data', '*', 'missing']);

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'allOf' => [
                            [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string'],
                                    'tags' => [
                                        'type' => 'array',
                                        'items' => ['$ref' => '#/components/schemas/Tag'],
                                    ],
                                ],
                                'required' => ['id'],
                            ],
                            ['type' => 'object'],
                        ],
                    ],
                ],
            ],
            'required' => ['data'],
        ], $schema->schema);

        $this->assertArrayHasKey(
            'subject',
            $document->component('Task')->properties(),
            'Shared components are not modified.',
        );
    }

    public function testItResolvesReferencedComponentsTransitively(): void
    {
        $resolved = new Document(new Schema(self::DOCUMENT))->resolve(new Schema([
            'items' => ['$ref' => '#/components/schemas/Task'],
        ]));

        $this->assertSame(
            '{"items":{"$ref":"#/$defs/Task"},"$defs":{'
            . '"Task":{"type":"object","properties":{"id":{"type":"string"},"subject":{"type":"string"},'
            . '"tags":{"type":"array","items":{"$ref":"#/$defs/Tag"}}},"required":["id","subject"]},'
            . '"Tag":{"type":"object","properties":{"name":{"type":"string"}}}}}',
            json_encode($resolved->toArray(), JSON_UNESCAPED_SLASHES),
        );
    }

    public function testItResolvesRecursiveComponents(): void
    {
        $document = new Document(new Schema([
            'components' => [
                'schemas' => [
                    'Node' => [
                        'type' => 'object',
                        'properties' => [
                            'children' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Node'],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $resolved = $document->resolve(new Schema(['$ref' => '#/components/schemas/Node']));

        $this->assertSame(
            '{"$ref":"#/$defs/Node","$defs":{"Node":{"type":"object","properties":{"children":'
            . '{"type":"array","items":{"$ref":"#/$defs/Node"}}}}}}',
            json_encode($resolved->toArray(), JSON_UNESCAPED_SLASHES),
        );
    }

    public function testItLeavesOutDefinitionsWithoutReferences(): void
    {
        $document = new Document(new Schema(self::DOCUMENT));

        $this->assertSame(['type' => 'string'], $document->resolve(new Schema(['type' => 'string']))->schema);
    }
}
