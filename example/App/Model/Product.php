<?php

namespace App\Model;

use JMS\Serializer\Annotation\Type;

class Product {
    
    /** @Type("integer") */
    public $sku;
    
    /** @Type("string") */
    public $name;
    
    /** @Type("double") */
    public $price;
    
    function __construct($sku, $name, $price) {
        $this->sku = $sku;
        $this->name = $name;
        $this->price = $price;
    }
}