<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use PHPUnit\Framework\TestCase;

class HttpIntegrationTest extends TestCase
{
    private static $server;
    private static int $port;
    private static string $stdoutLog;
    private static string $stderrLog;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::reservePort();
        self::$stdoutLog = tempnam(sys_get_temp_dir(), 'router-http-out-');
        self::$stderrLog = tempnam(sys_get_temp_dir(), 'router-http-err-');

        $fixture = __DIR__ . '/http/server.php';
        $command = [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            '-S',
            '127.0.0.1:' . self::$port,
            $fixture,
        ];

        self::$server = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', self::$stdoutLog, 'a'],
                2 => ['file', self::$stderrLog, 'a'],
            ],
            $pipes,
            dirname(__DIR__)
        );

        if (!is_resource(self::$server)) {
            throw new RuntimeException('Unable to start PHP built-in server');
        }

        fclose($pipes[0]);

        $deadline = microtime(true) + 5;
        do {
            $socket = @stream_socket_client(
                'tcp://127.0.0.1:' . self::$port,
                $errno,
                $errstr,
                0.1
            );

            if (is_resource($socket)) {
                fclose($socket);
                return;
            }

            usleep(25_000);
        } while (microtime(true) < $deadline);

        $stderr = @file_get_contents(self::$stderrLog) ?: '';
        self::stopServer();

        throw new RuntimeException(
            "PHP built-in server did not start. stderr: $stderr"
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        @unlink(self::$stdoutLog);
        @unlink(self::$stderrLog);
    }

    public function testHtmlNegotiationEmitsStatusContentTypeVaryAndBody(): void
    {
        $response = $this->request('GET', '/negotiate', [
            'Accept' => 'text/html',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertHeaderContains($response, 'content-type', 'text/html; charset=utf-8');
        $this->assertHeaderContains($response, 'vary', 'Accept');
        $this->assertSame('<p>Hello</p>', $response['body']);
    }

    public function testJsonNegotiationEmitsJsonRepresentation(): void
    {
        $response = $this->request('GET', '/negotiate', [
            'Accept' => 'application/json',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertHeaderContains($response, 'content-type', 'application/json; charset=utf-8');
        $this->assertHeaderContains($response, 'vary', 'Accept');
        $this->assertSame('"<p>Hello<\/p>"', $response['body']);
    }

    public function testUnsupportedAcceptEmits406AndEmptyBody(): void
    {
        $response = $this->request('GET', '/negotiate', [
            'Accept' => 'image/png',
        ]);

        $this->assertSame(406, $response['status']);
        $this->assertHeaderContains($response, 'vary', 'Accept');
        $this->assertSame('', $response['body']);
    }

    public function testHeadUsesGetHeadersButEmitsNoBody(): void
    {
        $response = $this->request('HEAD', '/head', [
            'Accept' => 'text/html',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertHeaderContains($response, 'content-type', 'text/html; charset=utf-8');
        $this->assertSame('', $response['body']);
    }

    public function test204And304EmitNoBody(): void
    {
        foreach ([204, 304] as $status) {
            $response = $this->request('GET', "/status/$status", [
                'Accept' => 'application/json',
            ]);

            $this->assertSame($status, $response['status']);
            $this->assertSame('', $response['body']);
        }
    }

    public function testRelativeRedirectEmitsLocationAndStatus(): void
    {
        $response = $this->request('GET', '/redirect-relative');

        $this->assertSame(302, $response['status']);
        $this->assertHeaderContains($response, 'location', '/target');
        $this->assertSame('', $response['body']);
    }

    public function testAbsoluteRedirectPassesLocationThrough(): void
    {
        $response = $this->request('GET', '/redirect-absolute');

        $this->assertSame(307, $response['status']);
        $this->assertHeaderContains(
            $response,
            'location',
            'https://example.com/target'
        );
        $this->assertSame('', $response['body']);
    }

    public function testCorsSimpleRequestEmitsCorsHeaders(): void
    {
        $response = $this->request('GET', '/cors', [
            'Origin' => 'https://client.example',
            'Accept' => 'application/json',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertHeaderContains(
            $response,
            'access-control-allow-origin',
            'https://client.example'
        );
        $this->assertHeaderContains(
            $response,
            'access-control-allow-credentials',
            'true'
        );
        $this->assertHeaderContains(
            $response,
            'access-control-expose-headers',
            'content-range'
        );
        $this->assertSame('{"cors":true}', $response['body']);
    }

    public function testCorsPreflightTerminatesWith204AndActualHeaders(): void
    {
        $response = $this->request('OPTIONS', '/cors', [
            'Origin' => 'https://client.example',
            'Access-Control-Request-Method' => 'PATCH',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type',
        ]);

        $this->assertSame(204, $response['status']);
        $this->assertHeaderContains(
            $response,
            'access-control-allow-origin',
            'https://client.example'
        );
        $this->assertHeaderContains(
            $response,
            'access-control-allow-methods',
            'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS'
        );
        $this->assertHeaderContains(
            $response,
            'access-control-allow-headers',
            'Authorization, Content-Type'
        );
        $this->assertSame('', $response['body']);
    }

    public function test404PreservesDetailedJsonError(): void
    {
        $response = $this->request('GET', '/error-404', [
            'Accept' => 'application/json',
        ]);

        $this->assertSame(404, $response['status']);
        $this->assertHeaderContains(
            $response,
            'content-type',
            'application/json; charset=utf-8'
        );

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(RuntimeException::class, $body['exception']);
        $this->assertSame('Missing resource', $body['message']);
    }

    public function test500IsRedactedAtActualHttpBoundary(): void
    {
        $response = $this->request('GET', '/error-500', [
            'Accept' => 'application/json',
        ]);

        $this->assertSame(500, $response['status']);

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['message' => 'Internal Server Error'], $body);
        $this->assertStringNotContainsString(
            'sensitive server detail',
            $response['body']
        );
    }

    public function testDebug500ExposesDetailsAtActualHttpBoundary(): void
    {
        $response = $this->request('GET', '/error-500-debug', [
            'Accept' => 'application/json',
        ]);

        $this->assertSame(500, $response['status']);

        $body = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(RuntimeException::class, $body['exception']);
        $this->assertSame('debug server detail', $body['message']);
    }

    private static function reservePort(): int
    {
        $socket = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr
        );

        if (!is_resource($socket)) {
            throw new RuntimeException(
                "Unable to reserve local HTTP port: $errstr ($errno)"
            );
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false || !preg_match('/:(\d+)$/', $name, $matches)) {
            throw new RuntimeException('Unable to determine reserved HTTP port');
        }

        return (int) $matches[1];
    }

    private static function stopServer(): void
    {
        if (!is_resource(self::$server)) {
            return;
        }

        proc_terminate(self::$server);
        proc_close(self::$server);
        self::$server = null;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,list<string>>,body:string}
     */
    private function request(
        string $method,
        string $path,
        array $headers = [],
        ?string $body = null
    ): array {
        $socket = stream_socket_client(
            'tcp://127.0.0.1:' . self::$port,
            $errno,
            $errstr,
            5
        );

        if (!is_resource($socket)) {
            $stderr = @file_get_contents(self::$stderrLog) ?: '';
            $this->fail(
                "Unable to connect to test server: $errstr ($errno)\n$stderr"
            );
        }

        $requestHeaders = [
            'Host' => '127.0.0.1:' . self::$port,
            'Connection' => 'close',
            ...$headers,
        ];

        if ($body !== null) {
            $requestHeaders['Content-Length'] = (string) strlen($body);
        }

        $request = "$method $path HTTP/1.1\r\n";
        foreach ($requestHeaders as $name => $value) {
            $request .= "$name: $value\r\n";
        }
        $request .= "\r\n";
        $request .= $body ?? '';

        fwrite($socket, $request);
        $raw = stream_get_contents($socket);
        fclose($socket);

        if ($raw === false || !str_contains($raw, "\r\n\r\n")) {
            $stderr = @file_get_contents(self::$stderrLog) ?: '';
            $this->fail("Malformed HTTP response:\n$raw\nServer stderr:\n$stderr");
        }

        [$rawHeaders, $responseBody] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $rawHeaders);
        $statusLine = array_shift($lines);

        if (!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $statusLine, $matches)) {
            $this->fail("Malformed HTTP status line: $statusLine");
        }

        $parsedHeaders = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($name))][] = trim($value);
        }

        return [
            'status' => (int) $matches[1],
            'headers' => $parsedHeaders,
            'body' => $responseBody,
        ];
    }

    /**
     * @param array{headers:array<string,list<string>>} $response
     */
    private function assertHeaderContains(
        array $response,
        string $name,
        string $expected
    ): void {
        $values = $response['headers'][strtolower($name)] ?? [];

        $this->assertContains(
            $expected,
            $values,
            sprintf(
                'Expected header %s: %s; got %s',
                $name,
                $expected,
                implode(' | ', $values)
            )
        );
    }
}
