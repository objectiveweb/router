<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Objectiveweb\Router;
use PHPUnit\Framework\TestCase;

class UrlTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['HTTP_HOST'] = 'internal.example:8080';
        $_SERVER['SERVER_NAME'] = 'internal.example';
        $_SERVER['SERVER_PORT'] = '8080';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

        unset(
            $_SERVER['HTTPS'],
            $_SERVER['SCRIPT_URL'],
            $_SERVER['PATH_INFO'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['HTTP_X_FORWARDED_HOST'],
            $_SERVER['HTTP_X_FORWARDED_PORT']
        );
    }

    public function testUrlAndRedirectAreInstanceMethods(): void
    {
        $this->assertFalse((new \ReflectionMethod(Router::class, 'url'))->isStatic());
        $this->assertFalse((new \ReflectionMethod(Router::class, 'redirect'))->isStatic());
    }

    public function testForwardedHeadersAreIgnoredByDefault(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'public.example';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

        $router = new Router();

        $this->assertSame(
            'http://internal.example:8080/index.php',
            $router->url()
        );
    }

    public function testTrustedExactProxyUsesForwardedHeaders(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'app.example';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

        $router = new Router(null, [
            'trusted.proxies' => ['10.0.0.12'],
        ]);

        $this->assertSame('https://app.example/index.php', $router->url());
    }

    public function testTrustedIpv4CidrUsesForwardedHeaders(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.42.7.19';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'app.example:8443';

        $router = new Router(null, [
            'trusted.proxies' => ['10.42.0.0/16'],
        ]);

        $this->assertSame('https://app.example:8443/index.php', $router->url());
    }

    public function testForwardedPortOverridesPortEmbeddedInForwardedHost(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'app.example:8443';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

        $router = new Router(null, [
            'trusted.proxies' => ['10.0.0.0/8'],
        ]);

        $this->assertSame('https://app.example/index.php', $router->url());
    }

    public function testTrustedIpv6CidrUsesForwardedHeaders(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:42::10';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = '[2001:db8:99::20]';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

        $router = new Router(null, [
            'trusted.proxies' => ['2001:db8:42::/48'],
        ]);

        $this->assertSame(
            'https://[2001:db8:99::20]/index.php',
            $router->url()
        );
    }

    public function testUntrustedPeerCannotSpoofForwardedHeaders(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

        $router = new Router(null, [
            'trusted.proxies' => ['10.0.0.0/8'],
        ]);

        $this->assertSame(
            'http://internal.example:8080/index.php',
            $router->url()
        );
    }

    public function testForwardedHeaderChainsAreIgnored(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http, https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example, app.example';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '80, 443';

        $router = new Router(null, [
            'trusted.proxies' => ['10.0.0.0/8'],
        ]);

        $this->assertSame(
            'http://internal.example:8080/index.php',
            $router->url()
        );
    }

    public function testUnsafeForwardedHostIsIgnored(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'user@app.example';
        $_SERVER['HTTP_X_FORWARDED_PORT'] = '443';

        $router = new Router(null, [
            'trusted.proxies' => ['10.0.0.0/8'],
        ]);

        $this->assertSame(
            'https://internal.example/index.php',
            $router->url()
        );
    }

    public function testForwardedSchemeWithoutPortUsesSchemeDefault(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'app.example';

        $router = new Router(null, [
            'trusted.proxies' => ['10.0.0.0/8'],
        ]);

        $this->assertSame('https://app.example/index.php', $router->url());
    }

    public function testInvalidTrustedProxyConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Router(null, [
            'trusted.proxies' => ['10.0.0.0/99'],
        ]);
    }


    public function testDirectHttpHostIsIgnoredByDefault(): void
    {
        $_SERVER['HTTP_HOST'] = 'attacker.example:9999';

        $router = new Router();

        $this->assertSame(
            'http://internal.example:8080/index.php',
            $router->url()
        );
    }

    public function testTrustedHostAllowsDirectHttpHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'public.example:8443';

        $router = new Router(null, [
            'trusted.hosts' => ['public.example'],
        ]);

        $this->assertSame(
            'http://public.example:8443/index.php',
            $router->url()
        );
    }

    public function testTrustedHostsMatchCaseInsensitivelyAndIgnoreTrailingDot(): void
    {
        $_SERVER['HTTP_HOST'] = 'PUBLIC.EXAMPLE.:8443';

        $router = new Router(null, [
            'trusted.hosts' => ['public.example'],
        ]);

        $this->assertSame(
            'http://PUBLIC.EXAMPLE.:8443/index.php',
            $router->url()
        );
    }

    public function testWildcardTrustedHostsAllowsAnyValidDirectHttpHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'tenant.example:8443';

        $router = new Router(null, [
            'trusted.hosts' => '*',
        ]);

        $this->assertSame(
            'http://tenant.example:8443/index.php',
            $router->url()
        );
    }

    public function testWildcardTrustedHostsStillRejectsMalformedHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'user@attacker.example';

        $router = new Router(null, [
            'trusted.hosts' => '*',
        ]);

        $this->assertSame(
            'http://internal.example:8080/index.php',
            $router->url()
        );
    }

    public function testTrustedHostsConfigurationRejectsInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Router(null, [
            'trusted.hosts' => 'example.com',
        ]);
    }

    public function testTrustedHostsConfigurationRejectsPorts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Router(null, [
            'trusted.hosts' => ['example.com:8443'],
        ]);
    }

    public function testRelativeUrlsUseScriptDirectoryInSubdirectory(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/apps/router/index.php';
        $_SERVER['PHP_SELF'] = '/apps/router/index.php';

        $router = new Router();

        $this->assertSame(
            'http://internal.example:8080/apps/router/index.php',
            $router->url()
        );
        $this->assertSame('/apps/router/assets/app.css', $router->url('assets/app.css'));
        $this->assertSame('/apps/router/assets/app.css', $router->url('/assets/app.css'));
    }

    public function testScriptUrlAndPathInfoKeepGeneratedUrlsAnchoredToFrontController(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/apps/router/index.php';
        $_SERVER['PHP_SELF'] = '/apps/router/index.php/products/42';
        $_SERVER['SCRIPT_URL'] = '/apps/router/index.php/products/42';
        $_SERVER['PATH_INFO'] = '/products/42';

        $router = new Router();

        $this->assertSame(
            'http://internal.example:8080/apps/router/index.php/products/42',
            $router->url()
        );
        $this->assertSame('/apps/router/index.php/edit', $router->url('edit'));
    }

    public function testZeroIsAValidRelativeUrlPath(): void
    {
        $router = new Router();

        $this->assertSame('/0', $router->url('0'));
    }

    public function testBracketedIpv6HostWithPort(): void
    {
        $_SERVER['HTTP_HOST'] = '[2001:db8::10]:8443';
        $_SERVER['SERVER_NAME'] = '2001:db8::10';
        $_SERVER['SERVER_PORT'] = '8443';

        $router = new Router(null, [
            'trusted.hosts' => ['2001:db8::10'],
        ]);

        $this->assertSame(
            'http://[2001:db8::10]:8443/index.php',
            $router->url()
        );
    }

    public function testTrustedForwardedBracketedIpv6HostWithPort(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = '[2001:db8:99::20]:8443';

        $router = new Router(null, [
            'trusted.proxies' => ['10.0.0.0/8'],
        ]);

        $this->assertSame(
            'https://[2001:db8:99::20]:8443/index.php',
            $router->url()
        );
    }

}
