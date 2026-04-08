<?php
require_once 'Product.php';

class ProductController {
    private $model;

    public function __construct($db) {
        $this->model = new Product($db);
    }

    public function index() {
        $products = $this->model->getProducts();
        // Pass data to view
        require 'views/productListView.php';
    }
}
?>
