<?php
declare(strict_types=1);

require_once __DIR__ . '/../Config.php';

class StaffController
{
    private \mysqli $conn;
    /** @var array<string, bool> */
    private array $columnExistsCache = [];

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
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        if ($action === null) {
            return;
        }

        // Get pending orders for staff dashboard
        if ($action === 'get_pending_orders') {
            $this->getPendingOrders();
            return;
        }

        // Get dashboard statistics
        if ($action === 'get_dashboard_stats') {
            $this->getDashboardStats();
            return;
        }

        // Create a walk-in order
        if ($action === 'create_walkin_order') {
            $this->createWalkinOrder();
            return;
        }

        // Confirm order before completion
        if ($action === 'confirm_order' && isset($_POST['order_id'])) {
            $this->confirmOrder((int)$_POST['order_id']);
            return;
        }

        // Mark order as complete
        if ($action === 'complete_order' && isset($_POST['order_id'])) {
            $this->completeOrder((int)$_POST['order_id']);
            return;
        }

        // Save staff profile
        if ($action === 'save_profile') {
            $this->saveProfile();
            return;
        }

        // Get all reservations
        if ($action === 'get_reservations') {
            $this->getReservations();
            return;
        }

        // Confirm reservation
        if ($action === 'confirm_reservation' && isset($_POST['reservation_id'])) {
            $this->confirmReservation((int)$_POST['reservation_id']);
            return;
        }

        // Complete reservation
        if ($action === 'complete_reservation' && isset($_POST['reservation_id'])) {
            $this->completeReservation((int)$_POST['reservation_id']);
            return;
        }

        // Cancel reservation
        if ($action === 'cancel_reservation' && isset($_POST['reservation_id'])) {
            $this->cancelReservation((int)$_POST['reservation_id']);
            return;
        }

        // Get reservation status overview
        if ($action === 'get_reservation_status_overview') {
            $this->getReservationStatusOverview();
            return;
        }

