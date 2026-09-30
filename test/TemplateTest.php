<?php

require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/TestableRouter.php';

use Objectiveweb\Router as BaseRouter;
use Objectiveweb\Router\Template;
use PHPUnit\Framework\TestCase;
use Test\Router;

class TemplateTest extends TestCase
{
    private array $files = [];
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (array_reverse($this->directories) as $directory) {
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }

        global $response_value, $response_code;
        $response_value = null;
        $response_code = null;
    }

    public function testDefaultTemplateRootUsesComposerProjectRoot(): void
    {
        $templates = dirname(__DIR__) . '/templates';
        $createdTemplatesDirectory = false;

        if (!is_dir($templates)) {
            mkdir($templates, 0777, true);
            $this->directories[] = $templates;
            $createdTemplatesDirectory = true;
        }

        $file = $templates . '/default-root-test.php';
        file_put_contents($file, '<?= $value ?>');
        $this->files[] = $file;

        $router = new BaseRouter();
        $template = $router->template('default-root-test', ['value' => 'project-root']);

        $this->assertInstanceOf(Template::class, $template);
        $this->assertSame('project-root', $template->render());
    }

    public function testTemplateDataCannotOverwriteRendererVariables(): void
    {
        $root = sys_get_temp_dir() . '/objectiveweb-router-template-scope-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $this->directories[] = $root;

        $file = $root . '/scope.php';
        file_put_contents(
            $file,
            '<?= $file ?>:<?= $data["state"] ?>:<?= basename($__file) ?>:<?= $value ?>'
        );
        $this->files[] = $file;

        $template = new Template($root, 'scope', [
            'file' => 'user-file',
            'data' => ['state' => 'user-data'],
            '__file' => '/tmp/overridden.php',
            'value' => 'ok',
        ]);

        $this->assertSame('user-file:user-data:scope.php:ok', $template->render());
    }

    public function testLayoutReceivesRenderedContentsEvenWhenDataContainsContentsKey(): void
    {
        $root = sys_get_temp_dir() . '/objectiveweb-router-template-layout-' . bin2hex(random_bytes(8));
        $layouts = $root . '/_layouts';
        mkdir($layouts, 0777, true);
        $this->directories[] = $layouts;
        $this->directories[] = $root;

        $templateFile = $root . '/page.php';
        $layoutFile = $layouts . '/main.php';

        file_put_contents($templateFile, 'body:<?= $value ?>');
        file_put_contents($layoutFile, '<main><?= $_contents ?></main>');

        $this->files[] = $templateFile;
        $this->files[] = $layoutFile;

        $template = new Template(
            $root,
            'page',
            [
                'value' => 'ok',
                '_contents' => 'attacker-controlled',
            ],
            'main'
        );

        $this->assertSame('<main>body:ok</main>', $template->render());
    }

    public function testTemplateExceptionRestoresOutputBufferLevel(): void
    {
        $root = sys_get_temp_dir() . '/objectiveweb-router-template-throw-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $this->directories[] = $root;

        $file = $root . '/throws.php';
        file_put_contents(
            $file,
            '<?php ob_start(); echo "nested"; throw new RuntimeException("template failed");'
        );
        $this->files[] = $file;

        $template = new Template($root, 'throws');
        $level = ob_get_level();

        try {
            $template->render();
            $this->fail('Expected template rendering to throw');
        } catch (\RuntimeException $exception) {
            $this->assertSame('template failed', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }

    public function testLayoutExceptionRestoresOutputBufferLevel(): void
    {
        $root = sys_get_temp_dir() . '/objectiveweb-router-layout-throw-' . bin2hex(random_bytes(8));
        $layouts = $root . '/_layouts';
        mkdir($layouts, 0777, true);
        $this->directories[] = $layouts;
        $this->directories[] = $root;

        $templateFile = $root . '/page.php';
        $layoutFile = $layouts . '/main.php';

        file_put_contents($templateFile, 'body');
        file_put_contents(
            $layoutFile,
            '<?php ob_start(); echo "nested-layout"; throw new RuntimeException("layout failed");'
        );

        $this->files[] = $templateFile;
        $this->files[] = $layoutFile;

        $template = new Template($root, 'page', [], 'main');
        $level = ob_get_level();

        try {
            $template->render();
            $this->fail('Expected layout rendering to throw');
        } catch (\RuntimeException $exception) {
            $this->assertSame('layout failed', $exception->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }

    public function testMissingAcceptPrefersHtmlTemplate(): void
    {
        global $response_value;

        [$router, $controller] = $this->createNegotiatedTemplateRoute();
        unset($_SERVER['HTTP_ACCEPT']);

        $router->controller('/', $controller);

        $this->assertInstanceOf(Template::class, $response_value);
    }

    public function testWildcardAcceptPrefersHtmlTemplate(): void
    {
        global $response_value;

        [$router, $controller] = $this->createNegotiatedTemplateRoute();
        $_SERVER['HTTP_ACCEPT'] = '*/*';

        $router->controller('/', $controller);

        $this->assertInstanceOf(Template::class, $response_value);
    }

    public function testJsonAcceptBypassesTemplate(): void
    {
        global $response_value;

        [$router, $controller] = $this->createNegotiatedTemplateRoute();
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $router->controller('/', $controller);

        $this->assertSame(['value' => 'negotiated'], $response_value);
    }

    public function testAcceptQualityChoosesPreferredRepresentation(): void
    {
        global $response_value, $response_code;

        [$router, $controller] = $this->createNegotiatedTemplateRoute();

        $_SERVER['HTTP_ACCEPT'] = 'text/html;q=0.5, application/json;q=0.9';
        $router->controller('/', $controller);
        $this->assertSame(['value' => 'negotiated'], $response_value);

        $response_value = null;
        $response_code = null;

        $_SERVER['HTTP_ACCEPT'] = 'text/html;q=0.9, application/json;q=0.5';
        $router->controller('/', $controller);
        $this->assertInstanceOf(Template::class, $response_value);
    }

    private function createNegotiatedTemplateRoute(): array
    {
        $root = sys_get_temp_dir() . '/objectiveweb-router-negotiation-' . bin2hex(random_bytes(8));
        $templates = $root . '/templates';

        mkdir($templates, 0777, true);
        $this->directories[] = $templates;
        $this->directories[] = $root;

        $file = $templates . '/index.php';
        file_put_contents($file, '<?= $value ?>');
        $this->files[] = $file;

        $controller = new class {
            public function index(array $query): array
            {
                return ['value' => 'negotiated'];
            }
        };

        $_GET = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PATH_INFO'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REDIRECT_URL'] = '/';

        return [new Router($root), $controller];
    }

    public function testControllerFallsBackToHttpMethodTemplate(): void
    {
        global $response_value, $response_code;

        $root = sys_get_temp_dir() . '/objectiveweb-router-' . bin2hex(random_bytes(8));
        $templates = $root . '/templates';

        mkdir($templates, 0777, true);
        $this->directories[] = $templates;
        $this->directories[] = $root;

        $file = $templates . '/get.php';
        file_put_contents($file, '<?= $value ?>');
        $this->files[] = $file;

        $controller = new class {
            public function getFallback(array $query): array
            {
                return ['value' => 'method-fallback'];
            }
        };

        $_GET = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PATH_INFO'] = '/fallback';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/fallback';
        $_SERVER['REDIRECT_URL'] = '/fallback';
        unset($_SERVER['HTTP_ACCEPT']);

        $router = new Router($root);
        $router->controller('/', $controller);

        $this->assertInstanceOf(Template::class, $response_value);
        $this->assertSame('method-fallback', $response_value->render());
        $this->assertSame(200, $response_code);
    }
}
