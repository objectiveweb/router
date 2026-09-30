<?php
namespace Test;

class Router extends \Objectiveweb\Router {

  static $response;
  static $code;
  
  public static function respond(
      mixed $value,
      int $code = 200,
      bool $debug = false
  ) {
      global $response_value, $response_code, $response_debug;
      $response_value = $value;
      $response_code = $code;
      $response_debug = $debug;
  }

  public static function prepareResponseForTest(
      mixed $content,
      ?string $accept = null,
      ?int $status = null,
      bool $debug = false
  ): array {
      return parent::prepareResponse($content, $accept, $status, $debug);
  }


  public static function prepareHttpResponseForTest(
      $content,
      int $code = 200,
      ?string $accept = null,
      ?string $requestMethod = null,
      bool $debug = false
  ): array {
      return parent::prepareHttpResponse(
          $content,
          $code,
          $accept,
          $requestMethod,
          $debug
      );
  }
}
