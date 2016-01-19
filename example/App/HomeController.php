<?php

namespace App;

class HomeController {
    
    function index() {
        return "App Index Page";
    }
    
    function get($path) {
        return "Default fallback for $path";
    }
    
    function say($str) {
        return $str;
    }
}