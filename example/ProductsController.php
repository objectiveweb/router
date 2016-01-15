<?php

class ProductsController {
  
  public $model = 'Product';
  
  private $products = array(
    array('name' => "Cassete Recorder", 'sku' => 1, 'price' => 30.00),
    array('name' => "Tractor Beam", 'sku' => 2, 'price' => 7.99)
  );
  
  /** 
   * (optional) runs before every request
   */
  function before() {
    $this->products[] = array('name' => 'Added before dispatching', 'sku' => 5, 'price' => 666.66);
    
    if(isset($_GET['error'])) {
      // trigger an error (could be testing for auth, permissions, etc)
      throw new Exception("error trigger detected", 500);
    }
  }
  
  function index() {
    return $this->products;
  }
  
  function get($sku) {
    
    foreach($this->products as $product) {
      if($product['sku'] == $sku) {
        return $product;
      }
    }
    
    throw new Exception("Product not found", 404);
  }
  
  function post($product) {
    
  }
  
  function sale($pct = 10) {
    return array_map(function($product) use($pct) {
      $product['price'] = $product['price'] * (1 - $pct/100);
      
      return $product;
    }, $this->products);
  }
}