<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/

// Health check
$router->get('/', function () {
    return response()->json([
        'service' => config('app.name'),
        'version' => '1.0.0',
        'status' => 'healthy',
    ]);
});

$router->get('/health', function () {
    return response()->json(['status' => 'healthy']);
});

/*
|--------------------------------------------------------------------------
| Zencoder-Compatible API Routes (v2)
|--------------------------------------------------------------------------
*/

$router->group(['prefix' => 'v2', 'middleware' => 'auth.api'], function () use ($router) {
    // Jobs
    $router->post('/jobs', 'JobController@create');
    $router->get('/jobs', 'JobController@index');
    $router->get('/jobs/{id}', 'JobController@show');
    $router->get('/jobs/{id}/progress', 'JobController@progress');
    $router->put('/jobs/{id}/cancel', 'JobController@cancel');
    $router->put('/jobs/{id}/resubmit', 'JobController@resubmit');
    $router->post('/jobs/{id}/finish', 'JobController@finish');

    // Outputs
    $router->get('/outputs/{id}', 'OutputController@show');
    $router->get('/outputs/{id}/progress', 'OutputController@progress');

    // Account
    $router->get('/account', 'AccountController@show');
    $router->get('/reports/minutes', 'AccountController@minutesReport');
});

/*
|--------------------------------------------------------------------------
| API Routes (alias)
|--------------------------------------------------------------------------
*/

$router->group(['prefix' => 'api/v2', 'middleware' => 'auth.api'], function () use ($router) {
    // Jobs
    $router->post('/jobs', 'JobController@create');
    $router->get('/jobs', 'JobController@index');
    $router->get('/jobs/{id}', 'JobController@show');
    $router->get('/jobs/{id}/progress', 'JobController@progress');
    $router->put('/jobs/{id}/cancel', 'JobController@cancel');
    $router->put('/jobs/{id}/resubmit', 'JobController@resubmit');

    // Outputs
    $router->get('/outputs/{id}', 'OutputController@show');
    $router->get('/outputs/{id}/progress', 'OutputController@progress');

    // Account
    $router->get('/account', 'AccountController@show');
    $router->get('/reports/minutes', 'AccountController@minutesReport');
});
