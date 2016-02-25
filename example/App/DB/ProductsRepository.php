<?php

namespace App\DB;

use App\Model\Product;

/**
 * Example Data Repository for Products
 */
class ProductsRepository {
  
  // internal products storage
  private $products = array();
  
    public function __construct(array $products) {
        $this->products = $products;
    }
    
  // Create a new product
  public function post(Product $product) {
    $this->products[] = $product;
  }
  
  // List all products
  public function index() {
    return $this->products;
  }
  
  // Get a product by SKU
  public function get($sku) {
    foreach($this->products as $product) {
      if($product->sku == $sku) {
        return $product;
      }
    }
    
    throw new \Exception("Product not found", 404);
  }
  
  public function put($sku, Product $product) {
    
    if(!is_array($data)) {
      throw new \Exception('Invalid request', 406);
    }
    
    $product = &$this->get($sku);
    
    foreach($data as $k => $v) {
      $product->$k = $v;
    }
    
    return true;
  }
  
  public function count() {
    return count($this->products);
  }
}