<?php

namespace Objectiveweb\Router;

/**
 * Template class for rendering PHP templates with optional layouts
 * 
 * This class provides a simple way to render templates and apply layouts
 * to the rendered content. It supports passing data to templates and
 * handling layout rendering.
 * 
 * @package Objectiveweb\Router
 */
class Template {

    /**
     * Template constructor
     *
     * @param string $_root Template root directory
     * @param string $template Path to the template file
     * @param array $data Data to be passed to the template
     * @param string|null $layout Layout file name (without extension)
     */
    function __construct(private $_root, private string $_template, private array $_data = [], private string|null $_layout = null) {

        $this->_template = $this->_root . DIRECTORY_SEPARATOR . $_template . '.php';

        if (!is_readable($this->_template)) {
            throw new \Exception("Cannot read $this->_template", 500);
        }
    }

    /**
     * Render the template and return the output
     * 
     * This method renders the template file, optionally applies a layout,
     * and returns the final rendered content.
     * 
     * @return string The rendered template content
     * @throws \Exception If the template file cannot be read
     */
    function render() {

        if (is_array($this->_data)) {
            extract($this->_data);
        }

        ob_start();

        include $this->_template;

        $_contents = ob_get_contents();

        ob_end_clean();

        if($this->_layout) {
            if(is_readable($this->_root . '/_layouts/' . $this->_layout . '.php')) {

                ob_start();
                include $this->_root . '/_layouts/' . $this->_layout . '.php';

                $_contents = ob_get_contents();
                ob_end_clean();
            }
            else {
                throw new \Exception("Cannot read $this->_layout", 500);
            }

        }

        return $_contents;
    }
}
