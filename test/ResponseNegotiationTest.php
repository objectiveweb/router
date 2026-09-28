<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TestableRouter.php';

use PHPUnit\Framework\TestCase;
use Test\Router;

class ResponseNegotiationTest extends TestCase
{
    public function testMissingAcceptUsesServerPreference(): void
    {
        $this->assertSame(
            'text/html',
            Router::negotiateContentType(['text/html', 'application/json'])
        );
    }

    public function testQualityValuesAreHonored(): void
    {
        $this->assertSame(
            'application/json',
            Router::negotiateContentType(
                ['text/html', 'application/json'],
                'text/html;q=0.4, application/json;q=0.9'
            )
        );
    }

    public function testSpecificExclusionOverridesWildcard(): void
    {
        $this->assertSame(
            'application/json',
            Router::negotiateContentType(
                ['text/html', 'application/json'],
                'text/html;q=0, */*;q=1'
            )
        );
    }

    public function testUnsupportedAcceptReturnsNull(): void
    {
        $this->assertNull(
            Router::negotiateContentType(
                ['text/html', 'application/json'],
                'image/png'
            )
        );
    }

    public function testJsonStringIsEncodedAsJsonScalar(): void
    {
        $response = Router::prepareResponseForTest('hello', 'application/json');

        $this->assertSame('application/json', $response['content_type']);
        $this->assertSame('"hello"', $response['body']);
        $this->assertTrue($response['vary_accept']);
    }

    public function testHtmlStringRemainsRaw(): void
    {
        $response = Router::prepareResponseForTest('<strong>Hello</strong>', 'text/html');

        $this->assertSame('text/html', $response['content_type']);
        $this->assertSame('<strong>Hello</strong>', $response['body']);
    }

    public function testStructuredResponseRejectsHtmlOnlyRequest(): void
    {
        $response = Router::prepareResponseForTest(['ok' => true], 'text/html');

        $this->assertNull($response['content_type']);
        $this->assertSame('', $response['body']);
    }

    public function testRenderableObjectCanProduceHtmlOrJson(): void
    {
        $content = new class {
            public string $value = 'test';

            public function render(): string
            {
                return '<p>test</p>';
            }
        };

        $html = Router::prepareResponseForTest($content, 'text/html');
        $this->assertSame('text/html', $html['content_type']);
        $this->assertSame('<p>test</p>', $html['body']);

        $json = Router::prepareResponseForTest($content, 'application/json');
        $this->assertSame('application/json', $json['content_type']);
        $this->assertStringContainsString('"value":"test"', $json['body']);
    }

    public function testStructuredRenderResultFallsBackToJsonWhenAccepted(): void
    {
        $content = new class {
            public function render(): array
            {
                return ['ok' => true];
            }
        };

        $response = Router::prepareResponseForTest($content, '*/*');

        $this->assertSame('application/json', $response['content_type']);
        $this->assertSame('{"ok":true}', $response['body']);
    }

    public function testStructuredRenderResultIsNotAcceptableForHtmlOnly(): void
    {
        $content = new class {
            public function render(): array
            {
                return ['ok' => true];
            }
        };

        $response = Router::prepareResponseForTest($content, 'text/html');

        $this->assertNull($response['content_type']);
    }
}
