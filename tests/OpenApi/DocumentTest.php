<?php

namespace Lier\McpTools\Tests\OpenApi;

use Lier\McpTools\Exceptions\InvalidApiDocumentationException;
use Lier\McpTools\OpenApi\Document;
use Lier\McpTools\OpenApi\Schema;
use JsonException;
use PHPUnit\Framework\TestCase;
use Throwable;

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

    public function testItFindsOperationsOfOptionalParameters(): void
    {
        $document = new Document(new Schema(['paths' => ['/tasks/{taskId}' => ['get' => ['summary' => 'Show']]]]));

        $this->assertSame('Show', $document->findOperation('GET', '/tasks/{task_id?}')?->summary);
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

    /**
     * @param array<string, mixed> $paths
     * @param string|null $server
     */
    private function documentWith(array $paths, ?string $server = null): Document
    {
        return new Document(new Schema([
            ...(is_null($server) ? [] : ['servers' => [['url' => $server]]]),
            'paths' => $paths,
        ]));
    }

    public function testItStripsTheServerPathFromTheRoutePath(): void
    {
        foreach (['http://localhost/api', 'https://example.com/api/', '/api'] as $server) {
            $document = $this->documentWith(['/tasks' => ['get' => ['summary' => 'List']]], $server);

            $this->assertSame('List', $document->findOperation('GET', '/api/tasks')?->summary, $server);
        }
    }

    public function testItOnlyStripsTheServerPathAtASegmentBoundary(): void
    {
        $document = $this->documentWith(['/tasks' => ['get' => []], '/apiary' => ['get' => []]], 'http://localhost/api');

        $this->assertNull($document->findOperation('GET', '/apitasks'));
        $this->assertNotNull($document->findOperation('GET', '/apiary'));
        $this->assertNotNull($document->findOperation('GET', '/tasks'), 'Paths without the prefix are used as is.');
    }

    public function testItDoesNotStripAServerWithoutAPath(): void
    {
        $document = $this->documentWith(['/tasks' => ['get' => []]], 'http://localhost');

        $this->assertNotNull($document->findOperation('GET', '/tasks'));
    }

    public function testItFindsOperationsByCamelCasedPath(): void
    {
        $document = $this->documentWith(['/phases/{projectPhase}/tasks/{taskId}' => ['get' => ['summary' => 'Show']]]);

        $this->assertSame('Show', $document->findOperation('GET', '/phases/{project_phase}/tasks/{task_id}')?->summary);
    }

    public function testItPrefersTheExactPathOverItsCamelCasedAndRequiredVariants(): void
    {
        $document = $this->documentWith([
            '/tasks/{task_id}' => ['get' => ['summary' => 'Exact']],
            '/tasks/{taskId}' => ['get' => ['summary' => 'Camel']],
        ]);

        $this->assertSame('Exact', $document->findOperation('GET', '/tasks/{task_id}')?->summary);
        $this->assertSame('Exact', $document->findOperation('GET', '/tasks/{task_id?}')?->summary);
    }

    public function testItFindsOptionalParametersDocumentedWithTheirOwnName(): void
    {
        $document = $this->documentWith(['/tasks/{taskId?}' => ['get' => ['summary' => 'Optional']]]);

        $this->assertSame('Optional', $document->findOperation('GET', '/tasks/{task_id?}')?->summary);
    }

    public function testItFindsNothingForMissingPathsOrMethods(): void
    {
        $document = $this->documentWith(['/tasks' => ['get' => []]]);

        $this->assertNull($document->findOperation('GET', '/missing'));
        $this->assertNull($document->findOperation('POST', '/tasks'));
        $this->assertNull(new Document(new Schema())->findOperation('GET', '/tasks'));
        $this->assertNotNull($document->findOperation('get', '/tasks'), 'Methods are matched case insensitively.');
    }

    public function testItRemovesPropertiesFromEveryAnyOfAndOneOfMember(): void
    {
        $document = new Document(new Schema(self::DOCUMENT));

        $schema = new Schema([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['a' => [], 'b' => []]],
                ['$ref' => '#/components/schemas/Task'],
            ],
            'oneOf' => [
                ['type' => 'object', 'properties' => ['a' => []], 'required' => ['a']],
                ['type' => 'string'],
            ],
        ]);

        $result = $document->withoutProperty($schema, ['a']);
        $subject = $document->withoutProperty($schema, ['subject']);

        $this->assertSame(['b'], array_keys($result->schemas('anyOf')[0]->properties()));
        $this->assertSame([], $result->schemas('oneOf')[0]->properties());
        $this->assertSame([], $result->schemas('oneOf')[0]->required());
        $this->assertSame(['type' => 'string'], $result->schemas('oneOf')[1]->schema);
        $this->assertSame(['id', 'tags'], array_keys($subject->schemas('anyOf')[1]->properties()));
        $this->assertSame(['id'], $subject->schemas('anyOf')[1]->required());
    }

    public function testItRemovesNestedPropertiesBehindReferencesAndLists(): void
    {
        $document = new Document(new Schema(self::DOCUMENT));

        $schema = new Schema(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Task']]);

        $result = $document->withoutProperty($schema, ['*', 'tags', '*', 'name']);

        $this->assertSame([], $result->schema('items')?->properties()['tags']->schema('items')?->properties());
        $this->assertSame(['name'], array_keys($document->component('Tag')->properties()));
    }

    public function testItIgnoresPathsThatDontExist(): void
    {
        $document = new Document(new Schema(self::DOCUMENT));
        $schema = new Schema(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]);

        $this->assertSame($schema, $document->withoutProperty($schema, []));
        $this->assertSame($schema->schema, $document->withoutProperty($schema, ['*'])->schema);
        $this->assertSame($schema->schema, $document->withoutProperty($schema, ['b', 'c'])->schema);
        $this->assertSame($schema->schema, $document->withoutProperty($schema, ['a', 'b'])->schema, 'A scalar has no children.');
    }

    public function testItCleansUpTheRequiredListOfAHiddenProperty(): void
    {
        $schema = new Schema([
            'type' => 'object',
            'properties' => ['a' => [], 'b' => [], 'c' => []],
            'required' => ['a', 'b', 'c'],
        ]);

        $result = new Document(new Schema())->withoutProperty($schema, ['b']);

        $this->assertSame(['a', 'c'], $result->required());
        $this->assertSame([0, 1], array_keys($result->required()), 'The required list stays a list.');
        $this->assertSame(['a', 'b', 'c'], $schema->required());
    }

    public function testItResolvesReferencesInsideDefinitionsOnce(): void
    {
        $resolved = new Document(new Schema(self::DOCUMENT))->resolve(new Schema([
            'allOf' => [
                ['$ref' => '#/components/schemas/Tag'],
                ['$ref' => '#/components/schemas/Tag'],
            ],
        ]));

        $this->assertSame(['Tag'], array_keys($resolved->schema('$defs')->schema ?? []));
    }

    public function testItFailsToResolveMissingComponents(): void
    {
        $this->expectException(InvalidApiDocumentationException::class);

        new Document(new Schema(self::DOCUMENT))->resolve(new Schema(['$ref' => '#/components/schemas/Nope']));
    }

    public function testItFailsToCreateADocumentFromAnUnreadablePathOrInvalidJson(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');

        try {
            file_put_contents($path, '{nope');

            $this->assertThrows(fn () => Document::create($path), JsonException::class);
            $this->assertThrows(fn () => Document::create($path . '-missing'), InvalidApiDocumentationException::class);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param class-string<Throwable> $exception
     */
    private function assertThrows(callable $callback, string $exception): void
    {
        try {
            $callback();
        } catch (Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);

            return;
        }

        $this->fail("Expected {$exception}.");
    }
}
