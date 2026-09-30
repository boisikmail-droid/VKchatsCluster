<?php

/** @var \Laravel\Lumen\Routing\Router $router */

$router->get('/', function () {
    return response()->json([
        'service' => 'vk-chatbot',
        'status' => 'ok',
    ]);
});

$router->get('/health', function () {
    try {
        app('db')->connection()->getPdo();
    } catch (Throwable $e) {
        return response()->json([
            'status' => 'degraded',
            'database' => 'down',
        ], 503);
    }

    return response()->json([
        'status' => 'ok',
        'database' => 'up',
    ]);
});

$router->post('/api/vk/callback', 'VkCallbackController@handle');

$router->group(['prefix' => 'api', 'middleware' => 'admin'], function () use ($router) {
    $router->get('/groups', 'GroupController@index');
    $router->post('/groups', 'GroupController@store');
    $router->get('/groups/{id}', 'GroupController@show');
    $router->post('/groups/{id}/sync', 'GroupController@sync');
    $router->get('/groups/{id}/members', 'GroupController@members');
    $router->get('/groups/{id}/activity', 'GroupController@activity');
    $router->get('/groups/{id}/stats', 'GroupController@stats');
});
