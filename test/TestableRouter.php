<?php
namespace Test;

class Router extends \Objectiveweb\Router {

  static $response;
  static $code;
  
  static function respond($value, $code = 200) {
      
      global $response_value, $response_code;
      $response_value = $value;
      $response_code = $code;
  }
}