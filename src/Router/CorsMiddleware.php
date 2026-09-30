<?php

namespace Objectiveweb\Router;

class CorsMiddleware implements RequestMiddlewareInterface
{
    /**
     * @param array<int,string> $methods
     * @param array<int,string>|null $allowHeaders
     * @param array<int,string> $exposeHeaders
     */
    public function __construct(
        private string $origin,
        private bool $credentials = true,
        private array $methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private ?array $allowHeaders = null,
        private array $exposeHeaders = ['content-range']
    ) {
    }

    public function before(string $method, string $path): void
    {
        header('Access-Control-Allow-Origin: ' . $this->origin);

        if ($this->credentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        if ($this->exposeHeaders !== []) {
            header(
                'Access-Control-Expose-Headers: ' . implode(', ', $this->exposeHeaders)
            );
        }

        if (
            strtoupper($method) !== 'OPTIONS'
            || !isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])
            || !isset($_SERVER['HTTP_ORIGIN'])
        ) {
            return;
        }

        header(
            'Access-Control-Allow-Methods: ' . implode(', ', $this->methods)
        );

        $allowHeaders = $this->allowHeaders;
        if ($allowHeaders === null) {
            $requestedHeaders = trim((string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? ''));
            if ($requestedHeaders !== '') {
                header('Access-Control-Allow-Headers: ' . $requestedHeaders);
            }
        } elseif ($allowHeaders !== []) {
            header(
                'Access-Control-Allow-Headers: ' . implode(', ', $allowHeaders)
            );
        }

        http_response_code(204);
        exit('');
    }

    public function after(string $method, string $path, mixed $response): mixed
    {
        return $response;
    }
}
