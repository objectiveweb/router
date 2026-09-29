<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Objectiveweb\Router;
use PHPUnit\Framework\TestCase;

class RequestBodyTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
    }

    public function testApplicationJsonDecodesJsonScalars(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';

        $_POST = 'true';
        $this->assertTrue(Router::parse_post_body());

        $_POST = '123';
        $this->assertSame(123, Router::parse_post_body());

        $_POST = '"hello"';
        $this->assertSame('hello', Router::parse_post_body());

        $_POST = 'null';
        $this->assertNull(Router::parse_post_body());
    }

    public function testMalformedJsonThrows400(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_POST = '{"ok":';

        try {
            Router::parse_post_body();
            $this->fail('Expected malformed JSON to throw');
        } catch (\RuntimeException $ex) {
            $this->assertSame(400, $ex->getCode());
            $this->assertSame('Invalid JSON request body', $ex->getMessage());
            $this->assertInstanceOf(\JsonException::class, $ex->getPrevious());
        }
    }

    public function testStructuredJsonMediaTypeIsDecoded(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/problem+json';
        $_POST = '{"title":"Invalid request"}';

        $this->assertSame(
            ['title' => 'Invalid request'],
            Router::parse_post_body()
        );
    }

    public function testJsonCanBeDecodedAsObject(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_POST = '{"ok":true}';

        $body = Router::parse_post_body(true, false);

        $this->assertInstanceOf(stdClass::class, $body);
        $this->assertTrue($body->ok);
    }

    public function testUrlEncodedBodyIsParsed(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = 'name=Router&enabled=1';

        $this->assertSame(
            ['name' => 'Router', 'enabled' => '1'],
            Router::parse_post_body()
        );
    }

    public function testPhpParsedFormDataIsReturnedDirectly(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['name' => 'Router'];

        $this->assertSame(['name' => 'Router'], Router::parse_post_body());
    }

    public function testMultipartBodyUsesPhpPostData(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
        $_POST = ['name' => 'Router'];

        $this->assertSame(['name' => 'Router'], Router::parse_post_body());
    }

    public function testUnknownContentTypeRemainsRaw(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        $_POST = 'name=Router&enabled=1';

        $this->assertSame(
            'name=Router&enabled=1',
            Router::parse_post_body()
        );
    }

    public function testMissingContentTypeDoesNotGuessJson(): void
    {
        $_POST = '{"ok":true}';

        $this->assertSame('{"ok":true}', Router::parse_post_body());
    }

    public function testDecodedFalseAlwaysReturnsRawBody(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_POST = '{"ok":true}';

        $this->assertSame(
            '{"ok":true}',
            Router::parse_post_body(false)
        );
    }
}
