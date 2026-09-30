<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Objectiveweb\Router;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// The built-in server invokes this file as its router script. Normalize the
// front-controller variables Objectiveweb Router expects from a web server.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REDIRECT_URL'] = $path;
$_SERVER['PATH_INFO'] = $path;

$router = new Router(null, [
    'debug' => $path === '/error-500-debug',
]);

$router->setCors('https://client.example');

$router->GET('/does-not-match-routing-order', static function (array $query): never {
    throw new RuntimeException('unmatched route executed');
});

$router->GET('/routing-order', static function (array $query): string {
    return 'first';
});

$router->GET('/routing-order', static function (array $query): string {
    return 'second';
});

$router->GET('/negotiate', static function (array $query): string {
    return '<p>Hello</p>';
});

$router->GET('/head', static function (array $query): string {
    return 'response body';
});

$router->GET('/cors', static function (array $query): array {
    return ['cors' => true];
});

$router->GET('/status/204', static function (array $query): never {
    Router::respond(['ignored' => true], 204);
});

$router->GET('/status/304', static function (array $query): never {
    Router::respond(['ignored' => true], 304);
});

$router->GET('/redirect-relative', function (array $query) use ($router): never {
    $router->redirect('/target', 302);
});

$router->GET('/redirect-absolute', function (array $query) use ($router): never {
    $router->redirect('https://example.com/target', 307);
});

$router->GET('/error-404', static function (array $query): never {
    throw new RuntimeException('Missing resource', 404);
});

$router->GET('/error-500', static function (array $query): never {
    throw new RuntimeException('sensitive server detail');
});

$router->GET('/error-500-debug', static function (array $query): never {
    throw new RuntimeException('debug server detail');
});
