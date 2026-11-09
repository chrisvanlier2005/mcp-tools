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
