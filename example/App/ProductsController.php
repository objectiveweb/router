<?php

namespace App;

use App\DB\ProductsRepository;
use App\Model\Product;

class ProductsController
{
    public function __construct(
        private ProductsRepository $products,
        private string $name = 'Products Controller'
    ) {
    }

    /**
     * GET /
     */
    public function index(): array
    {
        return $this->products->index();
    }

    /**
     * GET /sku
     */
    public function get($sku): Product
    {
        return $this->products->get($sku);
    }

    /**
     * POST /
     *
     * JMS Serializer can deserialize a JSON body into Product when installed.
     */
    public function post(Product $product): Product
    {
        $this->products->post($product);

        return $product;
    }

    /**
     * PUT /sku
     */
    public function put($sku, array $data): Product
    {
        $product = $this->get($sku);

        foreach ($data as $key => $value) {
            $product->$key = $value;
        }

        return $product;
    }

    /**
     * GET /sale resolves to getSale() before sale().
     */
    public function getSale(): array
    {
        return $this->sale(90);
    }

    /**
     * Fallback custom method, e.g. VIEW /sale/50.
     */
    public function sale($price = 12345): array
    {
        $products = $this->products->index();
        $products[0]->price = $price;

        return $products;
    }

    public function optionsSale(): int
    {
        return $this->products->count();
    }

    public function hello(): string
    {
        return "Hello $this->name";
    }
}
