<?php

namespace MyApplication;

class ProductsController {
    
  private $name;
  
  private $products = array(
    array('name' => "Cassete Recorder", 'sku' => 1, 'price' => 30.00),
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
    throw new \Exception("Unauthorized", 403);
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
   * POST /
   */
  function post($product) {
    $this->products[] = $product;
  }
  
  /** 
   * PATCH /sku
   */
  function patch($sku, $data) {
    $product = $this->get($sku);
    
    foreach($data as $key => $value) {
      $product->$key = $value;
    }
  }
  
  /**
   * PUT /sku
   */
  function put($sku, $data) {
    $product = &$this->get($sku);
    
    foreach(array_keys($product) as $key) {
      unset($product[$key]);
    }
    
    foreach($data as $key => $value) {
      $product[$key] = $value;
    }
    
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
   * This function will always override sale() for GET requests
   * The sale() function will act as a fallback for non-defined method (i.e. VIEW /products/sale)
   */
  function getSale() {
    return $this->sale(90);
  }

  /**
   * Handles a HEAD /sale request
   */
  function headSale() {
    header("X-Sale: true");
    return "";
  }
  
  /**
   * Handles an OPTIONS /salve request
   */
  function optionsSale() {
    return "Caught OPTIONS request on /sale";
  }
  
}