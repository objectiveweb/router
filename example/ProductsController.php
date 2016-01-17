<?php

namespace MyApplication;

class ProductsController {
    
  private $name;
  
  // emulate authentication for tests
  public $auth = false;
  
  public $products = array(
    array('name' => "Cassete Recorder", 'sku' => 1, 'price' => 100.00),
    array('name' => "Tractor Beam", 'sku' => 2, 'price' => 7.99)
  );
  
  function __construct($name = "Products") {
    $this->name = $name;
  }
  
  /** 
   * (optional) runs before every request
   */
  function before() {
    $this->products[] = array('name' => "Added before dispatching $this->name", 'sku' => 5, 'price' => 666.66);
    
    if(isset($_GET['error'])) {
      // trigger an error (could be testing for auth, permissions, etc)
      throw new \Exception("error trigger detected", 500);
    }
  }
  
  /**
   * (optional) triggered before every POST request
   *
   * You can also use beforeGet(), beforePut(), beforeDelete() and so on
   * Important: before() will be called before these methods
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
    return $this->products;
  }
  
  /**
   * GET /sku
   */
  function &get($sku) {

    foreach($this->products as &$product) {
      if($product['sku'] == $sku) {
        return $product;
      }
    }
    
    throw new \Exception("Product not found", 404);
  }
  
  /**
   * POST / Example
   *
   * You may also handle other methods defining each function (put, patch, options, head, ...)
   */
  function post($product) {
    $this->products[] = $product;
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
    }, $this->products);
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
    return count($this->products);
  }
  
}