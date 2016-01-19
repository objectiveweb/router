<?php
// Objectiveweb Router
// run() example

// library dependencies
// in real applications you should use an autoloader
include '../vendor/level-2/dice/Dice.php';
include '../src/Router.php';

// application dependencies
include 'App/HomeController.php';
include 'App/ProductsController.php';
include 'App/DB/ProductsRepository.php';

use Objectiveweb\Router;

$app = new Router();

// app configuration
$app->addRule('App\DB\ProductsRepository', [
    'shared' => true,
    'constructParams' => [
        array(
            array('name' => "Cassete Recorder", 'sku' => 1, 'price' => 100.00),
            array('name' => "Tractor Beam", 'sku' => 2, 'price' => 7.99)
        )
    ]
]);


// Starts the application on the App namespace
// Requests to /products will be mapped to App\ProductsController
// Root and other requests are mapped to App\HomeController
$app->run('App');