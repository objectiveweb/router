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

  public static function prepareResponseForTest($content, ?string $accept = null): array {
      return parent::prepareResponse($content, $accept);
  }


  public static function prepareHttpResponseForTest(
      $content,
      int $code = 200,
      ?string $accept = null,
      ?string $requestMethod = null
  ): array {
      return parent::prepareHttpResponse($content, $code, $accept, $requestMethod);
  }
}
