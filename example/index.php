<?php

// in real applications you should use composer and include the generated autoloader
include '../src/Router.php';
include 'ProductsController.php';

use Objectiveweb\Router;

Router::GET("/", function() {
  return <<< EOF
  <html>
  <body>
  <h1>Router Example page</h1>
  
  <h2>ProductsController</h2>
  <ul>
   <li><a href="index.php/products">Index: Products listing (json)</a></li>
   <li><a href="index.php/products/1">Path variable: Product detail</a></li>
   <li><a href="index.php/products/sale">Custom method: Products with 10% discount</a></li>
   <li><a href="index.php/products/sale/50">Custom method with path parameters: Products with 50% discount</a></li>
   <li><a href="index.php/products/anything/really?error=1">before(): Check error trigger</a></li>
EOF;
});

/**
 * Router::controller will bind a path to a class, using the following schema
 *
 * GET /    => $controller->index();
 * POST /   => $controller->post($decoded_post_body);
 * PUT /    => $controller->put($decoded_post_body);
 * PATCH /    => $controller->patch($decoded_post_body);
 *
 * Path parameters
 *
 * GET|PATCH|POST|PUT|DELETE /path[/path1/path2/...]
 *  if $controller->path() exists, calls $controller->path($path1, $path2, ...)
 *
 * When $controller->path() does not exist
 *
 * GET /path[/path1/path2/...]
 *  calls $controller->get($path, path1, $path1, ..., $_GET);
 * DELETE /path[/path1/path2/...]
 *  calls $controller->delete($path, path1, $path1, ..., $_GET);
 * POST /path[/path1/path2/...]
 *  $controller->post($path, path1, $path1, ..., $decoded_post_body);
 * PUT /path[/path1/path2/...]
 *  calls $controller->put($path, path1, $path1, ..., $decoded_post_body);
 * PATCH /path[/path1/path2/...]
 *  calls $controller->patch($path, path1, $path1, ..., $decoded_post_body);
 *
 * Additional parameters are passed to the class constructor
 */
Router::controller("/products", 'MyApplication\ProductsController', "Custom Name");