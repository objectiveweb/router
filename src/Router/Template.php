<?php

namespace Objectiveweb\Router;

/**
 * Template class for rendering PHP templates with optional layouts.
 */
class Template
{
    public function __construct(
        private string $_root,
        private string $_template,
        private array $_data = [],
        private ?string $_layout = null,
        private ?\Objectiveweb\Router $_router = null
    ) {
        $this->_template = $this->_root . DIRECTORY_SEPARATOR . $_template . '.php';

        if (!is_readable($this->_template)) {
            throw new \Exception("Cannot read $this->_template", 500);
        }
    }

    public function url(?string $path = null): string
    {
        if ($this->_router === null) {
            throw new \LogicException(
                'Template URL generation requires a Template created by Router::template()'
            );
        }

        return $this->_router->url($path);
    }

    /**
     * Render the template and optional layout.
     *
     * @throws \Throwable If template or layout execution fails.
     */
    public function render(): string
    {
        $_contents = $this->renderFile($this->_template, $this->_data);

        if ($this->_layout === null) {
            return $_contents;
        }

        $layout = $this->_root . '/_layouts/' . $this->_layout . '.php';
        if (!is_readable($layout)) {
            throw new \Exception("Cannot read $this->_layout", 500);
        }

        return $this->renderFile(
            $layout,
            [
                ...$this->_data,
                '_contents' => $_contents,
            ]
        );
    }

    /**
     * Render one PHP file with an isolated output buffer.
     *
     * Template data cannot overwrite local renderer variables.
     */
    private function renderFile(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);

        $bufferLevel = ob_get_level();
        ob_start();

        try {
            include $__file;

            // If template code opened additional buffers without closing them,
            // flush those into our rendering buffer before collecting it.
            while (ob_get_level() > $bufferLevel + 1) {
                ob_end_flush();
            }

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            // Restore exactly the buffer depth that existed before rendering.
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            throw $exception;
        }
    }
}
