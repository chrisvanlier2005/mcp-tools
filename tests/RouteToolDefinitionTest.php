<?php

namespace Lier\McpTools\Tests;

use Error;
use Illuminate\Routing\Route;
use Lier\McpTools\Exceptions\InvalidApiDocumentationException;
use Lier\McpTools\McpTool;
use Lier\McpTools\OpenApi\Document;
use Lier\McpTools\OpenApi\Schema;
use Lier\McpTools\RouteToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

final class RouteToolDefinitionTest extends TestCase
{
    public function testItUsesTheDocumentedMethodOfTheRoute(): void
    {
        $route = new Route(['PUT', 'PATCH'], 'assets/{asset}', [
            'uses' => 'Lier\McpTools\Tests\Fixtures\WidgetController@update',
            'controller' => 'Lier\McpTools\Tests\Fixtures\WidgetController@update',
        ]);

        $document = new Document(new Schema([
            'paths' => [
                '/assets/{asset}' => [
                    'patch' => ['summary' => 'Update asset'],
                ],
            ],
        ]));

        $definition = RouteToolDefinition::make($route, new McpTool('update-asset'), $document);

        $this->assertSame('PATCH', $definition->method);
        $this->assertSame('Update asset', $definition->title);
    }

    public function testItFallsBackFromEmptyTitlesAndDescriptions(): void
    {
        $route = new Route(['GET'], 'assets', [
            'uses' => 'Lier\McpTools\Tests\Fixtures\WidgetController@index',
            'controller' => 'Lier\McpTools\Tests\Fixtures\WidgetController@index',
        ]);

        $document = new Document(new Schema(['paths' => ['/assets' => ['get' => ['summary' => '', 'description' => '']]]]));

        $definition = RouteToolDefinition::make($route, new McpTool('get-assets', title: '', description: ''), $document);

        $this->assertSame('Get Assets', $definition->title);
        $this->assertSame('Get Assets', $definition->description);
    }

    public function testItFailsWhenNoneOfTheRouteMethodsIsDocumented(): void
    {
        $route = new Route(['PUT', 'PATCH'], 'assets/{asset}', [
            'uses' => 'Lier\McpTools\Tests\Fixtures\WidgetController@update',
            'controller' => 'Lier\McpTools\Tests\Fixtures\WidgetController@update',
        ]);

        $this->expectExceptionObject(InvalidApiDocumentationException::undocumentedOperation('PUT', '/assets/{asset}'));

        RouteToolDefinition::make($route, new McpTool('update-asset'), new Document(new Schema()));
    }

    public function testItRoundTripsThroughItsArrayRepresentation(): void
    {
        $definition = new RouteToolDefinition(
            action: 'App\Http\Controllers\TaskController@index',
            uri: 'api/tasks',
            method: 'GET',
            attribute: new McpTool(
                name: 'get-tasks',
                title: 'Tasks',
                description: 'Lists tasks.',
                hideInput: ['include'],
                hideOutput: ['data.*.secret'],
                readOnly: true,
                destructive: false,
                idempotent: true,
                openWorld: false,
            ),
            title: 'List tasks',
            description: 'Retrieve the tasks.',
            inputSchema: new Schema(['type' => 'object', 'properties' => ['page' => ['type' => 'integer']]]),
            outputSchema: new Schema(['type' => 'object', 'required' => ['data']]),
        );

        $this->assertEquals($definition, RouteToolDefinition::fromArray($definition->toArray()));
    }

    public function testItRestoresMissingSchemasAsEmptyOrNull(): void
    {
        $definition = RouteToolDefinition::fromArray([
            'action' => 'a', 'uri' => 'u', 'method' => 'GET', 'attribute' => ['name' => 'n'], 'title' => 't', 'description' => 'd',
        ]);

        $this->assertSame([], $definition->inputSchema->schema);
        $this->assertNull($definition->outputSchema);
        $this->assertSame([], $definition->attribute->hideInput);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'missing action' => [['action' => null]];
        yield 'numeric uri' => [['uri' => 5]];
        yield 'array method' => [['method' => []]];
        yield 'string attribute' => [['attribute' => 'n']];
        yield 'list attribute' => [['attribute' => ['n']]];
        yield 'missing title' => [['title' => null]];
        yield 'numeric description' => [['description' => 1]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidDefinitions')]
    public function testItRejectsInvalidCacheShapes(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteToolDefinition::fromArray($override + [
            'action' => 'a', 'uri' => 'u', 'method' => 'GET', 'attribute' => ['name' => 'n'], 'title' => 't', 'description' => 'd',
        ]);
    }

    public function testItRejectsAttributesThatDontFitTheMcpTool(): void
    {
        foreach ([['title' => 'no name'], ['name' => 'n', 'unknown' => 1], ['name' => 'n', 'hideInput' => 'x']] as $attribute) {
            try {
                RouteToolDefinition::fromArray([
                    'action' => 'a', 'uri' => 'u', 'method' => 'GET', 'attribute' => $attribute, 'title' => 't', 'description' => 'd',
                ]);

                $this->fail('Expected an error for ' . json_encode($attribute));
            } catch (Error) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItUsesTheFirstDocumentedMethodAndDefaultsToGet(): void
    {
        $document = new Document(new Schema(['paths' => ['/a' => ['post' => ['summary' => 'S']]]]));

        $post = RouteToolDefinition::make(new Route(['POST', 'GET'], 'a', ['uses' => 'X@y']), new McpTool('t'), $document);

        $this->assertSame('POST', $post->method);
        $this->assertSame('S', $post->title);
        $this->assertSame('S', $post->description);
        $this->assertSame('a', $post->uri);
        $this->assertNull($post->outputSchema);
        $this->assertSame(['type' => 'object', 'properties' => []], $post->inputSchema->schema);
    }

    public function testItIgnoresHeadAndPrefersTheAttributeOverTheDocumentation(): void
    {
        $document = new Document(new Schema(['paths' => ['/a' => ['get' => ['summary' => 'S', 'description' => 'D']]]]));

        $definition = RouteToolDefinition::make(
            new Route(['GET', 'HEAD'], '/a', ['uses' => 'X@y']),
            new McpTool('some-tool', title: 'Mine', description: 'Own'),
            $document,
        );

        $this->assertSame('GET', $definition->method);
        $this->assertSame('Mine', $definition->title);
        $this->assertSame('Own', $definition->description);
    }

    public function testItResolvesComponentsAndHidesPropertiesWhenMaking(): void
    {
        $document = new Document(new Schema([
            'paths' => ['/a' => ['get' => [
                'parameters' => [['name' => 'include', 'in' => 'query', 'schema' => ['type' => 'string']]],
                'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/T']]]]],
            ]]],
            'components' => ['schemas' => ['T' => ['type' => 'object', 'properties' => ['id' => [], 'secret' => []], 'required' => ['secret']]]],
        ]));

        $definition = RouteToolDefinition::make(
            new Route(['GET'], 'a', ['uses' => 'X@y']),
            new McpTool('t', hideInput: ['include'], hideOutput: ['secret']),
            $document,
        );

        $this->assertSame([], $definition->inputSchema->properties());
        $this->assertSame(['id'], array_keys($definition->outputSchema?->properties() ?? []));
        $this->assertSame([], $definition->outputSchema?->required());
    }
}
