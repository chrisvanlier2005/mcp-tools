<?php

namespace Lier\McpTools\Tests\Fixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Lier\McpTools\DispatchToRoute;
use Lier\McpTools\McpTool;

final class WidgetController
{
    #[McpTool('get-widgets', hideInput: ['include'], hideOutput: ['secret'])]
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'data' => [['id' => '1', 'name' => $request->string('search', 'all')->toString()]],
            'secret' => 'hidden',
            'include' => $request->query('include'),
        ]);
    }

    #[McpTool('create-widget')]
    public function store(Request $request): JsonResponse
    {
        return new JsonResponse(['id' => '2', 'name' => $request->string('name')->toString()], 201);
    }

    #[McpTool('show-widget')]
    public function show(string $widget): JsonResponse
    {
        abort_if($widget === 'missing', 404, 'Widget not found.');

        return new JsonResponse(['id' => $widget, 'name' => 'Widget']);
    }

    #[McpTool('delete-widget')]
    public function destroy(string $widget): Response
    {
        return new Response(status: 204);
    }

    #[McpTool('get-me')]
    public function me(): JsonResponse
    {
        return new JsonResponse(['id' => Auth::id()]);
    }

    public function marker(Request $request): JsonResponse
    {
        return new JsonResponse(['marked' => $request->attributes->get(DispatchToRoute::REQUEST_ATTRIBUTE)]);
    }

    #[McpTool('undocumented')]
    public function undocumented(): JsonResponse
    {
        return new JsonResponse([]);
    }

    public function plain(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
