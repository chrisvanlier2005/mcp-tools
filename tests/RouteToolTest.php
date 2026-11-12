<?php

namespace Lier\McpTools\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Lier\McpTools\DispatchToRoute;
use Lier\McpTools\RouteTool;
use Lier\McpTools\RouteToolDiscovery;
use Lier\McpTools\Tests\Fixtures\FakeDispatchToRoute;
use Lier\McpTools\Tests\Fixtures\RestrictedRouteTool;

final class RouteToolTest extends TestCase
{
    public function testItTakesItsDefinitionFromTheApiDocumentation(): void
    {
        $tool = Arr::first(app(RouteToolDiscovery::class)->tools(), fn (RouteTool $tool) => $tool->name() === 'get-widgets');

        $this->assertNotNull($tool);

        $definition = $tool->toArray();
        $json = (string) json_encode($definition);

        $this->assertSame('List widgets', $definition['title'] ?? null);
        $this->assertSame('Retrieve the widgets.', $definition['description'] ?? null);
        $this->assertSame(['readOnlyHint' => true], $definition['annotations'] ?? null);
        $this->assertSame(['search'], array_keys((array) ($definition['inputSchema']['properties'] ?? [])));
        $this->assertSame(['data'], array_keys((array) ($definition['outputSchema']['properties'] ?? [])));
        $this->assertStringContainsString('"$defs":{"Widget"', $json);
        $this->assertStringNotContainsString('#/components/', $json);
    }

    public function testItMarksDeleteRoutesAsDestructive(): void
    {
        $tool = Arr::first(app(RouteToolDiscovery::class)->tools(), fn (RouteTool $tool) => $tool->name() === 'delete-widget');

        $this->assertNotNull($tool);
        $this->assertSame(['destructiveHint' => true], $tool->annotations());
        $this->assertFalse($tool->isReadOnly());
        $this->assertArrayNotHasKey('outputSchema', $tool->toArray());
    }

    public function testItDispatchesToTheRouteAndHidesInputAndOutput(): void
    {
        $tool = Arr::first(app(RouteToolDiscovery::class)->tools(), fn (RouteTool $tool) => $tool->name() === 'get-widgets');

        $this->assertNotNull($tool);

        $response = $tool->handle(new Request(['search' => 'foo', 'include' => 'secrets']), app(DispatchToRoute::class));

        $this->assertInstanceOf(ResponseFactory::class, $response);
        $this->assertSame(['data' => [['id' => '1', 'name' => 'foo']], 'include' => null], $response->getStructuredContent());
    }

    public function testItReturnsAnErrorForUnsuccessfulResponses(): void
    {
        $tool = Arr::first(app(RouteToolDiscovery::class)->tools(), fn (RouteTool $tool) => $tool->name() === 'show-widget');

        $this->assertNotNull($tool);

        $response = $tool->handle(new Request(['widget' => 'missing']), app(DispatchToRoute::class));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isError());
        $this->assertStringContainsString('Widget not found.', (string) $response->content());
    }

    public function testItAnswersDoneForEmptyResponses(): void
    {
        $tool = Arr::first(app(RouteToolDiscovery::class)->tools(), fn (RouteTool $tool) => $tool->name() === 'delete-widget');

        $this->assertNotNull($tool);

        $response = $tool->handle(new Request(['widget' => '1']), app(DispatchToRoute::class));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertFalse($response->isError());
        $this->assertSame('Done.', (string) $response->content());
    }

    public function testItRegistersEveryToolWithoutAGate(): void
    {
        foreach (app(RouteToolDiscovery::class)->tools() as $tool) {
            $this->assertTrue($tool->eligibleForRegistration());
        }
    }

    public function testAnAppCanOverrideWhichToolsAreRegistered(): void
    {
        config(['mcp-tools.tool' => RestrictedRouteTool::class]);

        $tools = app(RouteToolDiscovery::class)->tools();
        $read = Arr::first($tools, fn (RouteTool $tool) => $tool->name() === 'get-widgets');
        $write = Arr::first($tools, fn (RouteTool $tool) => $tool->name() === 'create-widget');

        $this->assertNotNull($read);
        $this->assertNotNull($write);

        $this->actingAs(new GenericUser(['id' => 2]));

        $this->assertTrue($read->eligibleForRegistration());
        $this->assertFalse($write->eligibleForRegistration());

        $this->actingAs(new GenericUser(['id' => 1]));

        $this->assertTrue($write->eligibleForRegistration());
    }

    public function testItShapesSuccessfulBodiesForMcp(): void
    {
        $tool = Arr::first(app(RouteToolDiscovery::class)->tools(), fn (RouteTool $tool) => $tool->name() === 'get-widgets');

        $this->assertNotNull($tool);

        $fake = app(FakeDispatchToRoute::class);

        foreach (['[]' => 'Done.', '{}' => 'Done.', '{"secret":1}' => 'Done.', 'OK' => 'OK', '"Done."' => '"Done."', '[1,2]' => '[1,2]'] as $body => $text) {
            $fake->body = (string) $body;
            $response = $tool->handle(new Request([]), $fake);

            $this->assertInstanceOf(Response::class, $response, $body);
            $this->assertSame($text, (string) $response->content(), $body);
        }
    }
}