        // Get stock alerts for inventory low/critical/out of stock
        if ($action === 'get_stock_alerts') {
            $this->getStockAlerts();
            return;
        }
    }
   public function getInventoryProducts(): array
    {
        $sql = "SELECT 
                    Product_ID,
                    Product_Name,
                    Category,
                    Price,
                    Stock_Quantity,
                    Low_Stock_Alert,
                    Image
                FROM product 
                ORDER BY Product_ID ASC";

        $result = $this->conn->query($sql);
        // For debugging, allow optional 'debug' param to return raw row
        $debug = isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true');
        $products = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $products[] = $this->normalizeProductRow($row);
            }
            $result->free();
        }

        return $products;
   }

    private function getProductById(int $productId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT Product_ID, Product_Name, Description, Category, Sub_category, 
                    Price, Stock_Quantity, Low_Stock_Alert
               FROM product WHERE Product_ID = ? LIMIT 1'
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? $this->normalizeProductRow($row) : null;
    }
    private function normalizeProductRow(array $row): array
    {
        $stock = (int)($row['Stock_Quantity'] ?? 0);

        $row['Product_ID']       = (int)($row['Product_ID'] ?? 0);
        $row['Price']            = (float)($row['Price'] ?? 0);
        $row['Stock_Quantity']   = $stock;
        $row['Product_Name']     = trim($row['Product_Name'] ?? '');
        $row['Category']         = trim($row['Category'] ?? '');
        $row['Low_Stock_Alert']  = $this->computeAlert($stock); // ← Always recompute!

        return $row;
    }

    private function computeAlert(int $stock): string
    {
        if ($stock >= 20) return 'Safe';
        if ($stock >= 10) return 'Low';
        if ($stock >= 1)  return 'Critical';
        return 'Out of Stock';
    }

    /**
     * Get all pending orders for staff dashboard
     */
    private function getPendingOrders(): void
    {
        $sql = "SELECT 
                    o.OrderID,
                    o.User_ID,
                    o.Order_Date,
                    o.Mode_Payment,
                    o.Total_Amount,
                    o.Status,
                    u.name AS customer_name,
                    u.email AS customer_email
                FROM orders o
                LEFT JOIN users u ON o.User_ID = u.user_id
                WHERE o.Status IN ('Pending', 'Confirmed')
                ORDER BY o.Order_Date DESC";

        $result = $this->conn->query($sql);
        $orders = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $orderId = (int)$row['OrderID'];
                $items = $this->getOrderItems($orderId);

                $isWalkIn = empty($row['User_ID']);
                $customerName = $row['customer_name'] ?? '';
                if ($isWalkIn && $customerName === '') {
                    $customerName = 'Walk-in Customer';
                }

                $orders[] = [
                    'OrderID' => $orderId,
                    'User_ID' => $row['User_ID'] !== null ? (int)$row['User_ID'] : null,
                    'Order_Date' => (function($raw) {
                        if (!$raw) return '';
                        try {
                            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                            return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format(DATE_ATOM);
                        } catch (\Throwable $e) {
                            return (string)$raw;
                        }
                    })($row['Order_Date']),
                    'Mode_Payment' => $row['Mode_Payment'],
                    'Total_Amount' => (float)$row['Total_Amount'],
                    'Status' => $row['Status'],
                    'customer_name' => $customerName,
                    'customer_email' => $row['customer_email'] ?? '',
                    'order_source' => $isWalkIn ? 'Walk-In' : 'Online',
                    'service_type' => $isWalkIn ? 'Walk-In' : 'Online',
                    'items' => $items
                ];
            }
            $result->free();
        }

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'orders' => $orders]);
    }

    /**
     * Get dashboard statistics from database
     */
    private function getDashboardStats(): void
    {
        // Get pending count
        $pendingResult = $this->conn->query("SELECT COUNT(*) as count FROM orders WHERE Status = 'Pending'");
        $pendingCount = $pendingResult ? $pendingResult->fetch_assoc()['count'] : 0;

        // Get completed today count
        $completedTodayResult = $this->conn->query(
            "SELECT COUNT(*) as count FROM orders 
             WHERE Status = 'Completed' 
             AND DATE(Order_Date) = CURDATE()"
        );
        $completedToday = $completedTodayResult ? $completedTodayResult->fetch_assoc()['count'] : 0;

        // Get pending reservations count (all pending reservations regardless of date) - use case-insensitive match
        $reserveResult = $this->conn->query(
            "SELECT COUNT(*) as count FROM reservation WHERE LOWER(TRIM(Payment_Status)) = 'pending'"
        );
        $reserveCount = $reserveResult ? $reserveResult->fetch_assoc()['count'] : 0;

        // Get online orders count (all orders with User_ID)
        $onlineResult = $this->conn->query("SELECT COUNT(*) as count FROM orders WHERE User_ID IS NOT NULL");
        $onlineCount = $onlineResult ? $onlineResult->fetch_assoc()['count'] : 0;

        // Get total revenue from completed orders
        $revenueResult = $this->conn->query(
            "SELECT COALESCE(SUM(Total_Amount), 0) as revenue 
             FROM orders 
             WHERE Status = 'Completed'"
        );
        $revenue = $revenueResult ? $revenueResult->fetch_assoc()['revenue'] : 0;

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'stats' => [
                'pending' => (int)$pendingCount,
                'completedToday' => (int)$completedToday,
                'pendingReservations' => (int)$reserveCount,
                'online' => (int)$onlineCount,
                'revenue' => (float)$revenue
            ]
        ]);
    }

    /**
     * Get items for a specific order
     */
    private function getOrderItems(int $orderId): array
    {
        $priceColumn = $this->resolveOrderDetailPriceColumn();

        $select = [
            'od.OrderDetail_ID',
            'od.Product_ID',
            'od.Quantity',
        ];

        if ($priceColumn !== null) {
            $select[] = "od.$priceColumn AS line_price";
        } else {
            $select[] = '0 AS line_price';
        }

        if ($this->hasOrderDetailSubtotal()) {
            $select[] = 'od.Subtotal AS line_subtotal';
        }

        $select[] = 'p.Product_Name';

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM order_detail od JOIN product p ON od.Product_ID = p.Product_ID WHERE od.Order_ID = ?';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];

        while ($row = $result->fetch_assoc()) {
            $quantity = (int)$row['Quantity'];
            $unitPrice = isset($row['line_price']) ? (float)$row['line_price'] : 0.0;
            $subtotal = isset($row['line_subtotal']) ? (float)$row['line_subtotal'] : $unitPrice * $quantity;

            $items[] = [
                'OrderDetail_ID' => (int)$row['OrderDetail_ID'],
                'Product_ID' => (int)$row['Product_ID'],
                'Quantity' => $quantity,
                'Price' => $unitPrice,
                'Subtotal' => $subtotal,
                'Product_Name' => $row['Product_Name']
            ];
        }

        $stmt->close();
        return $items;
    }

    private function createWalkinOrder(): void
    {
        if (!isset($_POST['order'])) {
            $this->sendJson(['status' => 'error', 'message' => 'Missing order payload.'], 400);
            return;
        }

        $payload = json_decode((string)$_POST['order'], true);
        if (!is_array($payload)) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid order payload.'], 400);
            return;
        }

        $customerName = trim((string)($payload['customerName'] ?? ''));
        $serviceTypeInput = (string)($payload['orderType'] ?? '');
        $serviceType = $this->normalizeServiceType($serviceTypeInput);
        $paymentType = strtoupper(trim((string)($payload['paymentType'] ?? '')));
        $itemsPayload = $payload['items'] ?? [];
        $cashTenderedRaw = $payload['cashTendered'] ?? null;

        if ($customerName === '') {
            $this->sendJson(['status' => 'error', 'message' => 'Customer name is required.'], 422);
            return;
        }

        if ($serviceType === null) {
            $this->sendJson(['status' => 'error', 'message' => 'Order type must be either Dine In or Take Out.'], 422);
            return;
        }

        if ($paymentType !== 'CASH') {
            $this->sendJson(['status' => 'error', 'message' => 'Only cash payments are accepted for walk-in orders.'], 422);
            return;
        }

        if (!is_array($itemsPayload) || empty($itemsPayload)) {
            $this->sendJson(['status' => 'error', 'message' => 'At least one item is required.'], 422);
            return;
        }

        if (!is_numeric($cashTenderedRaw)) {
            $this->sendJson(['status' => 'error', 'message' => 'Cash tendered must be provided.'], 422);
            return;
        }

        $cashTendered = round((float)$cashTenderedRaw, 2);

        $lineItems = [];
        foreach ($itemsPayload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = isset($item['productId']) ? (int)$item['productId'] : 0;
            $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 0;

            if ($productId <= 0 || $quantity <= 0) {
                $this->sendJson(['status' => 'error', 'message' => 'Each item must have a valid product and quantity.'], 422);
                return;
            }

            if (isset($lineItems[$productId])) {
                $lineItems[$productId]['quantity'] += $quantity;
            } else {
                $lineItems[$productId] = [
                    'product_id' => $productId,
                    'quantity' => $quantity
                ];
            }
        }

        if (empty($lineItems)) {
            $this->sendJson(['status' => 'error', 'message' => 'Order items are invalid.'], 422);
            return;
        }

        $productIds = array_keys($lineItems);
        $transactionStarted = false;

        try {
            $this->conn->begin_transaction();
            $transactionStarted = true;

            $productData = $this->fetchProductsForUpdate($productIds);
            $computedTotal = 0.0;
            $preparedItems = [];

            foreach ($lineItems as $productId => $line) {
                if (!isset($productData[$productId])) {
                    throw new \InvalidArgumentException('A selected product is no longer available.');
                }

                $productRow = $productData[$productId];
                $requestedQty = $line['quantity'];
                $availableStock = (int)$productRow['Stock_Quantity'];

                if ($availableStock < $requestedQty) {
                    throw new \InvalidArgumentException('Insufficient stock for ' . $productRow['Product_Name']);
                }

                $unitPrice = (float)$productRow['Price'];
                $subtotal = round($unitPrice * $requestedQty, 2);
                $computedTotal = round($computedTotal + $subtotal, 2);

                $preparedItems[] = [
                    'product_id' => $productId,
                    'product_name' => $productRow['Product_Name'],
                    'quantity' => $requestedQty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal
                ];
            }

            if ($computedTotal <= 0) {
                throw new \RuntimeException('Computed total is invalid.');
            }

            if ($cashTendered + 0.0001 < $computedTotal) {
                throw new \InvalidArgumentException('Cash amount cannot be less than the order total.');
            }

            $changeDue = round($cashTendered - $computedTotal, 2);

            $modePayment = 'Cash';
            $status = 'Completed';

            $orderStmt = $this->conn->prepare(
                'INSERT INTO orders (User_ID, Order_Date, Mode_Payment, Total_Amount, Status) VALUES (NULL, NOW(), ?, ?, ?)'
            );
            if (!$orderStmt) {
                throw new \RuntimeException('Failed to prepare order insert statement.');
            }

            $orderStmt->bind_param('sds', $modePayment, $computedTotal, $status);

            if (!$orderStmt->execute()) {
                $error = $orderStmt->error;
                $orderStmt->close();
                throw new \RuntimeException('Failed to save order. ' . $error);
            }

            $orderId = (int)$orderStmt->insert_id;
            $orderStmt->close();

            foreach ($preparedItems as $item) {
                $this->insertOrderDetailRow($orderId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']);
                $this->decrementProductStock($item['product_id'], $item['quantity']);
            }

            $this->conn->commit();
            $transactionStarted = false;

            $orderSummary = [
                'order_id' => $orderId,
                'order_code' => $this->formatOrderCode($orderId),
                'customer_name' => $customerName,
                'service_type' => $serviceType,
                'cash_tendered' => $cashTendered,
                'change_due' => $changeDue,
                'total_amount' => $computedTotal,
                'created_at' => new \DateTimeImmutable('now'),
                'payment_type' => 'Cash'
            ];

            $pdfBinary = $this->generateReceiptPdf($orderSummary, $preparedItems);
            $receiptFilename = sprintf('walkin_receipt_%s_order_%s.pdf', (new \DateTimeImmutable('now'))->format('Ymd_His'), str_pad((string)$orderId, 5, '0', STR_PAD_LEFT));

            $savedReceiptPath = $this->saveReceiptPdf($receiptFilename, $pdfBinary);

            $this->sendJson([
                'status' => 'success',
                'message' => 'Walk-in order recorded successfully.',
                'order_id' => $orderId,
                'order_code' => $orderSummary['order_code'],
                'order_status' => $status,
                'order_source' => 'Walk-In',
                'total_amount' => $computedTotal,
                'cash_tendered' => $cashTendered,
                'change_due' => $changeDue,
                'service_type' => $serviceType,
                'receipt_filename' => $receiptFilename,
                'receipt_pdf' => base64_encode($pdfBinary),
                'receipt_path' => $savedReceiptPath
            ]);
        } catch (\Throwable $throwable) {
            if ($transactionStarted) {
                $this->conn->rollback();
            }

            $isValidationError = $throwable instanceof \InvalidArgumentException;
            if (!$isValidationError) {
                error_log('createWalkinOrder failed: ' . $throwable->getMessage());
            }

            $this->sendJson([
                'status' => 'error',
                'message' => $throwable->getMessage()
            ], $isValidationError ? 422 : 500);
        }
    }

    /**
     * Confirm an order so it can proceed to completion
     */
    private function confirmOrder(int $orderId): void
    {
        $order = $this->fetchOrderDetails($orderId);

        if (!$order) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Order not found']);
            return;
        }

        if ($order['Status'] === 'Confirmed') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'message' => 'Order already confirmed']);
            return;
        }

        if ($order['Status'] !== 'Pending') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Only pending orders can be confirmed'
            ]);
            return;
        }

        $stmt = $this->conn->prepare("UPDATE orders SET Status = 'Confirmed' WHERE OrderID = ?");
        $stmt->bind_param('i', $orderId);

        if (!$stmt->execute()) {
            $stmt->close();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Failed to update order status']);
            return;
        }

        $stmt->close();

        $emailSent = false;

        if (!empty($order['customer_email'])) {
            require_once __DIR__ . '/EmailApiController.php';
            $items = $this->getOrderItems($orderId);
            $emailDetails = [
                'order_id' => $orderId,
                'order_date' => (function($raw) {
                    if (!$raw) return '';
                    try {
                        $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                        return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format(DATE_ATOM);
                    } catch (\Throwable $e) {
                        return (string)$raw;
                    }
                })($order['Order_Date']),
                'payment_method' => $order['Mode_Payment'],
                'status' => 'Confirmed',
                'total_amount' => $order['Total_Amount'],
                'items' => $items,
                'message' => 'We will notify you again once your order has been delivered.'
            ];

            $emailResult = EmailApiController::sendOrderStatusEmail(
                $order['customer_email'],
                $order['customer_name'],
                $emailDetails
            );

            if ($emailResult !== true) {
                error_log('Failed to send confirmation email: ' . $emailResult);
            } else {
                $emailSent = true;
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Order confirmed successfully',
            'email_sent' => $emailSent
        ]);
    }

    /**
     * Mark an order as complete and send email notification
     */
    private function completeOrder(int $orderId): void
    {
        $order = $this->fetchOrderDetails($orderId);

        if (!$order) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Order not found']);
            return;
        }

        if ($order['Status'] !== 'Confirmed') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'Confirm the order before completing it'
            ]);
            return;
        }

        // Update order status to Completed
        $stmt = $this->conn->prepare("UPDATE orders SET Status = 'Completed' WHERE OrderID = ?");
        $stmt->bind_param('i', $orderId);
        
        if (!$stmt->execute()) {
            $stmt->close();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Failed to update order status']);
            return;
        }
        $stmt->close();

            // Send email notification if customer has email
        if (!empty($order['customer_email'])) {
            require_once __DIR__ . '/EmailApiController.php';
            
            // Get order items
            $items = $this->getOrderItems($orderId);
            
            // Prepare email details
            $emailDetails = [
                'order_id' => $orderId,
                'order_date' => (function($raw) {
                    if (!$raw) return '';
                    try {
                        $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                        return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format(DATE_ATOM);
                    } catch (\Throwable $e) {
                        return (string)$raw;
                    }
                })($order['Order_Date']),
                'payment_method' => $order['Mode_Payment'],
                'status' => 'Completed',
                'total_amount' => $order['Total_Amount'],
                'change' => 0,
                'items' => $items
            ];

            // Log suspicious items (if any) before sending the email
            foreach ($items as $idx => $it) {
                $nameKey = $it['Product_Name'] ?? $it['name'] ?? '';
                if (trim($nameKey) === '' || stripos((string)$nameKey, 'unknown') !== false) {
                    error_log("Warning: completion email for order {$orderId} contains item with missing/unknown name at index {$idx}. Item data: " . json_encode($it));
                }
            }

            // Send email (don't fail the order completion if email fails)
            $emailResult = EmailApiController::sendReceiptEmail(
                $order['customer_email'],
                $order['customer_name'],
                $emailDetails
            );
            
            if ($emailResult !== true) {
                error_log('Failed to send completion email: ' . $emailResult);
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Order marked as complete',
            'email_sent' => !empty($order['customer_email'])
        ]);
    }

    private function normalizeServiceType(string $serviceType): ?string
    {
        $normalized = strtolower(trim($serviceType));

        if (in_array($normalized, ['dine in', 'dine-in'], true)) {
            return 'Dine In';
        }

        if (in_array($normalized, ['take out', 'take-out', 'takeaway'], true)) {
            return 'Take Out';
        }

        return null;
    }

    /**
     * @param int[] $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsForUpdate(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $sql = "SELECT Product_ID, Product_Name, Price, Stock_Quantity FROM product WHERE Product_ID IN ($placeholders) FOR UPDATE";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare product lookup statement.');
        }

        $types = str_repeat('i', count($productIds));
        $ids = array_values(array_map('intval', $productIds));
        $this->bindParams($stmt, $types, $ids);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Failed to fetch product data. ' . $error);
        }

        $result = $stmt->get_result();
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[(int)$row['Product_ID']] = [
                'Product_ID' => (int)$row['Product_ID'],
                'Product_Name' => (string)($row['Product_Name'] ?? ''),
                'Price' => isset($row['Price']) ? (float)$row['Price'] : 0.0,
                'Stock_Quantity' => isset($row['Stock_Quantity']) ? (int)$row['Stock_Quantity'] : 0
            ];
        }

        $stmt->close();
        return $products;
    }

    private function insertOrderDetailRow(int $orderId, int $productId, int $quantity, float $unitPrice, float $subtotal): void
    {
        $columns = ['Order_ID', 'Product_ID', 'Quantity'];
        $types = 'iii';
        $values = [$orderId, $productId, $quantity];

        $priceColumn = $this->resolveOrderDetailPriceColumn();
        if ($priceColumn !== null) {
            $columns[] = $priceColumn;
            $types .= 'd';
            $values[] = $unitPrice;
        }

        if ($this->hasOrderDetailSubtotal()) {
            $columns[] = 'Subtotal';
            $types .= 'd';
            $values[] = $subtotal;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO order_detail (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare order detail statement.');
        }

        $this->bindParams($stmt, $types, $values);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Failed to save order items. ' . $error);
        }

        $stmt->close();
    }

    private function decrementProductStock(int $productId, int $quantity): void
    {
        // Ensure product has Stock_Quantity column before attempting to adjust stock
        if (!$this->columnExists('product', 'Stock_Quantity')) {
            return;
        }

        $stmt = $this->conn->prepare('UPDATE product SET Stock_Quantity = Stock_Quantity - ? WHERE Product_ID = ?');
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare inventory update.');
        }

        $stmt->bind_param('ii', $quantity, $productId);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Failed to update inventory. ' . $error);
        }

        $stmt->close();

        // Try to write to inventory_log if available (either Staff_ID or User_ID)
        $actorId = 0;
        if (isset($_SESSION['user_id'])) $actorId = (int)$_SESSION['user_id'];
        elseif (isset($_SESSION['user']['user_id'])) $actorId = (int)$_SESSION['user']['user_id'];

        if ($this->columnExists('inventory_log', 'Staff_ID')) {
            $stmt2 = $this->conn->prepare('INSERT INTO inventory_log (Product_ID, Staff_ID, Action_Type, Quantity_Changed, Log_Date) VALUES (?, ?, ?, ?, NOW())');
            if ($stmt2) {
                $action = 'Remove';
                $stmt2->bind_param('iisi', $productId, $actorId, $action, $quantity);
                $stmt2->execute();
                $stmt2->close();
            }
        } elseif ($this->columnExists('inventory_log', 'User_ID')) {
            $stmt2 = $this->conn->prepare('INSERT INTO inventory_log (Product_ID, User_ID, Action_Type, Quantity_Changed, Log_Date) VALUES (?, ?, ?, ?, NOW())');
            if ($stmt2) {
                $action = 'Remove';
                $stmt2->bind_param('iisi', $productId, $actorId, $action, $quantity);
                $stmt2->execute();
                $stmt2->close();
            }
        }
    }

    private function formatOrderCode(int $orderId): string
    {
        return 'WALKIN-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $items
     */
    private function generateReceiptPdf(array $summary, array $items): string
    {
        $width = $this->receiptWidth();
        $lines = [];

        $lines[] = $this->receiptDivider($width);
        $lines[] = $this->centerReceiptLine("Guillermo's Coffee and Pasta", $width);
        $lines[] = $this->centerReceiptLine(' camp avejar, 112 J P Laurel St, Nasugbu, Batangas', $width);
        $lines[] = $this->receiptDivider($width);

        $lines[] = $this->formatKeyValueRow('Receipt #', $summary['order_code'], $width);
        if ($summary['created_at'] instanceof \DateTimeInterface) {
            $lines[] = $this->formatKeyValueRow('Date', $summary['created_at']->format('M d, Y h:i A'), $width);
        }
        $lines[] = $this->formatKeyValueRow('Customer', (string)$summary['customer_name'], $width);
        $lines[] = $this->formatKeyValueRow('Service', (string)$summary['service_type'], $width);
        $lines[] = $this->formatKeyValueRow('Payment', (string)$summary['payment_type'], $width);
        $lines[] = $this->receiptDivider($width);

        $lines[] = $this->centerReceiptLine('Order Summary', $width);
        $lines[] = $this->receiptDivider($width);
        $lines[] = 'Qty  Item' . str_repeat(' ', max(0, $width - 4 - 4 - 10)) . 'Amount';
        $lines[] = $this->receiptDivider($width);

        foreach ($items as $item) {
            foreach ($this->formatItemRows($item, $width) as $row) {
                $lines[] = $row;
            }
        }

        $lines[] = $this->receiptDivider($width);
        $lines[] = $this->formatKeyValueRow('Subtotal', $this->formatCurrency((float)$summary['total_amount']), $width);
        $lines[] = $this->formatKeyValueRow('Cash Received', $this->formatCurrency((float)$summary['cash_tendered']), $width);
        $lines[] = $this->formatKeyValueRow('Change', $this->formatCurrency((float)$summary['change_due']), $width);
        $lines[] = $this->receiptDivider($width);

        $lines[] = $this->centerReceiptLine("Thank you for choosing Guillermo's!", $width);
        $lines[] = $this->centerReceiptLine('Show this receipt for loyalty points.', $width);
        $lines[] = $this->receiptDivider($width);

        return $this->buildSimplePdf($lines);
    }

    private function wrapReceiptLine(string $line, int $width = 60): array
    {
        if ($width <= 0) {
            return [$line];
        }

        $wrapped = wordwrap($line, $width, "\n", true);
        return explode("\n", $wrapped);
    }

    /**
     * @param string[] $lines
     */
    private function buildSimplePdf(array $lines): string
    {
        $contentLines = ['BT', '/F1 12 Tf', '1 0 0 1 60 760 Tm', '14 TL'];

        $first = true;
        foreach ($lines as $line) {
            $safe = $this->pdfEscape($line);
            if ($first) {
                $contentLines[] = '(' . $safe . ') Tj';
                $first = false;
                continue;
            }

            $contentLines[] = 'T*';
            $contentLines[] = '(' . $safe . ') Tj';
        }

        $contentLines[] = 'ET';
        $content = implode("\n", $contentLines) . "\n";

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $stream = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\n";
        $objects[] = "5 0 obj\n$stream\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $offset = strlen($pdf);

        foreach ($objects as $object) {
            $offsets[] = $offset;
            $pdf .= $object;
            $offset = strlen($pdf);
        }

        $xrefPosition = $offset;
        $pdf .= "xref\n";
        $pdf .= '0 ' . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $objectOffset) {
            $pdf .= sprintf('%010d 00000 n ', $objectOffset) . "\n";
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPosition . "\n";
        $pdf .= "%%EOF";

        return $pdf;
    }

    private function pdfEscape(string $text): string
    {
        $sanitized = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return preg_replace('/[^\x20-\x7E]/', '', $sanitized) ?? '';
    }

    private function sanitizeReceiptText(string $text): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
    }

    private function receiptWidth(): int
    {
        return 48;
    }

    private function receiptDivider(int $width): string
    {
        return '+' . str_repeat('-', max(0, $width - 2)) . '+';
    }

    private function centerReceiptLine(string $text, int $width): string
    {
        $safe = $this->sanitizeReceiptText($text);
        $length = strlen($safe);
        if ($length >= $width) {
            return substr($safe, 0, $width);
        }

        $padLeft = (int)floor(($width - $length) / 2);
        $padRight = $width - $length - $padLeft;

        return str_repeat(' ', $padLeft) . $safe . str_repeat(' ', $padRight);
    }

    private function formatKeyValueRow(string $label, string $value, int $width): string
    {
        $safeLabel = $this->sanitizeReceiptText($label);
        $safeValue = $this->sanitizeReceiptText($value);
        $available = max(0, $width - strlen($safeValue) - 1);

        if (strlen($safeLabel) > $available) {
            $safeLabel = substr($safeLabel, 0, $available);
        }

        return str_pad($safeLabel, $available, ' ') . ' ' . $safeValue;
    }

    /**
     * @param array<string, mixed> $item
     * @return string[]
     */
    private function formatItemRows(array $item, int $width): array
    {
        $rows = [];
        $qtyColWidth = 3;
        $amountColWidth = 12;
        $nameWidth = max(4, $width - $qtyColWidth - 1 - $amountColWidth);

        $qty = str_pad((string)max(1, (int)($item['quantity'] ?? 0)), $qtyColWidth, ' ', STR_PAD_LEFT);
        $total = str_pad($this->formatCurrency((float)($item['subtotal'] ?? 0)), $amountColWidth, ' ', STR_PAD_LEFT);
        $name = $this->sanitizeReceiptText((string)($item['product_name'] ?? ''));
        $nameChunks = $this->wrapReceiptLine($name, $nameWidth);
        if (empty($nameChunks)) {
            $nameChunks = [''];
        }

        foreach ($nameChunks as $index => $chunk) {
            $chunkPadded = str_pad($chunk, $nameWidth, ' ');
            if ($index === 0) {
                $rows[] = $qty . ' ' . $chunkPadded . $total;
            } else {
                $rows[] = str_repeat(' ', $qtyColWidth + 1) . $chunkPadded . str_repeat(' ', $amountColWidth);
            }
        }

        $unitLine = '    @ ' . $this->formatCurrency((float)($item['unit_price'] ?? 0)) . ' each';
        foreach ($this->wrapReceiptLine($unitLine, $width) as $chunk) {
            $rows[] = str_pad($chunk, $width, ' ');
        }

        $rows[] = '';

        return $rows;
    }

    private function formatCurrency(float $amount): string
    {
        return 'PHP ' . number_format($amount, 2);
    }

    private function saveReceiptPdf(string $filename, string $pdfBinary): ?string
    {
        $baseDir = dirname(__DIR__) . '/storage/receipts';
        $resolvedDir = $this->prepareReceiptDirectory($baseDir);

        if ($resolvedDir === null) {
            $fallback = dirname(__DIR__) . '/storage_receipts';
            $resolvedDir = $this->prepareReceiptDirectory($fallback);
        }

        if ($resolvedDir === null) {
            return null;
        }

        $resolvedDir = rtrim($resolvedDir, DIRECTORY_SEPARATOR);
        $fullPath = $resolvedDir . DIRECTORY_SEPARATOR . $filename;

        if (@file_put_contents($fullPath, $pdfBinary) === false) {
            return null;
        }

        return $fullPath;
    }

    private function prepareReceiptDirectory(string $path): ?string
    {
        if (is_dir($path)) {
            return $path;
        }

        if (file_exists($path) && !is_dir($path)) {
            return null;
        }

        if (@mkdir($path, 0775, true)) {
            return $path;
        }

        return null;
    }

    private function resolveOrderDetailPriceColumn(): ?string
    {
        foreach (['unitprice', 'Price'] as $column) {
            if ($this->columnExists('order_detail', $column)) {
                return $column;
            }
        }

        return null;
    }

    private function hasOrderDetailSubtotal(): bool
    {
        return $this->columnExists('order_detail', 'Subtotal');
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = strtolower($table . '.' . $column);
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        $stmt = $this->conn->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );

        if (!$stmt) {
            error_log('columnExists prepare failed for ' . $table . '.' . $column . ': ' . $this->conn->error);
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        $this->columnExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private function bindParams(\mysqli_stmt $stmt, string $types, array $values): void
    {
        $refs = [];
        foreach ($values as $key => $value) {
            $refs[$key] = &$values[$key];
        }

        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    private function sendJson(array $payload, int $code = 200): void
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        http_response_code($code);

        // Attempt robust JSON encoding: allow partial output on error and unescaped Unicode
        $json = @json_encode($payload, defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? (JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Try to sanitize payload to valid UTF-8 and encode again
            $cleanPayload = $this->utf8ize($payload);
            $json = @json_encode($cleanPayload, defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? (JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : JSON_UNESCAPED_UNICODE);
        }

        if ($json === false) {
            // As a last resort, return a simple error JSON
            $fallback = ['status' => 'error', 'message' => 'Failed to construct JSON response'];
            echo json_encode($fallback);
            return;
        }

        echo $json;
    }

    private function utf8ize($mixed)
    {
        if (is_array($mixed)) {
            $out = [];
            foreach ($mixed as $k => $v) {
                $out[$k] = $this->utf8ize($v);
            }
            return $out;
        }
        if (is_string($mixed)) {
            if (function_exists('mb_check_encoding') && !mb_check_encoding($mixed, 'UTF-8')) {
                if (function_exists('mb_convert_encoding')) {
                    return mb_convert_encoding($mixed, 'UTF-8', 'auto');
                } else {
                    // Last resort: replace invalid bytes with utf8 replacement sequence
                    return utf8_encode($mixed);
                }
            }
            return $mixed;
        }
        return $mixed;
    }

    private function fetchOrderDetails(int $orderId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT 
                o.OrderID,
                o.User_ID,
                o.Order_Date,
                o.Mode_Payment,
                o.Total_Amount,
                o.Status,
                u.name as customer_name,
                u.email as customer_email
             FROM orders o
             LEFT JOIN users u ON o.User_ID = u.user_id
             WHERE o.OrderID = ?"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();

        return $order ?: null;
    }

    /**
     * Save staff profile information
     */
    private function saveProfile(): void
    {
        $staffId = $_SESSION['user_id'] ?? null;
        
        if (!$staffId) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Not logged in']);
            return;
        }

        $fullname = trim($_POST['fullname'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($fullname) || empty($username)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Name and username are required']);
            return;
        }

        // Update users table (staff are stored in users table with User_Role = 'Staff')
        $stmt = $this->conn->prepare(
            "UPDATE users SET Name = ?, Username = ?, Email = ?, Phonenumber = ? WHERE User_ID = ? AND User_Role = 'Staff'"
        );
        $stmt->bind_param('ssssi', $fullname, $username, $email, $phone, $staffId);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['user']['Name'] = $fullname;
            $_SESSION['user']['Username'] = $username;
            $_SESSION['user']['Email'] = $email;
            $_SESSION['user']['Phonenumber'] = $phone;
            
            $stmt->close();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
        } else {
            $stmt->close();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update profile - no staff user found or no changes made']);
        }
    }

    public function __destruct()
    {
        if ($this->conn) {
            $this->conn->close();
        }
    }
    
    public function countCriticalProducts(): int
    {
        $products = $this->getInventoryProducts();
        $criticalCount = 0;

        foreach ($products as $p) {
            if (($p['Low_Stock_Alert'] ?? '') === 'Critical') {
                $criticalCount++;
            }
        }

        return $criticalCount;
    }

    /**
     * Get all reservations with customer and product details
     */
    private function getReservations(): void
    {
        // Support optional status filter, e.g., ?action=get_reservations&status=Pending
        $statusFilter = $_GET['status'] ?? $_POST['status'] ?? null;

        if ($statusFilter) {
            $sql = "SELECT 
                        r.Reservation_ID,
                        r.User_ID,
                        r.Product_ID,
                        r.Reservation_Date,
                        r.Payment_Status,
                        u.name as customer_name,
                        u.email as customer_email,
                        p.Product_Name,
                        p.Price
                    FROM reservation r
                    LEFT JOIN users u ON r.User_ID = u.user_id
                    LEFT JOIN product p ON r.Product_ID = p.Product_ID
                    WHERE r.Payment_Status = ?
                    ORDER BY r.Reservation_Date DESC";

            $stmt = $this->conn->prepare($sql);
            if ($stmt) {
                // Normalize the incoming filter to match expected values (case-insensitive)
                $normalizedFilter = ucfirst(strtolower(trim((string)$statusFilter)));
                $stmt->bind_param('s', $normalizedFilter);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = false;
            }
        } else {
            $sql = "SELECT 
                        r.Reservation_ID,
                        r.User_ID,
                        r.Product_ID,
                        r.Reservation_Date,
                        r.Payment_Status,
                        u.name as customer_name,
                        u.email as customer_email,
                        p.Product_Name,
                        p.Price
                    FROM reservation r
                    LEFT JOIN users u ON r.User_ID = u.user_id
                    LEFT JOIN product p ON r.Product_ID = p.Product_ID
                    ORDER BY r.Reservation_Date DESC";

            $result = $this->conn->query($sql);
        }
        $reservations = [];

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $raw = $row['Reservation_Date'] ?? null;
                $converted = '';
                if ($raw) {
                    try {
                        $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                        $converted = $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format(DATE_ATOM);
                    } catch (\Throwable $e) {
                        $converted = (string)$raw;
                    }
                }
                $row['Reservation_Date'] = $converted;
                // Normalize Payment_Status so front-end can rely on standard values
                $row['Payment_Status'] = isset($row['Payment_Status']) ? ucfirst(strtolower(trim((string)$row['Payment_Status']))) : '';
                $reservations[] = $row;
            }
            $result->free();
        }

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'reservations' => $reservations]);
    }

    /**
     * Return all products where Low_Stock_Alert is not 'Safe'.
     * This recomputes the status from Stock_Quantity to be consistent.
     */
    private function getStockAlerts(): void
    {
        try {
            // Clear any output buffers to prevent JSON corruption
            while (ob_get_level()) ob_end_clean();

            $sql = "SELECT Product_ID, Product_Name, Category, Price, Stock_Quantity FROM product";
            $result = $this->conn->query($sql);
            $alerts = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $stockQty = (int)($row['Stock_Quantity'] ?? 0);
                    $alert = 'Safe';
                    if ($stockQty >= 20) $alert = 'Safe';
                    elseif ($stockQty >= 10) $alert = 'Low';
                    elseif ($stockQty >= 1) $alert = 'Critical';
                    else $alert = 'Out of Stock';

                    if ($alert !== 'Safe') {
                        $alerts[] = [
                            'Product_ID' => (int)$row['Product_ID'],
                            'Product_Name' => (string)($row['Product_Name'] ?? ''),
                            'Category' => (string)($row['Category'] ?? ''),
                            'Price' => isset($row['Price']) ? (float)$row['Price'] : 0.0,
                            'Stock_Quantity' => $stockQty,
                            'Low_Stock_Alert' => $alert
                        ];
                    }
                }
                $result->free();
            }

            error_log('getStockAlerts returning ' . count($alerts) . ' alerts.');
            $this->sendJson(['status' => 'success', 'alerts' => $alerts]);
        } catch (\Throwable $e) {
            error_log('getStockAlerts error: ' . $e->getMessage());
            $this->sendJson(['status' => 'error', 'message' => 'Failed to fetch stock alerts.'], 500);
        }
    }

    /**
     * Confirm a reservation
     */
    private function confirmReservation(int $reservationId): void
    {
        // Clear any output buffers to prevent JSON corruption
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        error_reporting(0);
        ini_set('display_errors', 0);
        header('Content-Type: application/json');
        
        $stmt = $this->conn->prepare("UPDATE reservation SET Payment_Status = 'Confirmed' WHERE Reservation_ID = ? AND Payment_Status = 'Pending'");
        $stmt->bind_param('i', $reservationId);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            echo json_encode(['status' => 'success', 'message' => 'Reservation confirmed successfully']);
        } else {
            $stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'Failed to confirm reservation']);
        }
        exit;
    }

    /**
     * Complete a reservation and add to revenue
     */
    private function completeReservation(int $reservationId): void
    {
        // Clear any output buffers to prevent JSON corruption
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Start fresh buffer
        ob_start();
        
        // Suppress errors
        error_reporting(0);
        ini_set('display_errors', 0);
        
        header('Content-Type: application/json');
        
        // Get reservation details
        $stmt = $this->conn->prepare(
            "SELECT r.Reservation_ID, r.User_ID, r.Product_ID, r.Payment_Status, p.Price
             FROM reservation r
             JOIN product p ON r.Product_ID = p.Product_ID
             WHERE r.Reservation_ID = ?"
        );
        if (!$stmt) {
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Database prepare error']);
            exit;
        }
        $stmt->bind_param('i', $reservationId);
        if (!$stmt->execute()) {
            $stmt->close();
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch reservation']);
            exit;
        }
        $result = $stmt->get_result();
        $reservation = $result->fetch_assoc();
        $stmt->close();

        if (!$reservation) {
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Reservation not found']);
            exit;
        }

        if ($reservation['Payment_Status'] !== 'Confirmed') {
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Only confirmed reservations can be completed']);
            exit;
        }

        // Update reservation status to Completed
        $stmt = $this->conn->prepare("UPDATE reservation SET Payment_Status = 'Completed' WHERE Reservation_ID = ?");
        if (!$stmt) {
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Database prepare error']);
            exit;
        }
        $stmt->bind_param('i', $reservationId);
        
        if (!$stmt->execute()) {
            $stmt->close();
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Failed to complete reservation']);
            exit;
        }
        $stmt->close();

        // Create an order for this reservation
        $totalAmount = (float)$reservation['Price'];
        $stmt = $this->conn->prepare(
            "INSERT INTO orders (User_ID, Order_Date, Status, Total_Amount, Mode_Payment) 
             VALUES (?, NOW(), 'Completed', ?, 'Cash')"
        );
        if (!$stmt) {
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Database prepare error']);
            exit;
        }
        $stmt->bind_param('id', $reservation['User_ID'], $totalAmount);
        
        if (!$stmt->execute()) {
            $stmt->close();
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Failed to create order']);
            exit;
        }
        
        $orderId = $this->conn->insert_id;
        $stmt->close();

        // Create order detail
        try {
            $this->insertOrderDetailRow($orderId, $reservation['Product_ID'], 1, $totalAmount, $totalAmount);
        } catch (\Throwable $e) {
            $output = ob_get_clean();
            if (!empty($output)) error_log('Unexpected output: ' . $output);
            echo json_encode(['status' => 'error', 'message' => 'Failed to create order detail: ' . $e->getMessage()]);
            exit;
        }

        $output = ob_get_clean();
        if (!empty($output)) error_log('Unexpected output: ' . $output);
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'Reservation completed and added to revenue',
            'order_id' => $orderId,
            'revenue' => $totalAmount
        ]);
        exit;
    }

    /**
     * Cancel a reservation
     */
    private function cancelReservation(int $reservationId): void
    {
        // Clear any output buffers to prevent JSON corruption
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        error_reporting(0);
        ini_set('display_errors', 0);
        header('Content-Type: application/json');
        
        // Only allow cancellation for reservations that are still Pending
        $stmt = $this->conn->prepare("UPDATE reservation SET Payment_Status = 'Cancelled' WHERE Reservation_ID = ? AND Payment_Status = 'Pending'");
        $stmt->bind_param('i', $reservationId);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            // Return the updated reservation row as confirmation
            $sel = $this->conn->prepare("SELECT Reservation_ID, Payment_Status FROM reservation WHERE Reservation_ID = ? LIMIT 1");
            if ($sel) {
                $sel->bind_param('i', $reservationId);
                $sel->execute();
                $result = $sel->get_result();
                $updated = $result ? $result->fetch_assoc() : null;
                $sel->close();
            } else {
                $updated = null;
            }
            echo json_encode(['status' => 'success', 'message' => 'Reservation cancelled successfully', 'reservation' => $updated]);
        } else {
            // If nothing was affected, return current status for diagnostics
            $stmt->close();
            $sel = $this->conn->prepare("SELECT Reservation_ID, Payment_Status FROM reservation WHERE Reservation_ID = ? LIMIT 1");
            $curr = null;
            if ($sel) {
                $sel->bind_param('i', $reservationId);
                $sel->execute();
                $resQ = $sel->get_result();
                $curr = $resQ ? $resQ->fetch_assoc() : null;
                $sel->close();
            }
            $message = 'Failed to cancel reservation or reservation already processed. Only Pending reservations can be cancelled.';
            if ($curr) {
                $message .= ' (current status: ' . ($curr['Payment_Status'] ?? 'Unknown') . ')';
            }
            echo json_encode(['status' => 'error', 'message' => $message, 'reservation' => $curr]);
        }
        exit;
    }

    /**
     * Get reservation status overview - counts by status
     */
    private function getReservationStatusOverview(): void
    {
        // Clear any output buffers to prevent JSON corruption
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        error_reporting(0);
        ini_set('display_errors', 0);
        header('Content-Type: application/json');

        // Support debug flag via GET/POST (consistent with other endpoints)
        $debug = isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'true');
        // Ensure $row exists even if the query produced no result
        $row = null;

        // Use conditional aggregates and normalization (lower/trim) to ensure consistent counts
        $sql = "SELECT
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'pending' THEN COALESCE(p.Price,0) ELSE 0 END) AS pending_value,
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'confirmed' THEN COALESCE(p.Price,0) ELSE 0 END) AS confirmed_value,
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'completed' THEN COALESCE(p.Price,0) ELSE 0 END) AS completed_value,
                    SUM(CASE WHEN LOWER(TRIM(r.Payment_Status)) = 'cancelled' THEN COALESCE(p.Price,0) ELSE 0 END) AS cancelled_value,
                    COUNT(*) AS total_count,
                    SUM(COALESCE(p.Price,0)) AS total_value
                FROM reservation r
                LEFT JOIN product p ON r.Product_ID = p.Product_ID";

        $result = $this->conn->query($sql);
        $overview = [
            'Pending' => ['count' => 0, 'total_value' => 0],
            'Confirmed' => ['count' => 0, 'total_value' => 0],
            'Completed' => ['count' => 0, 'total_value' => 0],
            'Cancelled' => ['count' => 0, 'total_value' => 0],
            'total_count' => 0,
            'total_value' => 0
        ];

        if ($result) {
            $row = $result->fetch_assoc();
            if ($row) {
                $overview['Pending'] = ['count' => (int)($row['pending_count'] ?? 0), 'total_value' => (float)($row['pending_value'] ?? 0)];
                $overview['Confirmed'] = ['count' => (int)($row['confirmed_count'] ?? 0), 'total_value' => (float)($row['confirmed_value'] ?? 0)];
                $overview['Completed'] = ['count' => (int)($row['completed_count'] ?? 0), 'total_value' => (float)($row['completed_value'] ?? 0)];
                $overview['Cancelled'] = ['count' => (int)($row['cancelled_count'] ?? 0), 'total_value' => (float)($row['cancelled_value'] ?? 0)];
                $overview['total_count'] = (int)($row['total_count'] ?? 0);
                $overview['total_value'] = (float)($row['total_value'] ?? 0);
            }
            $result->free();
        
        }

            if ($debug) {
                echo json_encode(['status' => 'success', 'data' => $overview, 'debug' => $row]);
                exit;
            }

        echo json_encode([
            'status' => 'success',
            'data' => $overview
        ]);
        exit;
    }
}