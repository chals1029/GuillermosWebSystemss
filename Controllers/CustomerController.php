<?php
declare(strict_types=1);

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/EmailApiController.php';

class CustomerController
{
    private \mysqli $conn;
    private string $orderDetailPriceColumn = 'Price';
    private bool $orderDetailPriceColumnResolved = false;
    /** @var array<string, bool> */
    private array $columnExistsCache = [];
    private ?bool $inventoryAdjustAllowed = null;

    public function __construct()
    {
        global $conn;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($conn) || !$conn instanceof \mysqli) {
            throw new \RuntimeException('Database connection not available.');
        }

        $this->conn = $conn;
    }

    public function handleAjax(): void
    {
        $action = $_POST['action'] ?? $_GET['action'] ?? null;

        // Cart actions
        if (isset($_POST['action'], $_POST['product'])) {
            $this->handleCartAction($_POST['action'], $_POST['product']);
            return;
        }

        // Place order
        if ($action === 'place_order' && isset($_POST['order_data'])) {
            $orderData = json_decode($_POST['order_data'], true);
            if (!$orderData) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Invalid order data'], 400);
            }
            $result = $this->placeOrder($orderData);
            $this->jsonResponse($result, $result['status'] === 'success' ? 200 : 400);
            return;
        }

        // Get customer orders
        if ($action === 'get_orders') {
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
            if ($userId <= 0) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Not logged in'], 401);
            }
            $orders = $this->getCustomerOrders($userId);
            $this->jsonResponse(['status' => 'success', 'orders' => $orders]);
            return;
        }

        if ($action === 'get_announcements') {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $announcements = $this->getActiveAnnouncements($limit);
            $this->jsonResponse([
                'status' => 'success',
                'announcements' => $announcements,
            ]);
            return;
        }

        // Cancel order
        if ($action === 'cancel_order' && isset($_POST['order_id'])) {
            $orderId = (int)$_POST['order_id'];
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
            $result = $this->cancelOrder($orderId, $userId);
            $this->jsonResponse($result, $result['status'] === 'success' ? 200 : 400);
            return;
        }

        // Get order details
        if ($action === 'get_order_details' && isset($_GET['order_id'])) {
            $orderId = (int)$_GET['order_id'];
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
            $details = $this->getOrderDetails($orderId, $userId);
            if ($details) {
                $this->jsonResponse(['status' => 'success', 'order' => $details]);
            } else {
                $this->jsonResponse(['status' => 'error', 'message' => 'Order not found'], 404);
            }
            return;
        }

        // Generate receipt
        if ($action === 'generate_receipt' && isset($_GET['order_id'])) {
            $orderId = (int)$_GET['order_id'];
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
            $this->generateReceipt($orderId, $userId);
            return;
        }

        // Submit feedback
        if ($action === 'submit_feedback' && isset($_POST['order_id'], $_POST['rating'])) {
            try {
                $orderId = (int)$_POST['order_id'];
                $rating = (int)$_POST['rating'];
                $comment = trim($_POST['comment'] ?? '');
                $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
                
                if ($userId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Not logged in'], 401);
                    return;
                }
                
                $result = $this->submitFeedback($orderId, $userId, $rating, $comment);
                $this->jsonResponse($result, $result['status'] === 'success' ? 200 : 400);
            } catch (\Exception $e) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()], 500);
            }
            return;
        }

        // Get order feedback status
        if ($action === 'check_feedback' && isset($_GET['order_id'])) {
            $orderId = (int)$_GET['order_id'];
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
            $hasFeedback = $this->hasOrderFeedback($orderId, $userId);
            $this->jsonResponse(['status' => 'success', 'has_feedback' => $hasFeedback]);
            return;
        }

        // Get feedback details for an order (read-only)
        if ($action === 'get_feedback' && isset($_GET['order_id'])) {
            $orderId = (int)$_GET['order_id'];
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
            if ($userId <= 0) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Not logged in'], 401);
                return;
            }

            $feedback = $this->getFeedbackByOrder($orderId, $userId);
            if ($feedback === null) {
                $this->jsonResponse(['status' => 'success', 'has_feedback' => false, 'feedback' => null]);
                return;
            }

            $this->jsonResponse(['status' => 'success', 'has_feedback' => true, 'feedback' => $feedback]);
            return;
        }

        // AJAX: get product by name (used by client to resolve Product_ID when modal/top-list items don't include it)
        if ($action === 'get_product_by_name' && isset($_POST['name'])) {
            $name = trim($_POST['name']);
            $stmt = $this->conn->prepare('SELECT Product_ID, Product_Name, Price, Stock_Quantity FROM product WHERE Product_Name = ? LIMIT 1');
            if ($stmt === false) {
                $this->jsonResponse(['status' => 'error', 'message' => 'DB error preparing statement'], 500);
            }
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($product) {
                $this->jsonResponse(['status' => 'success', 'product' => $product]);
            } else {
                $this->jsonResponse(['status' => 'error', 'message' => 'Product not found'], 404);
            }
            return;
        }

        // Chat endpoint: use server-side call to Gemini/Generative Language
        if ($action === 'chat' && isset($_POST['prompt'])) {
            $userId = (int)($_SESSION['user_id'] ?? $_SESSION['user']['user_id'] ?? 0);
            if ($userId <= 0) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Not logged in'], 401);
            }
            $prompt = trim($_POST['prompt'] ?? '');
            if ($prompt === '') {
                $this->jsonResponse(['status' => 'error', 'message' => 'Prompt required'], 422);
            }
            try {
                $result = $this->callGeminiModel($prompt);
                // Pick the candidate content if available
                $text = '';
                if (is_array($result)) {
                    if (isset($result['candidates'][0]['content'])) $text = $result['candidates'][0]['content'];
                    else $text = json_encode($result);
                } else {
                    $text = (string)$result;
                }
                $this->jsonResponse(['status' => 'success', 'message' => $text]);
            } catch (\Throwable $t) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Server error: ' . $t->getMessage()], 500);
            }
            return;
        }

        // Quick check the GEMINI API key (does not reveal the actual key)
        if ($action === 'check_gemini_key') {
            if (defined('GEMINI_API_KEY')) {
                $apiKey = constant('GEMINI_API_KEY');
            } else {
                $apiKey = getenv('GEMINI_API_KEY');
            }
            $configured = !empty($apiKey);
            $masked = null;
            if ($configured) {
                // Show a very short mask so the admin can confirm whether the correct key is loaded.
                $len = strlen($apiKey);
                $masked = $len > 8 ? substr($apiKey, 0, 4) . str_repeat('*', $len - 8) . substr($apiKey, -4) : str_repeat('*', max(4, $len));
            }
            $this->jsonResponse(['status' => 'success', 'configured' => $configured, 'masked' => $masked]);
            return;
        }

        // Legacy checkout handler
        if (isset($_POST['checkout'], $_POST['order_data'])) {
            $this->handleCheckout($_POST['order_data']);
            return;
        }

        http_response_code(400);
        $this->jsonResponse([
            'status' => 'error',
            'message' => 'Unsupported request.',
        ]);
    }

    public function getProductsByCategory(string $category): array
    {
        $category = trim($category);

        if ($category === 'all') {
            $sql = 'SELECT Product_ID, Product_Name, Description, Price, Category, Image, Stock_Quantity FROM product ORDER BY Product_Name ASC';
            $result = $this->conn->query($sql);
            if ($result === false) {
                return [];
            }

            $products = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $sql = 'SELECT Product_ID, Product_Name, Description, Price, Category, Image, Stock_Quantity FROM product WHERE Category = ? ORDER BY Product_Name ASC';
            $stmt = $this->conn->prepare($sql);
            if ($stmt === false) {
                return [];
            }

            $stmt->bind_param('s', $category);
            $stmt->execute();
            $result = $stmt->get_result();
            $products = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
        }

        return array_map([$this, 'normalizeProduct'], $products);
    }

    private function normalizeProduct(array $row): array
    {
        if ($row['Image']) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($row['Image']) ?: 'image/jpeg';
            $row['Image'] = 'data:' . $mime . ';base64,' . base64_encode($row['Image']);
        } else {
            $row['Image'] = null;
        }
        return $row;
    }

    public function getCart(): array
    {
        return $_SESSION['cart'] ?? [];
    }

    public function countCartItems(array $cart): int
    {
        $total = 0;
        foreach ($cart as $item) {
            $total += (int)($item['quantity'] ?? 0);
        }

        return $total;
    }

    private function handleCartAction(string $action, string $productName): void
    {
        $action = strtolower(trim($action));
        $productName = trim($productName);

        if ($productName === '') {
            http_response_code(400);
            header('Content-Type: text/plain');
            echo '0';
            return;
        }

        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        if (!isset($_SESSION['cart'][$productName]) && $action === 'increase') {
            $product = $this->getProductByName($productName);
            if ($product === null) {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo (string)$this->countCartItems($_SESSION['cart']);
                return;
            }

            $_SESSION['cart'][$productName] = [
                'price' => (float)$product['Price'],
                'quantity' => 0,
                'image' => $product['Image'] ?? null,
            ];
        }

        if (!isset($_SESSION['cart'][$productName])) {
            header('Content-Type: text/plain');
            echo (string)$this->countCartItems($_SESSION['cart']);
            return;
        }

        switch ($action) {
            case 'increase':
                $_SESSION['cart'][$productName]['quantity']++;
                break;
            case 'decrease':
                $_SESSION['cart'][$productName]['quantity']--;
                if ($_SESSION['cart'][$productName]['quantity'] <= 0) {
                    unset($_SESSION['cart'][$productName]);
                }
                break;
            case 'remove':
                unset($_SESSION['cart'][$productName]);
                break;
        }

        header('Content-Type: text/plain');
        echo (string)$this->countCartItems($_SESSION['cart']);
        return;
    }

    private function handleCheckout(string $orderJson): void
    {
        $order = json_decode($orderJson, true);
        if (!is_array($order)) {
            http_response_code(400);
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'Invalid order payload.',
            ]);
            return;
        }

        if (empty($order['customer_name']) || empty($order['items']) || !is_array($order['items'])) {
            http_response_code(400);
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'Invalid order data.',
            ]);
            return;
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO orders 
            (customer_name, order_type, is_reservation, delivery_address, payment_method, subtotal, delivery_fee, order_json, created_at)
            VALUES (?,?,?,?,?,?,?, ?, NOW())'
        );

        if ($stmt === false) {
            http_response_code(500);
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'Database error preparing statement.',
            ]);
            return;
        }

        $isReservation = !empty($order['is_reservation']) ? 1 : 0;
        $address = $order['delivery_address'] ?? '';
        $itemsJson = json_encode($order['items']);
        $paymentMethod = $order['payment_method'] ?? 'cash';
        $subtotal = isset($order['subtotal']) ? (float)$order['subtotal'] : 0.0;
        $deliveryFee = isset($order['delivery_fee']) ? (float)$order['delivery_fee'] : 0.0;

        $stmt->bind_param(
            'ssissdds',
            $order['customer_name'],
            $order['order_type'],
            $isReservation,
            $address,
            $paymentMethod,
            $subtotal,
            $deliveryFee,
            $itemsJson
        );

        if ($stmt->execute()) {
            $_SESSION['cart'] = [];
            $stmt->close();
            $this->jsonResponse([
                'status' => 'ok',
                'address' => $address,
            ]);
            return;
        }

        $error = $stmt->error;
        $stmt->close();
        http_response_code(500);
        $this->jsonResponse([
            'status' => 'error',
            'message' => 'Database error: ' . $error,
        ]);
    }

    private function getProductByName(string $productName): ?array
    {
        $stmt = $this->conn->prepare('SELECT Product_Name, Price, Image FROM product WHERE Product_Name = ? LIMIT 1');
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('s', $productName);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($product && $product['Image']) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($product['Image']) ?: 'image/jpeg';
            $product['Image'] = 'data:' . $mime . ';base64,' . base64_encode($product['Image']);
        }

        return $product ?: null;
    }

    private function jsonResponse(array $payload, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($payload);
        exit;
    }

    /**
     * Place a new order with cart items
     * Creates records in orders, order_detail, payment, and invoice tables
     */
    public function placeOrder(array $orderData): array
    {
        // Resolve user id from multiple possible session keys
        $userId = 0;
        if (isset($_SESSION['user_id'])) $userId = (int)$_SESSION['user_id'];
        elseif (isset($_SESSION['User_ID'])) $userId = (int)$_SESSION['User_ID'];
        elseif (isset($_SESSION['user']['user_id'])) $userId = (int)$_SESSION['user']['user_id'];
        elseif (isset($_SESSION['user']['User_ID'])) $userId = (int)$_SESSION['user']['User_ID'];

        if ($userId <= 0) {
            return ['status' => 'error', 'message' => 'You must be logged in to place an order'];
        }

        if (empty($orderData['items']) || !is_array($orderData['items'])) {
            return ['status' => 'error', 'message' => 'Cart is empty'];
        }

        $paymentMethod = $orderData['payment_method'] ?? 'Cash';
        // Disallow GCash temporarily if client tries to use it
        if (is_string($paymentMethod) && strcasecmp($paymentMethod, 'gcash') === 0) {
            return ['status' => 'error', 'message' => 'GCash is currently unavailable. Please use Cash.'];
        }
        // Normalize Cash on Delivery to enum value "Cash"
        if (is_string($paymentMethod) && strcasecmp($paymentMethod, 'cash on delivery') === 0) {
            $paymentMethod = 'Cash';
        }
        $totalAmount = (float)($orderData['total_amount'] ?? 0);
        $amountTendered = (float)($orderData['amount_tendered'] ?? $totalAmount);
        $customerChange = max(0, $amountTendered - $totalAmount);

        // Validate required customer fields
        $customerName = trim($orderData['customer_name'] ?? '');
        $deliveryAddress = trim($orderData['delivery_address'] ?? '');
        if ($customerName === '' || $deliveryAddress === '') {
            return ['status' => 'error', 'message' => 'Customer name and delivery address are required.'];
        }

        // Start transaction
        $this->conn->begin_transaction();

        try {
            // 1. Validate stock availability for all items
            foreach ($orderData['items'] as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 0);

                $stmt = $this->conn->prepare('SELECT Stock_Quantity, Product_Name FROM product WHERE Product_ID = ?');
                $stmt->bind_param('i', $productId);
                $stmt->execute();
                $result = $stmt->get_result();
                $product = $result->fetch_assoc();
                $stmt->close();

                if (!$product) {
                    throw new \Exception("Product ID {$productId} not found");
                }

                if ($product['Stock_Quantity'] < $quantity) {
                    throw new \Exception("Insufficient stock for {$product['Product_Name']}");
                }
            }

            // 2. Insert into orders table
            $stmt = $this->conn->prepare(
                'INSERT INTO orders (User_ID, Order_Date, Mode_Payment, Total_Amount, Status) VALUES (?, NOW(), ?, ?, "Pending")'
            );
            if ($stmt === false) {
                throw new \Exception('Database error preparing order insert');
            }

            $stmt->bind_param('isd', $userId, $paymentMethod, $totalAmount);
            if (!$stmt->execute()) {
                throw new \Exception('Failed to create order: ' . $stmt->error);
            }
            $orderId = $stmt->insert_id;
            $stmt->close();

            // 3. Insert order details and update stock
            foreach ($orderData['items'] as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = (int)($item['quantity'] ?? 0);
                $price = (float)($item['price'] ?? 0);
                $subtotal = $price * $quantity;

                // Insert order detail
                $stmt = $this->conn->prepare(
                    'INSERT INTO order_detail (Order_ID, Product_ID, Quantity, Price, Subtotal) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('iiidd', $orderId, $productId, $quantity, $price, $subtotal);
                if (!$stmt->execute()) {
                    throw new \Exception('Failed to insert order detail: ' . $stmt->error);
                }
                $stmt->close();

                // Deduct stock if product has a Stock_Quantity column
                if ($this->canAdjustInventory()) {
                    $stmt = $this->conn->prepare('UPDATE product SET Stock_Quantity = Stock_Quantity - ? WHERE Product_ID = ?');
                    if (!$stmt) {
                        throw new \Exception('Failed to prepare stock update');
                    }

                    $stmt->bind_param('ii', $quantity, $productId);
                    if (!$stmt->execute()) {
                        $error = $stmt->error;
                        $stmt->close();
                        throw new \Exception('Failed to update stock: ' . $error);
                    }
                    $stmt->close();
                    // Log inventory change if applicable (use user id context)
                    try { $this->recordInventoryLog($productId, -$quantity, $userId, 'Remove'); } catch (\Throwable $e) { /* don't fail order on logging error */ }
                }
            }

            // 4. Insert payment record
            $stmt = $this->conn->prepare(
                'INSERT INTO payment (User_ID, Payment_Method, Payment_Amount, Customer_Change) VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('isdd', $userId, $paymentMethod, $amountTendered, $customerChange);
            if (!$stmt->execute()) {
                throw new \Exception('Failed to create payment record: ' . $stmt->error);
            }
            $stmt->close();

            // 5. Insert invoice record. Some schemas define Invoice_ID as NOT NULL without AUTO_INCREMENT,
            // so generate the next id explicitly inside the transaction.
            $invoiceIdResult = $this->conn->query('SELECT COALESCE(MAX(Invoice_ID), 0) + 1 AS NextInvoiceID FROM invoice FOR UPDATE');
            if ($invoiceIdResult === false) {
                throw new \Exception('Failed to generate invoice ID: ' . $this->conn->error);
            }
            $invoiceIdRow = $invoiceIdResult->fetch_assoc();
            $invoiceId = (int)($invoiceIdRow['NextInvoiceID'] ?? 1);

            $stmt = $this->conn->prepare(
                'INSERT INTO invoice (Invoice_ID, User_ID, Invoice_Date, Total, Customer_Change, Invoice_Status, Mode_Payment) VALUES (?, ?, NOW(), ?, ?, "Pending", ?)'
            );
            if ($stmt === false) {
                throw new \Exception('Database error preparing invoice insert');
            }

            $stmt->bind_param('iidds', $invoiceId, $userId, $totalAmount, $customerChange, $paymentMethod);
            if (!$stmt->execute()) {
                throw new \Exception('Failed to create invoice: ' . $stmt->error);
            }
            $stmt->close();

            // Commit transaction
            $this->conn->commit();

            // Clear cart
            $_SESSION['cart'] = [];

            // Send receipt email
            $stmt = $this->conn->prepare('SELECT name, email FROM users WHERE user_id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $customer = $result->fetch_assoc();
            $stmt->close();

            if ($customer && !empty($customer['email'])) {
                // Use the canonical, server-side order items so emails display authoritative product names/prices.
                $itemsForEmail = $this->getOrderItems((int)$orderId);

                // Maintain a fallback to client-supplied items if the DB lookup returned nothing (unlikely but defensive)
                if (empty($itemsForEmail) && !empty($orderData['items'])) {
                    $itemsForEmail = $orderData['items'];
                }

                $emailDetails = [
                    'order_id' => $orderId,
                    // Fetch the actual Order_Date from DB and convert to Manila timezone for response
                    'order_date' => (function($orderId, $conn) {
                        try {
                            $stmt = $conn->prepare('SELECT Order_Date FROM orders WHERE OrderID = ? LIMIT 1');
                            $stmt->bind_param('i', $orderId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $row = $result ? $result->fetch_assoc() : null;
                            $stmt->close();
                            $raw = $row['Order_Date'] ?? null;
                            if (!$raw) return date('Y-m-d H:i:s');
                            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                            return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format(DATE_ATOM);
                        } catch (\Throwable $e) {
                            return date('Y-m-d H:i:s');
                        }
                    })($orderId, $this->conn),
                    'payment_method' => $paymentMethod,
                    'status' => 'Pending',
                    'total_amount' => $totalAmount,
                    'change' => $customerChange,
                    'items' => $itemsForEmail
                ];

                // Log suspicious items (fallback check) if any item has no name or uses an unknown placeholder
                foreach ($emailDetails['items'] as $idx => $it) {
                    $nameKey = $it['Product_Name'] ?? $it['name'] ?? '';
                    if (trim($nameKey) === '' || stripos((string)$nameKey, 'unknown') !== false) {
                        error_log("Warning: email for order {$orderId} contains item with missing/unknown name at index {$idx}. Item data: " . json_encode($it));
                    }
                }

                // Send email (don't fail the order if email fails)
                $emailResult = EmailApiController::sendReceiptEmail($customer['email'], $customer['name'], $emailDetails);
                if ($emailResult !== true) {
                    // Log email failure but don't affect order success
                    error_log('Failed to send receipt email: ' . $emailResult);
                }
            }

            return [
                'status' => 'success',
                'message' => 'Order placed successfully',
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
                'total' => $totalAmount,
                'change' => $customerChange
            ];

        } catch (\Exception $e) {
            $this->conn->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Call Gemini / Generative Language model using server-side API key from environment.
     */
    public function callGeminiModel(string $prompt, string $model = 'text-bison-001'): array
    {
        // Prefer configured key names only; never use literal API key text as a constant/env variable name.
        if (defined('GEMINI_API_KEY')) {
            $apiKey = constant('GEMINI_API_KEY');
        } else {
            $apiKey = getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY');
        }

        if (empty($apiKey)) {
            return ['error' => 'API key not configured'];
        }

        $endpoint = "https://generativelanguage.googleapis.com/v1beta2/models/{$model}:generateText";
        $url = $endpoint . '?key=' . urlencode($apiKey);

        $payload = [
            'prompt' => ['text' => $prompt],
            'temperature' => 0.2,
            'candidateCount' => 1,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $status < 200 || $status >= 300) {
            $details = $res !== false ? json_decode($res, true) : null;
            return ['error' => 'API request failed', 'http_status' => $status, 'details' => $details ?: $err];
        }

        $data = json_decode($res, true);
        return $data ?? ['error' => 'Failed to decode response'];
    }

    /**
     * Get all orders for a customer
     */
    public function getCustomerOrders(int $userId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT OrderID, Order_Date, Mode_Payment, Total_Amount, Status 
             FROM orders 
             WHERE User_ID = ? 
             ORDER BY Order_Date DESC'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get items for each order
        foreach ($orders as &$order) {
            // Normalize types and convert Order_Date to Manila (PHT) timezone in ISO 8601 format
            $raw = $order['Order_Date'] ?? null;
            $order['Order_Date'] = (function($raw) {
                if (!$raw) return '';
                try {
                    $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                    return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('c');
                } catch (\Throwable $e) {
                    return (string)$raw;
                }
            })($raw);

            $order['items'] = $this->getOrderItems((int)$order['OrderID']);
            $order['Total_Amount'] = isset($order['Total_Amount']) ? (float)$order['Total_Amount'] : 0.0;
        }

        return $orders;
    }

    /**
     * Get items for a specific order
     */
    private function resolveOrderDetailPriceColumn(): string
    {
        if ($this->orderDetailPriceColumnResolved) {
            return $this->orderDetailPriceColumn;
        }

        $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS ";
        $sql .= "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_detail' ";
        $sql .= "AND COLUMN_NAME IN ('unitprice','Price') ";
        $sql .= "ORDER BY FIELD(COLUMN_NAME, 'unitprice', 'Price') LIMIT 1";

        $result = $this->conn->query($sql);
        if ($result && ($row = $result->fetch_assoc()) && !empty($row['COLUMN_NAME'])) {
            $this->orderDetailPriceColumn = $row['COLUMN_NAME'];
        }

        $this->orderDetailPriceColumnResolved = true;
        return $this->orderDetailPriceColumn;
    }

    private function getOrderItems(int $orderId): array
    {
        $priceColumn = $this->resolveOrderDetailPriceColumn();
        $sql = 'SELECT od.Product_ID, p.Product_Name, od.Quantity, od.' . $priceColumn . ' AS UnitPrice, od.Subtotal, p.Image '
            . 'FROM order_detail od '
            . 'JOIN product p ON od.Product_ID = p.Product_ID '
            . 'WHERE od.Order_ID = ?';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $finfo = class_exists('finfo') ? new \finfo(FILEINFO_MIME_TYPE) : null;

        foreach ($items as &$item) {
            $item['Quantity'] = (int)($item['Quantity'] ?? 0);
            $item['Price'] = isset($item['UnitPrice']) ? (float)$item['UnitPrice'] : (isset($item['Price']) ? (float)$item['Price'] : 0.0);
            unset($item['UnitPrice']);

            $item['Subtotal'] = isset($item['Subtotal']) ? (float)$item['Subtotal'] : $item['Price'] * $item['Quantity'];

            if (!empty($item['Image'])) {
                $mime = 'image/jpeg';
                if ($finfo) {
                    $detected = $finfo->buffer($item['Image']);
                    if (is_string($detected) && $detected !== '') {
                        $mime = $detected;
                    }
                }

                $item['Image'] = 'data:' . $mime . ';base64,' . base64_encode($item['Image']);
            } else {
                $item['Image'] = null;
            }
        }
        unset($item);

        return $items;
    }

    public function getRecentOrders(int $userId, int $limit = 3): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, $limit);

                $stmt = $this->conn->prepare(
                        'SELECT OrderID, Order_Date, Total_Amount, Status 
                         FROM orders 
                         WHERE User_ID = ? 
                             AND Status IS NOT NULL 
                             AND UPPER(TRIM(Status)) = "COMPLETED" 
                         ORDER BY Order_Date DESC 
                         LIMIT ?'
                );

        if ($stmt === false) {
            return [];
        }

        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        foreach ($orders as &$order) {
            // Standardize and convert order timestamp to Manila time (ISO 8601)
            $raw = $order['Order_Date'] ?? null;
            $order['Order_Date'] = (function($raw) {
                if (!$raw) return '';
                try {
                    $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                    return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('c');
                } catch (\Throwable $e) {
                    return (string)$raw;
                }
            })($raw);

            $order['items'] = $this->getOrderItems((int)$order['OrderID']);
            $order['Total_Amount'] = isset($order['Total_Amount']) ? (float)$order['Total_Amount'] : 0.0;
        }
        unset($order);

        return $orders;
    }

    /**
     * Cancel an order (only if status is Pending)
     */
    public function cancelOrder(int $orderId, int $userId): array
    {
        // Check if order exists and belongs to user, and get order date
        $stmt = $this->conn->prepare('SELECT Status, Order_Date FROM orders WHERE OrderID = ? AND User_ID = ?');
        $stmt->bind_param('ii', $orderId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();

        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }

        if ($order['Status'] !== 'Pending') {
            return ['status' => 'error', 'message' => 'Cannot cancel order. Only pending orders can be cancelled.'];
        }
        
        // Check if order is within 5-minute cancellation window
        try {
            $dt = new \DateTimeImmutable($order['Order_Date'], new \DateTimeZone('UTC'));
            $orderTime = $dt->getTimestamp();
        } catch (\Throwable $e) {
            $orderTime = strtotime($order['Order_Date']);
        }
        $currentTime = time();
        $timeDiffMinutes = ($currentTime - $orderTime) / 60;
        
        if ($timeDiffMinutes > 5) {
            $minutesAgo = floor($timeDiffMinutes);
            return ['status' => 'error', 'message' => "Orders can only be cancelled within 5 minutes of placement. This order was placed {$minutesAgo} minutes ago."];
        }

        // Start transaction to restore stock and update status
        $this->conn->begin_transaction();

        try {
            // Restore stock for all items
            $items = $this->getOrderItems($orderId);
            foreach ($items as $item) {
                // Restore stock for all items if product has Stock_Quantity column
                if (!$this->canAdjustInventory()) {
                    continue;
                }

                $stmt = $this->conn->prepare('UPDATE product SET Stock_Quantity = Stock_Quantity + ? WHERE Product_ID = ?');
                if (!$stmt) {
                    throw new \Exception('Failed to prepare stock restoration');
                }

                $stmt->bind_param('ii', $item['Quantity'], $item['Product_ID']);
                if (!$stmt->execute()) {
                    $error = $stmt->error;
                    $stmt->close();
                    throw new \Exception('Failed to restore stock: ' . $error);
                }
                $stmt->close();
                try { $this->recordInventoryLog((int)$item['Product_ID'], (int)$item['Quantity'], $userId, 'Add'); } catch (\Throwable $e) { /* ignore logging errors */ }
            }

            // Update order status to Cancelled
            $stmt = $this->conn->prepare('UPDATE orders SET Status = "Cancelled" WHERE OrderID = ?');
            $stmt->bind_param('i', $orderId);
            if (!$stmt->execute()) {
                throw new \Exception('Failed to cancel order: ' . $stmt->error);
            }
            $stmt->close();

            // Update invoice status
            $stmt = $this->conn->prepare('UPDATE invoice SET Invoice_Status = "Cancelled" WHERE User_ID = ? ORDER BY Invoice_ID DESC LIMIT 1');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();

            $this->conn->commit();
            return ['status' => 'success', 'message' => 'Order cancelled successfully'];

        } catch (\Exception $e) {
            $this->conn->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Get detailed information for a specific order
     */
    public function getOrderDetails(int $orderId, int $userId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT o.OrderID, o.Order_Date, o.Mode_Payment, o.Total_Amount, o.Status, o.Delivery_Address,
                    i.Invoice_ID, i.Total, i.Customer_Change, i.Invoice_Status
             FROM orders o
             LEFT JOIN invoice i ON o.User_ID = i.User_ID AND DATE(o.Order_Date) = DATE(i.Invoice_Date)
             WHERE o.OrderID = ? AND o.User_ID = ?'
        );
        $stmt->bind_param('ii', $orderId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();

        if (!$order) {
            return null;
        }
        // Convert Order_Date to Manila/PHT ISO 8601 for JSON usage and JS parsing
        $raw = $order['Order_Date'] ?? null;
        $order['Order_Date'] = (function($raw) {
            if (!$raw) return '';
            try {
                $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('c');
            } catch (\Throwable $e) {
                return (string)$raw;
            }
        })($raw);

        $order['items'] = $this->getOrderItems($orderId);
        return $order;
    }

    /**
     * Generate a printable receipt for an order
     */
    public function generateReceipt(int $orderId, int $userId): void
    {
        $order = $this->getOrderDetails($orderId, $userId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found';
            exit;
        }

        // Get customer details
        $stmt = $this->conn->prepare('SELECT name, email, phonenumber FROM users WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $customer = $result->fetch_assoc();
        $stmt->close();

        // Generate HTML receipt
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - Order #' . $orderId . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: \'Inter\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            color: #2c3e50;
        }
        .receipt-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            position: relative;
        }
        .receipt-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        .receipt-header::before {
            content: \'🧾\';
            font-size: 3rem;
            display: block;
            margin-bottom: 10px;
        }
        .receipt-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .receipt-header p {
            margin: 5px 0 0;
            opacity: 0.9;
            font-size: 1.1rem;
        }
        .order-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }
        .receipt-content {
            padding: 30px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid #667eea;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .info-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .info-card h3 {
            margin: 0 0 12px;
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .info-card p {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 500;
            color: #2c3e50;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .items-section {
            margin-bottom: 30px;
        }
        .section-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            cursor: pointer;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            transition: background 0.2s;
        }
        .section-header:hover {
            background: #e9ecef;
        }
        .section-header h2 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: #2c3e50;
        }
        .section-icon {
            margin-right: 12px;
            font-size: 1.5rem;
        }
        .toggle-icon {
            margin-left: auto;
            transition: transform 0.3s;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .items-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .items-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            background: white;
        }
        .items-table tbody tr:hover {
            background: #f8f9fa;
        }
        .item-name {
            font-weight: 600;
            color: #2c3e50;
        }
        .item-qty {
            background: #e9ecef;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #495057;
        }
        .price {
            font-weight: 600;
            color: #28a745;
        }
        .subtotal {
            font-weight: 700;
            color: #2c3e50;
        }
        .totals-section {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        .totals-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
        }
        .total-row.final {
            border-top: 2px solid rgba(255,255,255,0.3);
            padding-top: 15px;
            margin-top: 10px;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .change-row {
            background: rgba(255,255,255,0.1);
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .footer {
            text-align: center;
            padding: 30px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
        }
        .footer h3 {
            margin: 0 0 10px;
            color: #2c3e50;
            font-size: 1.3rem;
        }
        .footer p {
            margin: 5px 0;
            color: #6c757d;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102,126,234,0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        .progress-tracker {
            margin-bottom: 30px;
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .progress-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        .progress-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #2c3e50;
        }
        .progress-icon {
            margin-right: 10px;
            font-size: 1.3rem;
        }
        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        .progress-steps::before {
            content: \'\';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e9ecef;
            z-index: 1;
        }
        .step {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: #6c757d;
            position: relative;
            z-index: 2;
            transition: all 0.3s;
        }
        .step.active {
            background: #28a745;
            border-color: #28a745;
            color: white;
        }
        .step.completed {
            background: #28a745;
            border-color: #28a745;
            color: white;
        }
        .step-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        .step-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-align: center;
            flex: 1;
        }
        .step-label.active {
            color: #28a745;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            body { padding: 10px; }
            .receipt-container { margin: 0; }
            .receipt-header { padding: 20px; }
            .receipt-content { padding: 20px; }
            .info-grid { grid-template-columns: 1fr; }
            .totals-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .progress-steps { flex-wrap: wrap; gap: 10px; }
        }
        @media print {
            body { background: white !important; }
            .receipt-container { box-shadow: none; }
            .action-buttons,
            .toggle-icon,
            .section-header { display: none !important; }
            .items-section { display: block !important; }
            .progress-tracker { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <div class="order-badge">#' . $orderId . '</div>
            <h1>Order Receipt</h1>
            <p>Guillermo\'s Restaurant</p>
        </div>

        <div class="receipt-content">
            <!-- Order Progress Tracker -->
            <div class="progress-tracker">
                <div class="progress-header">
                    <span class="progress-icon">📍</span>
                    <h3>Order Status</h3>
                </div>
                <div class="progress-steps">
                    <div class="step ' . ($order['Status'] === 'Pending' || $order['Status'] === 'Completed' || $order['Status'] === 'Cancelled' ? 'completed' : '') . '">1</div>
                    <div class="step ' . ($order['Status'] === 'Completed' ? 'completed' : ($order['Status'] === 'Pending' ? 'active' : '')) . '">2</div>
                    <div class="step ' . ($order['Status'] === 'Completed' ? 'completed' : '') . '">3</div>
                </div>
                <div class="step-labels">
                    <div class="step-label ' . ($order['Status'] === 'Pending' || $order['Status'] === 'Completed' || $order['Status'] === 'Cancelled' ? 'active' : '') . '">Order Placed</div>
                    <div class="step-label ' . ($order['Status'] === 'Completed' ? 'active' : ($order['Status'] === 'Pending' ? 'active' : '')) . '">Preparing</div>
                    <div class="step-label ' . ($order['Status'] === 'Completed' ? 'active' : '') . '">Delivered</div>
                </div>
            </div>

            <!-- Order Information -->
            <div class="info-grid">
                <div class="info-card">
                    <h3>👤 Customer</h3>
                    <p>' . htmlspecialchars($customer['name'] ?? 'Guest') . '</p>
                </div>
                <div class="info-card">
                    <h3>📅 Order Date</h3>
                    <p>' . (function($raw) {
                        if (!$raw) return '';
                        try {
                            $hasTz = preg_match('/[Zz]|[+\-]\d{2}(:?\d{2})?$/', trim($raw));
                            if ($hasTz) {
                                $dt = new \DateTimeImmutable($raw);
                            } else {
                                $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                            }
                            return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M d, Y g:i A');
                        } catch (\Throwable $e) {
                            return date('M d, Y g:i A', strtotime($raw));
                        }
                    })($order['Order_Date']) . '</p>
                </div>
                <div class="info-card">
                    <h3>📍 Delivery Address</h3>
                    <p>' . htmlspecialchars($order['Delivery_Address'] ?? 'N/A') . '</p>
                </div>
                <div class="info-card">
                    <h3>💳 Payment Method</h3>
                    <p>' . htmlspecialchars($order['Mode_Payment']) . '</p>
                </div>
                <div class="info-card">
                    <h3>📊 Status</h3>
                    <p><span class="status-badge status-' . strtolower($order['Status']) . '">' . htmlspecialchars($order['Status']) . '</span></p>
                </div>
                <div class="info-card">
                    <h3>📧 Contact</h3>
                    <p>' . htmlspecialchars($customer['email'] ?? 'N/A') . '</p>
                </div>
            </div>

            <!-- Order Items -->
            <div class="items-section">
                <div class="section-header" onclick="toggleItems()">
                    <span class="section-icon">🛒</span>
                    <h2>Order Items</h2>
                    <span class="toggle-icon">▼</span>
                </div>
                <div id="items-content">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>';

        foreach ($order['items'] as $item) {
            echo '<tr>
                    <td><span class="item-name">' . htmlspecialchars($item['Product_Name']) . '</span></td>
                    <td><span class="item-qty">' . $item['Quantity'] . '</span></td>
                    <td><span class="price">₱' . number_format($item['Price'], 2) . '</span></td>
                    <td><span class="subtotal">₱' . number_format($item['Subtotal'], 2) . '</span></td>
                </tr>';
        }

        echo '</tbody>
                    </table>
                </div>
            </div>

            <!-- Order Totals -->
            <div class="totals-section">
                <div class="totals-grid">
                    <div></div>
                    <div>
                        <div class="total-row">
                            <span>Total Amount:</span>
                            <strong>₱' . number_format($order['Total_Amount'], 2) . '</strong>
                        </div>';

        if ($order['Customer_Change'] > 0) {
            echo '<div class="change-row">
                    <div class="total-row">
                        <span>Change:</span>
                        <strong>₱' . number_format($order['Customer_Change'], 2) . '</strong>
                    </div>
                </div>';
        }

        echo '</div>
                </div>
            </div>

            <!-- Footer -->
            <div class="footer">
                <h3>Thank You! 🎉</h3>
                <p>We hope you enjoy your meal from Guillermo\'s Restaurant</p>
                <p>For any questions or concerns, please contact our support team</p>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="window.print()">🖨️ Print Receipt</button>
                    <button class="btn btn-secondary" onclick="window.close()">❌ Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleItems() {
            const content = document.getElementById(\'items-content\');
            const toggle = document.querySelector(\'.toggle-icon\');
            const header = document.querySelector(\'.section-header\');

            if (content.style.display === \'none\') {
                content.style.display = \'block\';
                toggle.style.transform = \'rotate(0deg)\';
                header.style.background = \'#e9ecef\';
            } else {
                content.style.display = \'none\';
                toggle.style.transform = \'rotate(180deg)\';
                header.style.background = \'#f8f9fa\';
            }
        }

        // Add some interactive effects
        document.addEventListener(\'DOMContentLoaded\', function() {
            // Animate info cards on load
            const cards = document.querySelectorAll(\'.info-card\');
            cards.forEach((card, index) => {
                card.style.opacity = \'0\';
                card.style.transform = \'translateY(20px)\';
                setTimeout(() => {
                    card.style.transition = \'all 0.5s ease\';
                    card.style.opacity = \'1\';
                    card.style.transform = \'translateY(0)\';
                }, index * 100);
            });

            // Add click effect to buttons
            const buttons = document.querySelectorAll(\'.btn\');
            buttons.forEach(btn => {
                btn.addEventListener(\'mousedown\', function() {
                    this.style.transform = \'translateY(1px)\';
                });
                btn.addEventListener(\'mouseup\', function() {
                    this.style.transform = \'translateY(-2px)\';
                });
                btn.addEventListener(\'mouseleave\', function() {
                    this.style.transform = \'translateY(0)\';
                });
            });
        });
    </script>
</body>
</html>';
        exit;
    }

    /**
     * Submit customer feedback for a completed order
     */
    private function submitFeedback(int $orderId, int $userId, int $rating, string $comment): array
    {
        // Validate rating
        if ($rating < 1 || $rating > 5) {
            return ['status' => 'error', 'message' => 'Rating must be between 1 and 5 stars'];
        }

        // Verify order belongs to user and is completed
        $checkStmt = $this->conn->prepare(
            "SELECT OrderID FROM orders WHERE OrderID = ? AND User_ID = ? AND Status = 'Completed' LIMIT 1"
        );
        if (!$checkStmt) {
            return ['status' => 'error', 'message' => 'Database error'];
        }
        $checkStmt->bind_param('ii', $orderId, $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $order = $result->fetch_assoc();
        $checkStmt->close();

        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found or not completed'];
        }

        // Check if feedback already exists
        $existsStmt = $this->conn->prepare(
            "SELECT Feedback_ID FROM customer_feedback WHERE User_ID = ? AND OrderID = ? LIMIT 1"
        );
        if ($existsStmt) {
            $existsStmt->bind_param('ii', $userId, $orderId);
            $existsStmt->execute();
            $existsResult = $existsStmt->get_result();
            if ($existsResult->num_rows > 0) {
                $existsStmt->close();
                return ['status' => 'error', 'message' => 'You have already submitted feedback for this order'];
            }
            $existsStmt->close();
        }

        // Get first product from the order for Product_ID requirement
        $productStmt = $this->conn->prepare(
            "SELECT Product_ID FROM order_detail WHERE Order_ID = ? LIMIT 1"
        );
        $productId = null;
        if ($productStmt) {
            $productStmt->bind_param('i', $orderId);
            $productStmt->execute();
            $productResult = $productStmt->get_result();
            $productRow = $productResult->fetch_assoc();
            $productId = $productRow['Product_ID'] ?? null;
            $productStmt->close();
        }

        if (!$productId) {
            return ['status' => 'error', 'message' => 'Order details not found'];
        }

        // Insert feedback - matching new table structure with OrderID as NOT NULL
        $insertStmt = $this->conn->prepare(
            "INSERT INTO customer_feedback (OrderID, User_ID, Product_ID, Rating, Comment, Date_Submitted) 
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        
        if (!$insertStmt) {
            return ['status' => 'error', 'message' => 'Database error: ' . $this->conn->error];
        }

        $insertStmt->bind_param('iiiis', $orderId, $userId, $productId, $rating, $comment);
        $success = $insertStmt->execute();
        $error = $insertStmt->error;
        $insertStmt->close();

        if ($success) {
            return ['status' => 'success', 'message' => 'Thank you for your feedback!'];
        } else {
            return ['status' => 'error', 'message' => 'Failed to submit feedback: ' . $error];
        }
    }

    /**
     * Check if user has already submitted feedback for an order
     */
    private function hasOrderFeedback(int $orderId, int $userId): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT Feedback_ID FROM customer_feedback WHERE User_ID = ? AND OrderID = ? LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $userId, $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $hasFeedback = $result->num_rows > 0;
        $stmt->close();
        return $hasFeedback;
    }

    /**
     * Get the feedback row for a specific order and user
     */
    private function getFeedbackByOrder(int $orderId, int $userId): ?array
    {
        $sql = "SELECT cf.Rating, cf.Comment, cf.Date_Submitted, cf.Product_ID, p.Product_Name AS Product_Name
                FROM customer_feedback cf
                LEFT JOIN product p ON p.Product_ID = cf.Product_ID
                WHERE cf.OrderID = ? AND cf.User_ID = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $orderId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$row) return null;
        return [
            'rating' => (int)($row['Rating'] ?? 0),
            'comment' => $row['Comment'] ?? '',
            'product_id' => isset($row['Product_ID']) ? (int)$row['Product_ID'] : null,
            'product_name' => $row['Product_Name'] ?? null,
            'date_submitted' => $row['Date_Submitted'] ?? null,
        ];
    }

    public function getActiveAnnouncements(int $limit = 5): array
    {
        if (!$this->tableExists('announcements')) {
            return [];
        }

        $limitNormalized = $limit > 0 ? min($limit, 25) : 5;

        $sql = "SELECT Announcement_ID, Message, Audience, Is_Active, Expires_At, Created_At
                FROM announcements
                WHERE Is_Active = 1
                  AND LOWER(Audience) IN ('customer', 'customers', 'all')
                  AND (Expires_At IS NULL OR Expires_At >= NOW())
                ORDER BY Created_At DESC
                LIMIT ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $limitNormalized);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return array_map([$this, 'normalizeAnnouncementRow'], $rows);
    }

    private function normalizeAnnouncementRow(array $row): array
    {
        $message = trim((string)($row['Message'] ?? ''));

        $createdRaw = $row['Created_At'] ?? null;
        $createdFormatted = '';
        if ($createdRaw) {
            $created = date_create($createdRaw);
            if ($created instanceof \DateTimeInterface) {
                $createdFormatted = $created->format('M d, Y h:i A');
            }
        }

        $expiresRaw = $row['Expires_At'] ?? null;
        $expiresFormatted = '';
        if ($expiresRaw) {
            $expires = date_create($expiresRaw);
            if ($expires instanceof \DateTimeInterface) {
                $expiresFormatted = $expires->format('M d, Y h:i A');
            }
        }

        return [
            'id' => (int)($row['Announcement_ID'] ?? 0),
            'message' => $message,
            'created_at' => $createdRaw,
            'created_at_formatted' => $createdFormatted,
            'expires_at' => $expiresRaw,
            'expires_at_formatted' => $expiresFormatted,
        ];
    }

    private function canAdjustInventory(): bool
    {
        if ($this->inventoryAdjustAllowed !== null) {
            return $this->inventoryAdjustAllowed;
        }

        // Only require that product.Stock_Quantity exist to allow stock adjustments.
        // inventory_log presence is optional; when present, we will log changes.
        $this->inventoryAdjustAllowed = $this->columnExists('product', 'Stock_Quantity');

        return $this->inventoryAdjustAllowed;
    }

    private function inventoryLogHasUserId(): bool
    {
        return $this->columnExists('inventory_log', 'User_ID');
    }

    private function inventoryLogHasStaffId(): bool
    {
        return $this->columnExists('inventory_log', 'Staff_ID');
    }

    /**
     * Attempt to record inventory changes in the inventory_log table if possible.
     * actionType example: 'Add', 'Update', 'Remove'
     */
    private function recordInventoryLog(int $productId, int $quantityChange, ?int $userId, string $actionType = 'Update'): void
    {
        // The inventory_log schema may vary; attempt to write to the most common columns.
        if (!$this->columnExists('inventory_log', 'Quantity_Changed')) {
            return;
        }

        // Prefer User_ID if present; otherwise try Staff_ID when available.
        if ($userId !== null && $this->inventoryLogHasUserId()) {
            $stmt = $this->conn->prepare('INSERT INTO inventory_log (Product_ID, User_ID, Action_Type, Quantity_Changed, Log_Date) VALUES (?, ?, ?, ?, NOW())');
            if ($stmt) {
                $stmt->bind_param('iisi', $productId, $userId, $actionType, $quantityChange);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        if ($this->inventoryLogHasStaffId()) {
            // If we don't have a user id but the table expects Staff_ID, attempt to insert NULL staff
            $stmt = $this->conn->prepare('INSERT INTO inventory_log (Product_ID, Staff_ID, Action_Type, Quantity_Changed, Log_Date) VALUES (?, NULL, ?, ?, NOW())');
            if ($stmt) {
                $stmt->bind_param('isi', $productId, $actionType, $quantityChange);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        $sql = 'SELECT COUNT(*) AS cnt
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = ?
                  AND column_name = ?
                LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log('CustomerController::columnExists prepare failed for ' . $table . '.' . $column . ': ' . $this->conn->error);
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        $stmt->bind_param('ss', $table, $column);
        if (!$stmt->execute()) {
            error_log('CustomerController::columnExists execute failed for ' . $table . '.' . $column . ': ' . $stmt->error);
            $stmt->close();
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        $exists = $row ? ((int)($row['cnt'] ?? 0) > 0) : false;
        $this->columnExistsCache[$cacheKey] = $exists;

        return $exists;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}
