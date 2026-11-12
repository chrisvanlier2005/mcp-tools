<?php

namespace Lier\McpTools\Tests;

use Illuminate\Routing\Route;
use Lier\McpTools\Exceptions\InvalidApiDocumentationException;
use Lier\McpTools\McpTool;
use Lier\McpTools\OpenApi\Document;
use Lier\McpTools\OpenApi\Schema;
use Lier\McpTools\RouteToolDefinition;
use PHPUnit\Framework\TestCase;

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
}
