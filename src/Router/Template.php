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
        private ?string $_layout = null
    ) {
        $this->_template = $this->_root . DIRECTORY_SEPARATOR . $_template . '.php';

        if (!is_readable($this->_template)) {
            throw new \Exception("Cannot read $this->_template", 500);
        }
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
    private function renderFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            include $file;

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }
    }
}
