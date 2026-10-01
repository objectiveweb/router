<?php

require dirname(__DIR__) . '/vendor/autoload.php';

require __DIR__ . '/App/Model/Product.php';
require __DIR__ . '/App/HomeController.php';
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

// Requests to /products map to App\ProductsController.
// Root and unmatched controller names fall back to App\HomeController.
$app->run('App');
