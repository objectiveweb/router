<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TestableRouter.php';

use PHPUnit\Framework\TestCase;
use Test\Router;

class SerializedServerException extends \RuntimeException {}

class CustomSerializedResponse
{
    public function __construct(
        public string $name,
        public int $count
    ) {
    }
}

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

    public function testHeadSuppressesBodyButPreservesRepresentation(): void
    {
        $response = Router::prepareHttpResponseForTest(
            ['ok' => true],
            200,
            'application/json',
            'HEAD'
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/json', $response['content_type']);
        $this->assertSame('', $response['body']);
    }

    public function testNoContentStatusesNeverContainBodyOrNegotiateRepresentation(): void
    {
        foreach ([101, 199, 204, 205, 304] as $status) {
            $response = Router::prepareHttpResponseForTest(
                ['ignored' => true],
                $status,
                'image/png'
            );

            $this->assertSame($status, $response['status']);
            $this->assertSame('', $response['body']);
            $this->assertNull($response['content_type']);
            $this->assertFalse($response['vary_accept']);
        }
    }

    public function testThrowablePreserves404ForHtmlClient(): void
    {
        $response = Router::prepareHttpResponseForTest(
            new \RuntimeException('Missing', 404),
            404,
            'text/html'
        );

        $this->assertSame(404, $response['status']);
        $this->assertSame('text/html', $response['content_type']);
        $this->assertStringContainsString('RuntimeException', $response['body']);
        $this->assertStringContainsString('Missing', $response['body']);
        $this->assertTrue($response['vary_accept']);
    }

    public function testThrowableRedacts500ForHtmlClientByDefault(): void
    {
        $response = Router::prepareHttpResponseForTest(
            new \TypeError('Database password: secret'),
            500,
            'text/html'
        );

        $this->assertSame(500, $response['status']);
        $this->assertSame('text/html', $response['content_type']);
        $this->assertStringContainsString('Internal Server Error', $response['body']);
        $this->assertStringNotContainsString('TypeError', $response['body']);
        $this->assertStringNotContainsString('secret', $response['body']);
    }

    public function testThrowableRedacts500ForJsonClientByDefault(): void
    {
        $response = Router::prepareHttpResponseForTest(
            new \RuntimeException('Database password: secret', 500),
            500,
            'application/json'
        );

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(500, $response['status']);
        $this->assertSame('application/json', $response['content_type']);
        $this->assertSame(['message' => 'Internal Server Error'], $body);
    }

    public function testDebugModeExposes500Details(): void
    {
        $response = Router::prepareHttpResponseForTest(
            new \RuntimeException('Detailed failure', 500),
            500,
            'application/json',
            null,
            true
        );

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(\RuntimeException::class, $body['exception']);
        $this->assertSame('Detailed failure', $body['message']);
    }

    public function testThrowablePreservesStatusForJsonClient(): void
    {
        $response = Router::prepareHttpResponseForTest(
            new \RuntimeException('Missing', 404),
            404,
            'application/json'
        );

        $this->assertSame(404, $response['status']);
        $this->assertSame('application/json', $response['content_type']);

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(\RuntimeException::class, $body['exception']);
        $this->assertSame('Missing', $body['message']);
    }

    public function testThrowableHtmlEscapesMessageInDebugMode(): void
    {
        $response = Router::prepareHttpResponseForTest(
            new \RuntimeException('<script>alert(1)</script>', 500),
            500,
            'text/html',
            null,
            true
        );

        $this->assertStringNotContainsString('<script>', $response['body']);
        $this->assertStringContainsString('&lt;script&gt;', $response['body']);
    }

    public function testThrowableStillReturns406ForUnsupportedRepresentation(): void
    {
        $response = Router::prepareHttpResponseForTest(
            new \RuntimeException('Missing', 404),
            404,
            'image/png'
        );

        $this->assertSame(406, $response['status']);
        $this->assertNull($response['content_type']);
    }

    public function testRegisteredSerializerProducesJsonThroughPrepareResponse(): void
    {
        Router::addSerializer(
            CustomSerializedResponse::class,
            static fn (CustomSerializedResponse $response): array => [
                'label' => strtoupper($response->name),
                'items' => $response->count,
            ]
        );

        $response = Router::prepareResponseForTest(
            new CustomSerializedResponse('widgets', 3),
            'application/json'
        );

        $this->assertSame('application/json', $response['content_type']);
        $this->assertSame(
            '{"label":"WIDGETS","items":3}',
            $response['body']
        );
        $this->assertFalse($response['vary_accept']);
    }

    public function testRegisteredExceptionSerializerIsNotRedacted(): void
    {
        Router::addSerializer(
            SerializedServerException::class,
            static fn (SerializedServerException $exception): array => [
                'error' => $exception->getMessage(),
            ]
        );

        $response = Router::prepareHttpResponseForTest(
            new SerializedServerException('intentional detail', 500),
            500,
            'application/json'
        );

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['error' => 'intentional detail'], $body);
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
