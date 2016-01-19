<?php

namespace App;

class ProductsController {
    
  private $name;
  
  // emulate authentication for tests
  public $auth = false;
  
  // ProductsRepository will be injected automatically
  // $name is a random parameter to demonstrate additional parameters
  function __construct(\App\DB\ProductsRepository $products, $name = "Products Controller") {
      $this->products = $products;
      $this->name = $name;
  }
  
  /** 
   * (optional) runs before every request
   */
  function before() {
    if(isset($_GET['error'])) {
      // trigger an error (could be testing for auth, permissions, etc)
      throw new \Exception("error trigger detected", 500);
    }
  }
  
  /**
   * (optional) triggered before every POST request
   *
   * You can also use beforeGet(), beforePut(), beforeDelete() and so on
   * Important: before() will also be called before these methods
   */
  function beforePost() {
    if(!$this->auth) {
      throw new \Exception("Unauthorized", 403);
    }
  }
  
  // rest callbacks
  
  /**
   * GET /
   */
  function index() {
    return $this->products->index();
  }
  
  /**
   * GET /sku
   */
  function get($sku) {
    return $this->products->get($sku);
  }
  
  /**
   * POST / Example
   *
   * You may also handle other methods defining each function (put, patch, options, head, ...)
   */
  function post($product) {
    $this->products->post($product);
  }
  
  
  /**
   * This function will always override sale() for GET requests
   * The sale() function will act as a fallback for non-defined method (i.e. VIEW /products/sale)
   */
  function getSale() {
    return $this->sale(90);
  }
  
  /**
   * Handles requests to /sale
   */
  function sale($pct = 10) {
    return array_map(function($product) use($pct) {
      $product['price'] = $product['price'] * (1 - $pct/100);
      
      return $product;
    }, $this->products->index());
  }
  
  /**
   * Handles a HEAD /sale request
   */
  function headSale() {
    header("X-Sale: true");
    return "";
  }
  
  /**
   * Handles an OPTIONS /sale request
   */
  function optionsSale() {
    return $this->products->count();
  }
  
    
    function hello() {
        return "Hello $this->name";
    }
}