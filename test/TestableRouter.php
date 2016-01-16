<?php
namespace Test;

class Router extends \Objectiveweb\Router {

  static $response;
  static $code;
  
  static function respond($value, $code = 200) {
    self::$response = $value;
    self::$code = $code;
  }
}