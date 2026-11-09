<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Document
    |--------------------------------------------------------------------------
    |
    | The OpenAPI JSON document the tool schemas are taken from. Defaults to
    | Scramble's export path (`php artisan scramble:export`).
    |
    */

    'openapi_path' => env('MCP_TOOLS_OPENAPI_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard
    |--------------------------------------------------------------------------
    |
    | The guard the MCP user is set on when a tool call is dispatched to its
    | route, e.g. "sanctum". Defaults to the application's default guard.
    |
    */

    'guard' => env('MCP_TOOLS_GUARD'),

    /*
    |--------------------------------------------------------------------------
    | Tool Class
    |--------------------------------------------------------------------------
    |
    | The class the annotated routes are made into. Extend RouteTool to
    | customize the tools, e.g. override shouldRegister() to restrict
    | which tools a user gets.
    |
    */

    'tool' => \Lier\McpTools\RouteTool::class,

];
