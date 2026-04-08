<?php
class Product {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Fetch all products
    public function getProducts() {
        $sql = "SELECT Product_Name, Category, Price, Image FROM product";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        return $products;
    }
}
?>
