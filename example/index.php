<?php

require dirname(__DIR__) . '/vendor/autoload.php';

require __DIR__ . '/App/Model/Product.php';
require __DIR__ . '/App/ProductsController.php';
require __DIR__ . '/App/DB/ProductsRepository.php';

use App\Model\Product;
use Objectiveweb\Router;

$app = new Router();

$app->addRule('App\DB\ProductsRepository', [
    'shared' => true,
    'constructParams' => [
        [
            new Product(1, 'Cassette Recorder', 100.00),
            new Product(2, 'Tractor Beam', 7.99),
        ],
    ],
]);

$app->GET('/?', function (array $query) {
    return <<<'HTML'
<!doctype html>
<html>
<body>
<h1>Router example</h1>

<h2>ProductsController</h2>
<ul>
    <li><a href="index.php/products">Product listing</a></li>
    <li><a href="index.php/products/1">Product detail</a></li>
    <li><a href="index.php/products/sale">HTTP-method-specific custom route</a></li>
    <li><a href="index.php/products/sale/50">Custom route with path parameter</a></li>
</ul>
</body>
</html>
HTML;
});

/*
 * Controller mapping examples:
 *
 * GET    /products            -> index($_GET)
 * GET    /products/1          -> get('1', $_GET)
 * POST   /products            -> post($body)
 * PUT    /products/1          -> put('1', $body)
 * GET    /products/sale       -> getSale($_GET), then sale($_GET), then get('sale', $_GET)
 *
 * Additional arguments passed to controller() are available to the
 * controller constructor through Dice.
 */
$app->controller('/products', App\ProductsController::class, 'Custom Name');
