<?php
declare(strict_types=1);

require_once __DIR__ . '/../Config.php';

class OwnerController
{
    private \mysqli $conn;
    private float $positiveRatingThreshold = 4.0;
    /** @var array<string, bool> */
    private array $columnExistsCache = [];
    private string $finalizedStatusesSql = "'completed','delivered','reserved'";
    private string $localTimezone = 'Asia/Manila';

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

        switch ($action) {
            case 'stats':
                $this->jsonResponse([
                    'status' => 'success',
                    'data' => $this->getDashboardStats(),
                ]);
                break;
            case 'inventory':
                $category = $_GET['category'] ?? $_POST['category'] ?? null;
                $this->jsonResponse([
                    'status' => 'success',
                    'data' => $this->getInventory($category),
                ]);
                break;
            case 'performance':
                $category = $_GET['category'] ?? $_POST['category'] ?? 'all';
                $this->jsonResponse([
                    'status' => 'success',
                    'data' => $this->getProductPerformance($category),
                ]);
                break;
            case 'create-product':
                $this->requirePost();
                $payload = $this->getRequestData();
                $imageData = $this->handleImageUpload();
                $payload['Image'] = $imageData;
                $productData = $this->validateProductInput($payload);
                $newId = $this->insertProduct($productData);
                $product = $this->getProductById($newId);
                if ($product === null) {
                    $this->serverError('Unable to fetch newly created product.');
                }

                $this->jsonResponse([
                    'status' => 'success',
                    'data' => $product,
                ]);
                break;
            case 'update-product':
                try {
                    $this->requirePost();
                    $payload = $this->getRequestData();
                    $productId = isset($payload['Product_ID']) ? (int)$payload['Product_ID'] : 0;
                    if ($productId <= 0) {
                        $this->validationError('Product ID is required.');
                    }

                    $existingProduct = $this->getProductById($productId);
                    if ($existingProduct === null) {
                        $this->notFound('Product not found.');
                    }

                    $imageData = $this->handleImageUpload();
                    if ($imageData !== null) {
                        $payload['Image'] = $imageData;
                    }
                    $productData = $this->validateProductInput($payload);
                    $this->updateProduct($productId, $productData);

                    $this->jsonResponse([
                        'status' => 'success',
                    ]);
                } catch (\Throwable $e) {
                    // log detailed info to storage for debugging (won't expose internal paths to client)
                    $logDir = __DIR__ . '/../storage';
                    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
                    $logFile = $logDir . '/owner_errors.log';
                    $msg = sprintf("[%s] update-product exception: %s in %s:%d\nStack: %s\nRequest: %s\n\n",
                        date('Y-m-d H:i:s'),
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine(),
                        $e->getTraceAsString(),
                        json_encode($_REQUEST)
                    );
                    @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);

                    // return a safe error message to the client for DevTools visibility
                    $this->serverError('Server error while updating product. Check server logs.');
                }
                break;
            case 'delete-product':
                $this->requirePost();
                $payload = $this->getRequestData();
                $productId = isset($payload['Product_ID']) ? (int)$payload['Product_ID'] : 0;
                if ($productId <= 0) {
                    $this->validationError('Product ID is required.');
                }

                if (!$this->deleteProduct($productId)) {
                    $this->notFound('Product not found.');
                }

                $this->jsonResponse([
                    'status' => 'success',
                ]);
                break;
           case 'adjust-stock':
            $this->requirePost();
            $payload = $this->getRequestData();
            $productId = (int)($payload['Product_ID'] ?? 0);
            $change    = (int)($payload['Quantity_Changed'] ?? 0);
            $force     = !empty($payload['forceUpdate']);

            if ($productId <= 0 || $change === 0) {
                $this->validationError('Invalid data.');
            }

            $product = $this->getProductById($productId);
            if (!$product) {
                $this->notFound('Product not found.');
            }

            $newStock = $product['Stock_Quantity'] + $change;

            if ($newStock < 0 && !$force) {
                $this->validationError('Stock cannot go negative. Use “Force” if needed.');
            }
            $this->updateProduct($productId, [
                'Product_Name'     => $product['Product_Name'],
                'Description'      => $product['Description'],
                'Category'         => $product['Category'],
                'Sub_category'     => $product['Sub_category'],
                'Price'            => $product['Price'],
                'Stock_Quantity'   => max(0, $newStock),   
                'Low_Stock_Alert'  => $this->computeAlert(max(0, $newStock)),
            ]);

            $this->jsonResponse([
                'status' => 'success',
                'data'   => $this->getProductById($productId)
            ]);
            break;

        case 'inventory-log':
            $productId = (int)($_GET['product_id'] ?? 0);
            $limit     = (int)($_GET['limit'] ?? 50);
            $this->jsonResponse([
                'status' => 'success',
                'data'   => $this->getInventoryLog($productId, $limit)
            ]);
            break;

        case 'debug-errors':
            // Only allow owners to read this endpoint
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $role = strtolower((string)($_SESSION['user_role'] ?? ''));
            if ($role !== 'owner') {
                $this->jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 200;
            $logFile = __DIR__ . '/../storage/owner_errors.log';
            if (!is_file($logFile)) {
                $this->jsonResponse(['status' => 'success', 'data' => '(no log file)']);
            }

            $content = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($content === false) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Unable to read log file'], 500);
            }

            $start = max(0, count($content) - $lines);
            $tail = implode("\n", array_slice($content, $start));
            $this->jsonResponse(['status' => 'success', 'data' => $tail]);
            break;

        case 'get_system_history':
            $type   = $_GET['type'] ?? '';
            $date   = $_GET['date'] ?? '';
            $search = $_GET['search'] ?? '';
            $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
            $this->jsonResponse([
                'status' => 'success',
                'data'   => $this->getSystemHistory($type, $date, $search, $limit)
            ]);
            break;

        case 'create-announcement':
            $this->requirePost();
            $payload   = $this->getRequestData();
            $message   = $payload['message'] ?? '';
            $audience  = $payload['audience'] ?? 'customer';
            $expiresAt = $payload['expires_at'] ?? null;
            $userId    = (int)($_SESSION['user_id'] ?? 0);
            $result    = $this->createAnnouncement($message, $audience, $expiresAt, $userId);
            $status    = $result['status'] ?? 'error';
            $code      = $status === 'success' ? 200 : (int)($result['code'] ?? 400);
            $this->jsonResponse($result, $code);
            break;

        case 'list-announcements':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $includeInactive = isset($_GET['include_inactive']) ? filter_var($_GET['include_inactive'], FILTER_VALIDATE_BOOLEAN) : false;
            $this->jsonResponse([
                'status' => 'success',
                'data'   => $this->getAnnouncements($limit, $includeInactive)
            ]);
            break;

        case 'delete-announcement':
            $this->requirePost();
            $payload = $this->getRequestData();
            $announcementId = isset($payload['announcement_id']) ? (int)$payload['announcement_id'] : 0;
            if ($announcementId <= 0) {
                $this->jsonResponse([
                    'status'  => 'error',
                    'message' => 'Invalid announcement.'], 422);
            }
            $deleted = $this->archiveAnnouncement($announcementId);
            $this->jsonResponse([
                'status' => $deleted ? 'success' : 'error',
                'message' => $deleted ? 'Announcement archived.' : 'Unable to archive announcement.'
            ], $deleted ? 200 : 404);
            break;

        case 'revenue-chart-weekly':
            $this->jsonResponse([
                'status' => 'success',
                'data' => $this->getWeeklyRevenueChart(),
            ]);
            break;

        case 'revenue-chart-monthly':
            $this->jsonResponse([
                'status' => 'success',
                'data' => $this->getMonthlyRevenueChart(),
            ]);
            break;

        case 'revenue-chart-yearly':
            $this->jsonResponse([
                'status' => 'success',
                'data' => $this->getYearlyRevenueChart(),
            ]);
            break;

        case 'funding-projections':
            $this->jsonResponse([
                'status' => 'success',
                'data' => $this->getFundingProjections(),
            ]);
            break;

        case 'generate_dashboard_report':
            $this->generateDashboardReportPdf();
            break;

        case 'ai-order-analytics':
            $this->jsonResponse([
                'status' => 'success',
                'data' => $this->getAiOrderAnalytics(),
            ]);
            break;

        case 'historical-revenue':
            $this->jsonResponse([
                'status' => 'success',
                'data' => $this->getHistoricalRevenue(),
            ]);
            break;
        case 'get_staff_list':
            $this->jsonResponse([
                'status' => 'success',
                'data' => $this->getStaffList(),
            ]);
            break;
        case 'update_staff':
            $this->requirePost();
            $this->updateStaff();
            break;
        case 'delete_staff':
            $this->requirePost();
            $this->deleteStaff();
            break;

        default:
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'Unsupported action.',
            ], 400);
    }
}

    public function getDashboardStats(): array
    {
        $todaySummary = $this->getTodayOrderSummary();
        $weeklySummary = $this->getWeeklyOrderSummary();
        $monthlySummary = $this->getMonthlyOrderSummary();

        return [
            'total_customers' => $this->countCustomers(),
            'total_orders' => $this->countOrders(),
            'total_delivered' => $this->countDeliveredOrders(),
            'total_revenue' => $this->calculateRevenue(),
            'total_reservations' => $this->countReservations(),
            'pending_reservations' => $this->countReservationsByStatus('Pending'),
            'confirmed_reservations' => $this->countReservationsByStatus('Confirmed'),
            'cancelled_reservations' => $this->countReservationsByStatus('Cancelled'),
            'orders_today' => $todaySummary['orders'],
            'revenue_today' => $todaySummary['revenue'],
            'orders_weekly' => $weeklySummary['orders'],
            'revenue_weekly' => $weeklySummary['revenue'],
            'orders_monthly' => $monthlySummary['orders'],
            'revenue_monthly' => $monthlySummary['revenue'],
        ];
    }

   public function getInventory(?string $category = null): array
    {
        $sql = "SELECT 
                    Product_ID, 
                    Product_Name, 
                    Category, 
                    Price, 
                    IFNULL(Description, '') AS Description,
                    IFNULL(Sub_category, '') AS Sub_category,
                    Stock_Quantity,
                    Image
                FROM product";

        $params = [];
        $types  = '';

        if ($category !== null && $category !== '') {
            $sql .= " WHERE Category = ?";
            $params[] = $category;
            $types   .= 's';
        }

        $sql .= " ORDER BY Product_ID ASC";

    $stmt = $this->conn->prepare($sql);
    if (!$stmt) return [];

    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $data   = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return array_map([$this, 'normalizeProductRow'], $data);
}

    public function getProductPerformance(string $category = 'all'): array
    {
        if (!$this->tableExists('order_detail') || !$this->tableExists('product')) {
            return [];
        }

        $salesSubquery = 'SELECT od.Product_ID, SUM(od.Quantity) AS total_quantity, ' . $this->getOrderDetailRevenueExpression() . ' AS total_revenue '
            . 'FROM order_detail od '
            . 'GROUP BY od.Product_ID';

        $hasFeedback = $this->tableExists('customer_feedback');

        if ($hasFeedback) {
            $feedbackSubquery = 'SELECT Product_ID, '
                . 'AVG(Rating) AS avg_rating, '
                . 'COUNT(*) AS total_reviews, '
                . 'SUM(CASE WHEN Rating >= 4 THEN 1 ELSE 0 END) AS positive_reviews '
                . 'FROM customer_feedback '
                . 'GROUP BY Product_ID';

            $sql = 'SELECT p.Product_ID, p.Product_Name, p.Category, p.Price, p.Image, '
                . 's.total_quantity AS sales, '
                . 's.total_revenue AS revenue, '
                . 'f.avg_rating, f.total_reviews, f.positive_reviews '
                . 'FROM (' . $salesSubquery . ') s '
                . 'JOIN product p ON p.Product_ID = s.Product_ID '
                . 'LEFT JOIN (' . $feedbackSubquery . ') f ON f.Product_ID = p.Product_ID '
                . 'ORDER BY s.total_quantity DESC';
        } else {
            $sql = 'SELECT p.Product_ID, p.Product_Name, p.Category, p.Price, p.Image, '
                . 's.total_quantity AS sales, '
                . 's.total_revenue AS revenue '
                . 'FROM (' . $salesSubquery . ') s '
                . 'JOIN product p ON p.Product_ID = s.Product_ID '
                . 'ORDER BY s.total_quantity DESC';
        }

        $result = $this->conn->query($sql);
        if ($result === false) {
            return [];
        }

        $performance = [];
        while ($row = $result->fetch_assoc()) {
            $cat = $row['Category'] ?? 'Uncategorized';
            if ($category !== 'all' && strcasecmp($cat, $category) !== 0) {
                continue;
            }

            $sales = (int)($row['sales'] ?? 0);
            $revenue = (float)($row['revenue'] ?? 0.0);
            $avgRating = $hasFeedback ? (float)($row['avg_rating'] ?? 0.0) : 0.0;
            $totalReviews = $hasFeedback ? (int)($row['total_reviews'] ?? 0) : 0;
            $positiveReviews = $hasFeedback ? (int)($row['positive_reviews'] ?? 0) : 0;

            if ($hasFeedback) {
                if ($positiveReviews <= 0) {
                    continue;
                }

                if ($avgRating < $this->positiveRatingThreshold) {
                    continue;
                }
            }

            $performance[] = [
                'id' => (int)$row['Product_ID'],
                'name' => $row['Product_Name'],
                'cat' => $cat,
                'price' => (float)($row['Price'] ?? 0),
                'sales' => $sales,
                'revenue' => $revenue,
                'rating' => $hasFeedback ? round(min(max($avgRating, 0.0), 5.0), 2) : null,
                'reviews' => $totalReviews,
                'positive_reviews' => $positiveReviews,
                'image' => $this->encodeProductImage($row['Image'] ?? null),
            ];
        }

        $result->free();

        return $performance;
    }

    private function countCustomers(): int
    {
        if (!$this->tableExists('users')) {
            return 0;
        }

        $result = $this->conn->query("SELECT COUNT(*) AS total FROM users WHERE user_role = 'customer'");
        if ($result === false) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return isset($row['total']) ? (int)$row['total'] : 0;
    }

    private function countOrders(): int
    {
        if (!$this->tableExists('orders')) {
            return 0;
        }

        $result = $this->conn->query('SELECT COUNT(*) AS total FROM orders');
        if ($result === false) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return isset($row['total']) ? (int)$row['total'] : 0;
    }

    private function countDeliveredOrders(): int
    {
        if (!$this->tableExists('orders')) {
            return 0;
        }

        // Check for Status column (which holds 'Completed' for delivered orders)
        if ($this->columnExists('orders', 'Status')) {
            $sql = sprintf('SELECT COUNT(*) AS total FROM orders WHERE %s', $this->statusCondition('Status'));
            $result = $this->conn->query($sql);
            if ($result === false) {
                return 0;
            }

            $row = $result->fetch_assoc();
            $result->free();

            return isset($row['total']) ? (int)$row['total'] : 0;
        }

        // Fallback to checking other possible column names
        foreach (['status', 'order_status', 'delivery_status'] as $column) {
            if ($this->columnExists('orders', $column)) {
                $sql = sprintf('SELECT COUNT(*) AS total FROM orders WHERE %s', $this->statusCondition($column));
                $result = $this->conn->query($sql);
                if ($result === false) {
                    return 0;
                }

                $row = $result->fetch_assoc();
                $result->free();

                return isset($row['total']) ? (int)$row['total'] : 0;
            }
        }

        return 0;
    }

    private function calculateRevenue(): float
    {
        if (!$this->tableExists('orders')) {
            return 0.0;
        }

        // Only sum revenue from completed orders
        if ($this->columnExists('orders', 'Status')) {
            $sql = sprintf('SELECT SUM(Total_Amount) AS revenue FROM orders WHERE %s', $this->statusCondition('Status'));
            $result = $this->conn->query($sql);
        } else {
            // Fallback if Status column doesn't exist
            $result = $this->conn->query('SELECT SUM(Total_Amount) AS revenue FROM orders');
        }
        
        if ($result === false) {
            return 0.0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return isset($row['revenue']) ? (float)$row['revenue'] : 0.0;
    }

    private function countReservations(): int
    {
        if (!$this->tableExists('reservation')) {
            return 0;
        }

        $result = $this->conn->query('SELECT COUNT(*) AS total FROM reservation');
        if ($result === false) {
            return 0;
        }

        $row = $result->fetch_assoc();
        $result->free();

        return isset($row['total']) ? (int)$row['total'] : 0;
    }

    private function countReservationsByStatus(string $status): int
    {
        if (!$this->tableExists('reservation')) {
            return 0;
        }

        $possibleColumns = ['status', 'Status', 'reservation_status', 'Reservation_Status'];
        foreach ($possibleColumns as $column) {
            if ($this->columnExists('reservation', $column)) {
                $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM reservation WHERE LOWER({$column}) = LOWER(?)");
                $stmt->bind_param('s', $status);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                return isset($row['total']) ? (int)$row['total'] : 0;
            }
        }

        // Fallback: if no status column exists, return 0
        return 0;
    }

    /**
     * Get order summary and revenue for today
     */
    public function getTodayOrderSummary(): array
    {
        if (!$this->tableExists('orders')) {
            return ['orders' => 0, 'revenue' => 0.0];
        }

        $statusClause = $this->statusCondition('Status');
        $localOrderDate = $this->localDateExpression('Order_Date');
        $localToday = $this->localTodayExpression();
        $sql = "SELECT 
                    COUNT(*) as orders,
                    COALESCE(SUM(Total_Amount), 0) as revenue
                FROM orders 
                WHERE {$localOrderDate} = {$localToday}
                AND {$statusClause}";
        
        $result = $this->conn->query($sql);
        if ($result === false) {
            return ['orders' => 0, 'revenue' => 0.0];
        }

        $row = $result->fetch_assoc();
        $result->free();

        return [
            'orders' => (int)($row['orders'] ?? 0),
            'revenue' => (float)($row['revenue'] ?? 0.0)
        ];
    }

    /**
     * Get order summary and revenue for current week
     */
    public function getWeeklyOrderSummary(): array
    {
        if (!$this->tableExists('orders')) {
            return ['orders' => 0, 'revenue' => 0.0];
        }

        $statusClause = $this->statusCondition('Status');
        $localOrderDateTime = $this->localDateTimeExpression('Order_Date');
        $localToday = $this->localTodayExpression();
        $sql = "SELECT 
                    COUNT(*) as orders,
                    COALESCE(SUM(Total_Amount), 0) as revenue
                FROM orders 
                WHERE YEARWEEK({$localOrderDateTime}, 1) = YEARWEEK({$localToday}, 1)
                AND {$statusClause}";
        
        $result = $this->conn->query($sql);
        if ($result === false) {
            return ['orders' => 0, 'revenue' => 0.0];
        }

        $row = $result->fetch_assoc();
        $result->free();

        return [
            'orders' => (int)($row['orders'] ?? 0),
            'revenue' => (float)($row['revenue'] ?? 0.0)
        ];
    }

    /**
     * Get order summary and revenue for current month
     */
    public function getMonthlyOrderSummary(): array
    {
        if (!$this->tableExists('orders')) {
            return ['orders' => 0, 'revenue' => 0.0];
        }

        $statusClause = $this->statusCondition('Status');
        $localOrderDateTime = $this->localDateTimeExpression('Order_Date');
        $localToday = $this->localTodayExpression();
        $sql = "SELECT 
                    COUNT(*) as orders,
                    COALESCE(SUM(Total_Amount), 0) as revenue
                FROM orders 
                WHERE YEAR({$localOrderDateTime}) = YEAR({$localToday}) 
                AND MONTH({$localOrderDateTime}) = MONTH({$localToday})
                AND {$statusClause}";
        
        $result = $this->conn->query($sql);
        if ($result === false) {
            return ['orders' => 0, 'revenue' => 0.0];
        }

        $row = $result->fetch_assoc();
        $result->free();

        return [
            'orders' => (int)($row['orders'] ?? 0),
            'revenue' => (float)($row['revenue'] ?? 0.0)
        ];
    }

    /**
     * Get weekly revenue chart data (last 7 days)
     */
    public function getWeeklyRevenueChart(): array
    {
        if (!$this->tableExists('orders')) {
            return [];
        }

        $statusClause = $this->statusCondition('Status');
        $localOrderDate = $this->localDateExpression('Order_Date');
        $localOrderDateTime = $this->localDateTimeExpression('Order_Date');
        $localNow = $this->localNowExpression();
        $sql = "SELECT 
                    {$localOrderDate} as date,
                    DAYNAME({$localOrderDateTime}) as day_name,
                    COUNT(*) as orders,
                    COALESCE(SUM(Total_Amount), 0) as revenue
                FROM orders 
                WHERE {$localOrderDateTime} >= DATE_SUB({$localNow}, INTERVAL 7 DAY)
                AND {$statusClause}
                GROUP BY {$localOrderDate}, DAYNAME({$localOrderDateTime})
                ORDER BY date ASC";
        
        $result = $this->conn->query($sql);
        if ($result === false) {
            return [];
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'date' => $row['date'],
                'day' => $row['day_name'],
                'orders' => (int)$row['orders'],
                'revenue' => (float)$row['revenue']
            ];
        }
        $result->free();

        return $data;
    }

    /**
     * Get monthly revenue chart data (last 30 days)
     */
    public function getMonthlyRevenueChart(): array
    {
        if (!$this->tableExists('orders')) {
            return [];
        }

        $statusClause = $this->statusCondition('Status');
        $localOrderDate = $this->localDateExpression('Order_Date');
        $localOrderDateTime = $this->localDateTimeExpression('Order_Date');
        $localNow = $this->localNowExpression();
        $sql = "SELECT 
                    {$localOrderDate} as date,
                    COUNT(*) as orders,
                    COALESCE(SUM(Total_Amount), 0) as revenue
                FROM orders 
                WHERE {$localOrderDateTime} >= DATE_SUB({$localNow}, INTERVAL 30 DAY)
                AND {$statusClause}
                GROUP BY {$localOrderDate}
                ORDER BY date ASC";
        
        $result = $this->conn->query($sql);
        if ($result === false) {
            return [];
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'date' => $row['date'],
                'orders' => (int)$row['orders'],
                'revenue' => (float)$row['revenue']
            ];
        }
        $result->free();

        return $data;
    }

    /**
     * Get yearly revenue chart (last 12 months aggregated by month)
     */
    public function getYearlyRevenueChart(): array
    {
        if (!$this->tableExists('orders')) {
            return [];
        }

        $statusClause = $this->statusCondition('Status');
        $localOrderDateTime = $this->localDateTimeExpression('Order_Date');
        $localNow = $this->localNowExpression();
        $sql = "SELECT 
                    DATE_FORMAT({$localOrderDateTime}, '%Y-%m') AS month,
                    MIN(DATE_FORMAT({$localOrderDateTime}, '%b')) AS month_name,
                    COUNT(*) AS orders,
                    COALESCE(SUM(Total_Amount), 0) AS revenue
                FROM orders
                WHERE {$localOrderDateTime} >= DATE_SUB({$localNow}, INTERVAL 12 MONTH)
                AND {$statusClause}
                GROUP BY DATE_FORMAT({$localOrderDateTime}, '%Y-%m')
                ORDER BY month ASC";

        try {
            $result = $this->conn->query($sql);
        } catch (\mysqli_sql_exception $e) {
            error_log('getYearlyRevenueChart query failed: ' . $e->getMessage());
            return [];
        }
        if ($result === false) {
            return [];
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'month' => (string)$row['month'],
                'month_name' => (string)$row['month_name'],
                'orders' => (int)$row['orders'],
                'revenue' => (float)$row['revenue'],
            ];
        }
        $result->free();

        return $data;
    }

    /**
     * Get historical revenue data for projections (last 6 months)
     */
    public function getHistoricalRevenue(): array
    {
        if (!$this->tableExists('orders')) {
            return [];
        }

        $statusClause = $this->statusCondition('Status');
        $localOrderDateTime = $this->localDateTimeExpression('Order_Date');
        $localNow = $this->localNowExpression();
        $sql = "SELECT 
                    DATE_FORMAT({$localOrderDateTime}, '%Y-%m') as month,
                    DATE_FORMAT({$localOrderDateTime}, '%M %Y') as month_name,
                    COUNT(*) as orders,
                    COALESCE(SUM(Total_Amount), 0) as revenue,
                    COALESCE(AVG(Total_Amount), 0) as avg_order_value
                FROM orders 
                WHERE {$statusClause}
                AND {$localOrderDateTime} >= DATE_SUB({$localNow}, INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT({$localOrderDateTime}, '%Y-%m'), DATE_FORMAT({$localOrderDateTime}, '%M %Y')
                ORDER BY month ASC";
        
        $result = $this->conn->query($sql);
        if ($result === false) {
            return [];
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'month' => $row['month'],
                'month_name' => $row['month_name'],
                'orders' => (int)$row['orders'],
                'revenue' => (float)$row['revenue'],
                'avg_order_value' => (float)$row['avg_order_value']
            ];
        }
        $result->free();

        return $data;
    }

    /**
     * Get staff list for owner management
     */
    public function getStaffList(): array
    {
        // Only owner allowed
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $r = strtolower((string)($_SESSION['user_role'] ?? ''));
        if ($r !== 'owner' && $r !== 'admin') {
            return [];
        }
        if (!$this->tableExists('users')) return [];

        $sql = "SELECT User_ID, Username, Name, Email, Phonenumber, User_Role, Date_Created FROM users WHERE User_Role = 'Staff' ORDER BY Date_Created DESC";
        $result = $this->conn->query($sql);
        if ($result === false) return [];

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'User_ID' => (int)($row['User_ID'] ?? 0),
                'Username' => (string)($row['Username'] ?? ''),
                'Name' => (string)($row['Name'] ?? ''),
                'Email' => (string)($row['Email'] ?? ''),
                'Phonenumber' => (string)($row['Phonenumber'] ?? ''),
                'User_Role' => (string)($row['User_Role'] ?? ''),
                // Convert server timestamp (UTC) to Manila/PHT for display
                'Date_Created' => (function($raw) {
                    if (!$raw) return '';
                    try {
                        $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                        return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M d, Y g:i A');
                    } catch (\Throwable $e) {
                        return (string)$raw;
                    }
                })($row['Date_Created'] ?? null),
            ];
        }
        $result->free();
        return $rows;
    }

    public function updateStaff(): void
    {
        // Only owner allowed
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $r = strtolower((string)($_SESSION['user_role'] ?? ''));
        if ($r !== 'owner' && $r !== 'admin') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 403);
            return;
        }
        header('Content-Type: application/json');
        $payload = $this->getRequestData();
        $id = isset($payload['User_ID']) ? (int)$payload['User_ID'] : 0;
        $name = trim($payload['Name'] ?? '');
        $username = trim($payload['Username'] ?? '');
        $email = trim($payload['Email'] ?? '');
        $phone = trim($payload['Phonenumber'] ?? '');

        if ($id <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid user ID'], 422);
            return;
        }
        if ($name === '' || $username === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Name and username are required'], 422);
            return;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid email address'], 422);
            return;
        }
        if ($phone !== '' && !preg_match('/^\d{11}$/', $phone)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid phone number (must be 11 digits)'], 422);
            return;
        }

        // Check username uniqueness
        $stmt = $this->conn->prepare('SELECT User_ID FROM users WHERE Username = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $otherId = (int)$row['User_ID'];
                if ($otherId !== $id) {
                    $stmt->close();
                    $this->jsonResponse(['status' => 'error', 'message' => 'Username already in use'], 409);
                    return;
                }
            }
            $stmt->close();
        }

        // Check email uniqueness
        if ($email !== '') {
            $stmt = $this->conn->prepare('SELECT User_ID FROM users WHERE Email = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $otherId = (int)$row['User_ID'];
                    if ($otherId !== $id) {
                        $stmt->close();
                        $this->jsonResponse(['status' => 'error', 'message' => 'Email already in use'], 409);
                        return;
                    }
                }
                $stmt->close();
            }
        }

        $stmt = $this->conn->prepare('UPDATE users SET Name = ?, Username = ?, Email = ?, Phonenumber = ? WHERE User_ID = ? AND User_Role = "Staff"');
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error'], 500);
            return;
        }
        $stmt->bind_param('ssssi', $name, $username, $email, $phone, $id);
        if ($stmt->execute()) {
            $stmt->close();
            $this->jsonResponse(['status' => 'success', 'message' => 'Staff updated successfully']);
            return;
        }
        $err = $stmt->error;
        $stmt->close();
        $this->jsonResponse(['status' => 'error', 'message' => 'Failed to update staff: ' . $err], 500);
    }

    public function deleteStaff(): void
    {
        // Only owner allowed
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $r = strtolower((string)($_SESSION['user_role'] ?? ''));
        if ($r !== 'owner' && $r !== 'admin') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 403);
            return;
        }
        header('Content-Type: application/json');
        $payload = $this->getRequestData();
        $id = isset($payload['User_ID']) ? (int)$payload['User_ID'] : 0;
        if ($id <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid user ID'], 422);
            return;
        }

        // Prevent owner from deleting themselves
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($sessionUserId === $id) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unable to delete your own account'], 403);
            return;
        }

        $stmt = $this->conn->prepare('DELETE FROM users WHERE User_ID = ? AND User_Role = "Staff"');
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error'], 500);
            return;
        }
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) {
                $this->jsonResponse(['status' => 'success', 'message' => 'Staff removed']);
            } else {
                $this->jsonResponse(['status' => 'error', 'message' => 'Staff not found or unable to delete'], 404);
            }
            return;
        }
        $err = $stmt->error;
        $stmt->close();
        $this->jsonResponse(['status' => 'error', 'message' => 'Failed to delete staff: ' . $err], 500);
    }

    /**
     * Calculate funding projections based on historical data
     */
    public function getFundingProjections(): array
    {
        $historical = $this->getHistoricalRevenue();
        
        if (empty($historical)) {
            return [
                'current_month_revenue' => 0,
                'projected_next_month' => 0,
                'projected_3_months' => 0,
                'projected_6_months' => 0,
                'growth_rate' => 0,
                'confidence' => 'low',
                'historical_data' => []
            ];
        }

        // Calculate growth rate from historical data
        $revenues = array_column($historical, 'revenue');
        $totalRevenue = array_sum($revenues);
        $avgRevenue = count($revenues) > 0 ? $totalRevenue / count($revenues) : 0;
        
        // Calculate growth rate (comparing first half vs second half)
        $growthRate = 0;
        
        // Need at least 2 months to calculate meaningful growth
        if (count($revenues) >= 2) {
            $halfPoint = (int)ceil(count($revenues) / 2);
            $firstHalf = array_slice($revenues, 0, $halfPoint);
            $secondHalf = array_slice($revenues, $halfPoint);
            
            $avgFirstHalf = count($firstHalf) > 0 ? array_sum($firstHalf) / count($firstHalf) : 0;
            $avgSecondHalf = count($secondHalf) > 0 ? array_sum($secondHalf) / count($secondHalf) : 0;
            
            if ($avgFirstHalf > 0) {
                $growthRate = (($avgSecondHalf - $avgFirstHalf) / $avgFirstHalf) * 100;
            } elseif ($avgSecondHalf > 0) {
                // If first half is 0 but second half has revenue, show positive growth
                $growthRate = 100;
            }
        }

        // Project future revenue (conservative estimate)
        $currentRevenue = end($revenues) ?: $avgRevenue;
        $monthlyGrowthMultiplier = 1 + ($growthRate / 100);
        
        $projectedNextMonth = $currentRevenue * $monthlyGrowthMultiplier;
        $projected3Months = $currentRevenue * pow($monthlyGrowthMultiplier, 3);
        $projected6Months = $currentRevenue * pow($monthlyGrowthMultiplier, 6);

        // Determine confidence level based on data consistency
        $confidence = 'low';
        if (count($revenues) >= 6) {
            $stdDev = $this->calculateStandardDeviation($revenues);
            $coefficientOfVariation = $avgRevenue > 0 ? ($stdDev / $avgRevenue) : 1;
            
            if ($coefficientOfVariation < 0.2) {
                $confidence = 'high';
            } elseif ($coefficientOfVariation < 0.4) {
                $confidence = 'medium';
            }
        } elseif (count($revenues) >= 3) {
            $confidence = 'medium';
        }

        return [
            'current_month_revenue' => $currentRevenue,
            'projected_next_month' => $projectedNextMonth,
            'projected_3_months' => $projected3Months,
            'projected_6_months' => $projected6Months,
            'average_monthly_revenue' => $avgRevenue,
            'growth_rate' => $growthRate,
            'confidence' => $confidence,
            'data_points' => count($revenues),
            'historical_data' => $historical
        ];
    }

    public function getAiOrderAnalytics(): array
    {
        $windowDays = 90;

        if (!$this->tableExists('orders')) {
            return $this->buildEmptyAiPayload($windowDays, 'Orders table not found. Local AI analytics require recent order data.');
        }

        $hasOrderDetail = $this->tableExists('order_detail');

        $currentBounds = $this->computeWindowBounds($windowDays, 0);
        $previousBounds = $this->computeWindowBounds($windowDays, $windowDays);
        $statusFilter = $this->ordersStatusFilter('o');

        $currentSummary = $this->summarizeOrderWindow($currentBounds, $hasOrderDetail, $statusFilter);
        $previousSummary = $this->summarizeOrderWindow($previousBounds, $hasOrderDetail, $statusFilter);

        $currentOrders = $currentSummary['orders'];
        $currentRevenue = $currentSummary['revenue'];
        $currentItems = $currentSummary['items'];

        $previousOrders = $previousSummary['orders'];
        $previousRevenue = $previousSummary['revenue'];
        $previousItems = $previousSummary['items'];

        $avgOrderValue = $currentOrders > 0 ? $currentRevenue / $currentOrders : 0.0;
        $avgItems = ($hasOrderDetail && $currentOrders > 0) ? $currentItems / $currentOrders : 0.0;

        $growthOrders = round($this->computeGrowthPercent((float)$currentOrders, (float)$previousOrders), 1);
        $growthRevenue = round($this->computeGrowthPercent($currentRevenue, $previousRevenue), 1);
        $itemsGrowth = $hasOrderDetail ? round($this->computeGrowthPercent((float)$currentItems, (float)$previousItems), 1) : null;

        $trend = $this->buildWeeklyTrend($currentBounds, $statusFilter);
        $segments = $this->buildAiSegments($currentBounds, $statusFilter, $currentOrders);

        $leaders = [];
        $laggers = [];
        $categoryBreakdown = [];

        if ($hasOrderDetail) {
            $momentum = $this->buildProductMomentum($currentBounds, $previousBounds, $statusFilter);
            $leaders = $momentum['leaders'];
            $laggers = $momentum['laggers'];
            $categoryBreakdown = $momentum['category_breakdown'];
        }

        $segments['category_breakdown'] = $categoryBreakdown;

        $summary = [
            'window_days' => $windowDays,
            'start_date' => $currentBounds['start'],
            'end_date' => $currentBounds['end'],
            'start_date_display' => $currentBounds['start_dt']->format('M d, Y'),
            'end_date_display' => $currentBounds['end_dt']->format('M d, Y'),
            'order_count' => $currentOrders,
            'revenue' => $currentRevenue,
            'avg_order_value' => $avgOrderValue,
            'avg_items_per_order' => $avgItems,
            'total_items' => $currentItems,
            'previous_items' => $previousItems,
            'previous_order_count' => $previousOrders,
            'previous_revenue' => $previousRevenue,
            'growth_orders_pct' => $growthOrders,
            'growth_revenue_pct' => $growthRevenue,
            'growth_items_pct' => $itemsGrowth,
            'has_item_data' => $hasOrderDetail,
        ];

        $insights = $this->generateAiInsights($summary, $trend, $segments, $leaders, $laggers);

        return [
            'summary' => $summary,
            'trend' => $trend,
            'segments' => $segments,
            'leaders' => $leaders,
            'laggers' => $laggers,
            'insights' => $insights,
            'generated_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ];
    }

    private function buildEmptyAiPayload(int $windowDays, string $message): array
    {
        $now = new \DateTimeImmutable('now');

        return [
            'summary' => [
                'window_days' => $windowDays,
                'start_date' => null,
                'end_date' => null,
                'start_date_display' => null,
                'end_date_display' => null,
                'order_count' => 0,
                'revenue' => 0.0,
                'avg_order_value' => 0.0,
                'avg_items_per_order' => 0.0,
                'total_items' => 0,
                'previous_items' => 0,
                'previous_order_count' => 0,
                'previous_revenue' => 0.0,
                'growth_orders_pct' => 0.0,
                'growth_revenue_pct' => 0.0,
                'growth_items_pct' => 0.0,
                'has_item_data' => false,
            ],
            'trend' => [],
            'segments' => [
                'best_day' => null,
                'best_hour' => null,
                'payment_mix' => [],
                'category_breakdown' => [],
            ],
            'leaders' => [],
            'laggers' => [],
            'insights' => [$message],
            'generated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{start:string,end:string,start_dt:\DateTimeImmutable,end_dt:\DateTimeImmutable}
     */
    private function computeWindowBounds(int $windowDays, int $offsetDays = 0): array
    {
        $windowDays = max(1, $windowDays);
        $offsetDays = max(0, $offsetDays);

        $endDate = new \DateTimeImmutable('today 23:59:59');
        if ($offsetDays > 0) {
            $endDate = $endDate->sub(new \DateInterval('P' . $offsetDays . 'D'));
        }

        $startDate = $endDate->sub(new \DateInterval('P' . ($windowDays - 1) . 'D'))->setTime(0, 0, 0);

        return [
            'start' => $startDate->format('Y-m-d H:i:s'),
            'end' => $endDate->format('Y-m-d H:i:s'),
            'start_dt' => $startDate,
            'end_dt' => $endDate,
        ];
    }

    private function summarizeOrderWindow(array $bounds, bool $hasOrderDetail, string $statusFilter): array
    {
        $orders = 0;
        $revenue = 0.0;
        $items = 0;

        $localOrderDateTime = $this->localDateTimeExpression('o.Order_Date');
        $windowFilter = sprintf('%s BETWEEN ? AND ? %s', $localOrderDateTime, $statusFilter);

        if ($hasOrderDetail) {
            $sql = 'SELECT COUNT(*) AS orders,
                           COALESCE(SUM(order_total), 0) AS revenue,
                           COALESCE(SUM(total_items), 0) AS items
                    FROM (
                        SELECT o.OrderID,
                               COALESCE(o.Total_Amount, 0) AS order_total,
                               COALESCE(SUM(od.Quantity), 0) AS total_items
                        FROM orders o
                        LEFT JOIN order_detail od ON od.Order_ID = o.OrderID
                        WHERE ' . $windowFilter . '
                        GROUP BY o.OrderID
                    ) AS per_order';
        } else {
            $sql = 'SELECT COUNT(*) AS orders,
                           COALESCE(SUM(o.Total_Amount), 0) AS revenue
                    FROM orders o
                    WHERE ' . $windowFilter;
        }

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $bounds['start'], $bounds['end']);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc();
                    if ($row) {
                        $orders = (int)($row['orders'] ?? 0);
                        $revenue = (float)($row['revenue'] ?? 0.0);
                        if ($hasOrderDetail) {
                            $items = (int)($row['items'] ?? 0);
                        }
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }

        if (!$hasOrderDetail) {
            $items = 0;
        }

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'items' => $items,
        ];
    }

    private function buildWeeklyTrend(array $bounds, string $statusFilter): array
    {
        $trend = [];

        $localOrderDateTime = $this->localDateTimeExpression('o.Order_Date');
        $sql = 'SELECT YEARWEEK(' . $localOrderDateTime . ', 3) AS week_key,
                   MIN(' . $localOrderDateTime . ') AS week_start,
                   MAX(' . $localOrderDateTime . ') AS week_end,
                   COUNT(DISTINCT o.OrderID) AS orders,
                   COALESCE(SUM(o.Total_Amount), 0) AS revenue
            FROM orders o
            WHERE ' . $localOrderDateTime . ' BETWEEN ? AND ? ' . $statusFilter . '
            GROUP BY week_key
            ORDER BY week_start ASC';

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $bounds['start'], $bounds['end']);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $start = isset($row['week_start']) ? date_create($row['week_start']) : null;
                        $end = isset($row['week_end']) ? date_create($row['week_end']) : null;
                        $label = $start && $end
                            ? $start->format('M d') . ' – ' . $end->format('M d')
                            : ('Week ' . ($row['week_key'] ?? '?'));

                        $trend[] = [
                            'label' => $label,
                            'start_date' => isset($row['week_start']) ? (string)$row['week_start'] : null,
                            'end_date' => isset($row['week_end']) ? (string)$row['week_end'] : null,
                            'orders' => (int)($row['orders'] ?? 0),
                            'revenue' => (float)($row['revenue'] ?? 0.0),
                        ];
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }

        return $trend;
    }

    private function buildAiSegments(array $bounds, string $statusFilter, int $totalOrders): array
    {
        $bestDay = null;
        $bestHour = null;
        $paymentMix = [];

         $localOrderDateTime = $this->localDateTimeExpression('o.Order_Date');

         $daySql = 'SELECT DAYNAME(' . $localOrderDateTime . ') AS label,
                     COUNT(DISTINCT o.OrderID) AS orders,
                     COALESCE(SUM(o.Total_Amount), 0) AS revenue
                 FROM orders o
                 WHERE ' . $localOrderDateTime . ' BETWEEN ? AND ? ' . $statusFilter . '
                 GROUP BY label
                 ORDER BY orders DESC, revenue DESC
                 LIMIT 1';

        $stmt = $this->conn->prepare($daySql);
        if ($stmt) {
            $stmt->bind_param('ss', $bounds['start'], $bounds['end']);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc();
                    if ($row) {
                        $bestDay = [
                            'label' => (string)($row['label'] ?? 'Unknown'),
                            'orders' => (int)($row['orders'] ?? 0),
                            'revenue' => (float)($row['revenue'] ?? 0.0),
                        ];
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }

         $hourSql = 'SELECT HOUR(' . $localOrderDateTime . ') AS hour_block,
                      COUNT(DISTINCT o.OrderID) AS orders
                  FROM orders o
                  WHERE ' . $localOrderDateTime . ' BETWEEN ? AND ? ' . $statusFilter . '
                  GROUP BY hour_block
                  ORDER BY orders DESC
                  LIMIT 1';

        $stmt = $this->conn->prepare($hourSql);
        if ($stmt) {
            $stmt->bind_param('ss', $bounds['start'], $bounds['end']);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    $row = $result->fetch_assoc();
                    if ($row) {
                        $bestHour = [
                            'hour' => (int)($row['hour_block'] ?? 0),
                            'orders' => (int)($row['orders'] ?? 0),
                        ];
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }

         $paymentSql = 'SELECT COALESCE(NULLIF(o.Mode_Payment, \'\'), \'Unspecified\') AS method,
                      COUNT(DISTINCT o.OrderID) AS orders,
                      COALESCE(SUM(o.Total_Amount), 0) AS revenue
                  FROM orders o
                  WHERE ' . $localOrderDateTime . ' BETWEEN ? AND ? ' . $statusFilter . '
                  GROUP BY method
                  ORDER BY orders DESC';

        $stmt = $this->conn->prepare($paymentSql);
        if ($stmt) {
            $stmt->bind_param('ss', $bounds['start'], $bounds['end']);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $orders = (int)($row['orders'] ?? 0);
                        $share = $totalOrders > 0 ? round(($orders / $totalOrders) * 100, 2) : 0.0;
                        $paymentMix[] = [
                            'method' => (string)($row['method'] ?? 'Unspecified'),
                            'orders' => $orders,
                            'revenue' => (float)($row['revenue'] ?? 0.0),
                            'share' => $share,
                        ];
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }

        return [
            'best_day' => $bestDay,
            'best_hour' => $bestHour,
            'payment_mix' => $paymentMix,
        ];
    }

    private function buildProductMomentum(array $currentBounds, array $previousBounds, string $statusFilter): array
    {
        $revenueExpression = $this->getOrderDetailRevenueExpression();

        $currentSnapshot = $this->fetchProductSnapshot($currentBounds, $statusFilter, $revenueExpression);
        $previousSnapshot = $this->fetchProductSnapshot($previousBounds, $statusFilter, $revenueExpression);

        $previousMap = [];
        foreach ($previousSnapshot as $row) {
            $previousMap[$row['product_id']] = $row;
        }

        $products = [];
        $categoryTotals = [];
        $totalQuantity = 0;

        foreach ($currentSnapshot as $row) {
            $prevQty = $previousMap[$row['product_id']]['quantity'] ?? 0;
            $growth = $this->computeGrowthPercent((float)$row['quantity'], (float)$prevQty);
            $growth = round($growth, 1);

            $products[] = [
                'product_id' => $row['product_id'],
                'name' => $row['product_name'],
                'category' => $row['product_category'],
                'quantity' => $row['quantity'],
                'revenue' => $row['revenue'],
                'previous_quantity' => $prevQty,
                'growth_pct' => $growth,
            ];

            $category = $row['product_category'];
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $row['quantity'];
            $totalQuantity += $row['quantity'];
        }

        $leaders = $products;
        usort($leaders, fn($a, $b) => $b['quantity'] <=> $a['quantity']);
        $leaders = array_slice($leaders, 0, 5);

        $laggers = $products;
        usort($laggers, fn($a, $b) => $a['quantity'] <=> $b['quantity']);
        $laggers = array_slice($laggers, 0, 5);

        $categoryBreakdown = [];
        foreach ($categoryTotals as $category => $qty) {
            $share = $totalQuantity > 0 ? round(($qty / $totalQuantity) * 100, 2) : 0.0;
            $categoryBreakdown[] = [
                'category' => $category,
                'quantity' => $qty,
                'share' => $share,
            ];
        }
        usort($categoryBreakdown, fn($a, $b) => $b['quantity'] <=> $a['quantity']);

        return [
            'leaders' => $leaders,
            'laggers' => $laggers,
            'category_breakdown' => $categoryBreakdown,
        ];
    }

    private function fetchProductSnapshot(array $bounds, string $statusFilter, string $revenueExpression): array
    {
        $results = [];

        $productNameExpr = "COALESCE(p.Product_Name, CONCAT('Product #', od.Product_ID))";
        $productCategoryExpr = "COALESCE(NULLIF(p.Category, ''), 'Uncategorized')";

        $localOrderDateTime = $this->localDateTimeExpression('o.Order_Date');

        $sql = 'SELECT od.Product_ID AS product_id, '
            . $productNameExpr . ' AS product_name, '
            . $productCategoryExpr . ' AS product_category, '
            . 'COALESCE(SUM(od.Quantity), 0) AS quantity, '
            . $revenueExpression . ' AS revenue '
            . 'FROM order_detail od '
            . 'JOIN orders o ON o.OrderID = od.Order_ID '
            . 'LEFT JOIN product p ON p.Product_ID = od.Product_ID '
            . 'WHERE ' . $localOrderDateTime . ' BETWEEN ? AND ? ' . $statusFilter . ' '
            . 'GROUP BY od.Product_ID, ' . $productNameExpr . ', ' . $productCategoryExpr . ' '
            . 'HAVING quantity > 0 '
            . 'ORDER BY quantity DESC';

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $bounds['start'], $bounds['end']);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $results[] = [
                            'product_id' => (int)($row['product_id'] ?? 0),
                            'product_name' => (string)($row['product_name'] ?? 'Unknown Product'),
                            'product_category' => (string)($row['product_category'] ?? 'Uncategorized'),
                            'quantity' => (int)($row['quantity'] ?? 0),
                            'revenue' => (float)($row['revenue'] ?? 0.0),
                        ];
                    }
                    $result->free();
                }
            }
            $stmt->close();
        }

        return $results;
    }

    private function computeGrowthPercent(float $current, float $previous): float
    {
        if ($previous <= 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return (($current - $previous) / $previous) * 100.0;
    }

    private function localDateTimeExpression(string $columnExpression): string
    {
        $converted = sprintf(
            "CONVERT_TZ(%s, 'UTC', '%s')",
            $columnExpression,
            $this->localTimezone
        );

        return sprintf('COALESCE(%s, %s)', $converted, $columnExpression);
    }

    private function localDateExpression(string $columnExpression): string
    {
        return sprintf('DATE(%s)', $this->localDateTimeExpression($columnExpression));
    }

    private function localNowExpression(): string
    {
        $converted = sprintf("CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', '%s')", $this->localTimezone);
        return sprintf('COALESCE(%s, UTC_TIMESTAMP())', $converted);
    }

    private function localTodayExpression(): string
    {
        return sprintf('DATE(%s)', $this->localNowExpression());
    }

    private function statusCondition(string $columnExpression): string
    {
        return sprintf('LOWER(TRIM(%s)) IN (%s)', $columnExpression, $this->finalizedStatusesSql);
    }

    private function ordersStatusFilter(string $alias = 'o'): string
    {
        if ($this->columnExists('orders', 'Status')) {
            return 'AND ' . $this->statusCondition("{$alias}.Status");
        }

        return '';
    }

    private function generateAiInsights(array $summary, array $trend, array $segments, array $leaders, array $laggers): array
    {
        $insights = [];

        $windowDays = (int)($summary['window_days'] ?? 0);
        $orderCount = (int)($summary['order_count'] ?? 0);
        $growthRevenue = (float)($summary['growth_revenue_pct'] ?? 0.0);
        $growthOrders = (float)($summary['growth_orders_pct'] ?? 0.0);

        if ($orderCount === 0) {
            $insights[] = 'No orders were recorded in the last ' . $windowDays . ' days. Encourage customers with promos or social updates.';
            return $insights;
        }

        if ($growthRevenue >= 5.0) {
            $insights[] = 'Revenue grew by ' . number_format($growthRevenue, 1) . '% versus the previous period. Keep the current strategy going.';
        } elseif ($growthRevenue <= -5.0) {
            $insights[] = 'Revenue dropped by ' . number_format(abs($growthRevenue), 1) . '%. Review pricing, marketing, or staff allocation.';
        }

        if ($growthOrders >= 5.0) {
            $insights[] = 'Order volume is up ' . number_format($growthOrders, 1) . '%. Prepare additional stock to maintain service levels.';
        } elseif ($growthOrders <= -5.0) {
            $insights[] = 'Orders declined by ' . number_format(abs($growthOrders), 1) . '%. Consider a flash deal or new product highlight.';
        }

        if (!empty($segments['best_day'])) {
            $bestDay = $segments['best_day'];
            $insights[] = sprintf(
                '%s is the busiest day with %d orders (₱%s revenue). Ensure staffing is ready.',
                $bestDay['label'],
                (int)$bestDay['orders'],
                number_format((float)$bestDay['revenue'], 2)
            );
        }

        if (!empty($segments['best_hour'])) {
            $hour = str_pad((string)((int)$segments['best_hour']['hour']), 2, '0', STR_PAD_LEFT);
            $insights[] = sprintf(
                'Peak order time is around %s:00 with %d orders. Time promos before this rush.',
                $hour,
                (int)$segments['best_hour']['orders']
            );
        }

        if (!empty($segments['payment_mix'])) {
            $topPayment = $segments['payment_mix'][0];
            if (($topPayment['share'] ?? 0) >= 70) {
                $insights[] = sprintf(
                    '%s accounts for %.1f%% of payments. Ensure this payment option stays reliable.',
                    $topPayment['method'],
                    (float)$topPayment['share']
                );
            }
        }

        if (!empty($leaders)) {
            $topLeader = $leaders[0];
            $insights[] = sprintf(
                '%s leads with %d units sold (growth %s%%). Consider featuring it in marketing.',
                $topLeader['name'],
                (int)$topLeader['quantity'],
                number_format((float)$topLeader['growth_pct'], 1)
            );
        }

        if (!empty($laggers)) {
            $lagger = $laggers[0];
            $insights[] = sprintf(
                '%s moved only %d units. Bundle or spotlight it to boost demand.',
                $lagger['name'],
                (int)$lagger['quantity']
            );
        }

        if (!empty($segments['category_breakdown'])) {
            $topCategory = $segments['category_breakdown'][0];
            if (($topCategory['share'] ?? 0) >= 40) {
                $insights[] = sprintf(
                    '%s drives %.1f%% of items sold. Monitor stock to avoid outages.',
                    $topCategory['category'],
                    (float)$topCategory['share']
                );
            }
        }

        if (empty($insights)) {
            $insights[] = 'Local AI found a stable pattern with no major swings. Keep monitoring weekly for changes.';
        }

        $insights = array_values(array_unique(array_map('trim', $insights)));

        return array_slice($insights, 0, 6);
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStandardDeviation(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);

        $variance = array_sum($squaredDiffs) / count($values);
        return sqrt($variance);
    }

    /**
     * Get expense projections (if expense tracking is implemented)
     */
    public function getExpenseProjections(): array
    {
        // Placeholder for future expense tracking
        // This would track operational costs, inventory costs, staff salaries, etc.
        return [
            'monthly_expenses' => 0,
            'projected_expenses' => 0,
            'net_profit_projection' => 0
        ];
    }

    private function getProductMap(): array
    {
        if (!$this->tableExists('product')) {
            return [];
        }

        $result = $this->conn->query('SELECT Product_ID, Product_Name, Category, Price FROM product');
        if ($result === false) {
            return [];
        }

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['Product_Name']] = $row;
        }

        $result->free();

        return $map;
    }

    private function requirePost(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
        if ($method !== 'POST') {
            header('Allow: POST');
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'POST method required.',
            ], 405);
        }
    }

    private function getRequestData(): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function handleImageUpload(): ?string
    {
        if (!isset($_FILES['Image']) || $_FILES['Image']['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $file = $_FILES['Image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->validationError('File upload error: ' . $file['error']);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedTypes)) {
            $this->validationError('Invalid image type. Only JPEG, PNG, GIF, WebP allowed.');
        }

        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            $this->validationError('Image too large. Max 2MB.');
        }

        $imageData = file_get_contents($file['tmp_name']);
        if ($imageData === false) {
            $this->validationError('Failed to read image file.');
        }

        return $imageData;
    }
// Auto-compute Low_Stock_Alert based on stock, changes 11-17-25//

   private function computeAlert(int $stock): string
{
    if ($stock >= 20) {
        return 'Safe';
    }
    if ($stock >= 10) {
        return 'Low';
    }
    if ($stock >= 1) {
        return 'Critical';
    }
    return 'Out of Stock';
}

    // Add inside class OwnerController
    private function safe_trim($value): string {
    // convert null/other to string and trim safely
    return trim((string) ($value ?? ''));
}

    private function validateProductInput(array $input): array
    {
        $name = trim((string)($input['Product_Name'] ?? ''));
        if ($name === '') $this->validationError('Product name is required.');

        $category = trim((string)($input['Category'] ?? ''));
        if ($category === '') $this->validationError('Category is required.');

        $price = round((float)($input['Price'] ?? 0), 2);
        if ($price < 0) $this->validationError('Price cannot be negative.');

        $stock = (int)($input['Stock_Quantity'] ?? 0);
        if ($stock < 0) $this->validationError('Stock quantity cannot be negative.');

        $description = trim((string)($input['Description'] ?? '')) ?: null;
        $subCategory = trim((string)($input['Sub_category'] ?? '')) ?: null;
        $image = $input['Image'] ?? null;

        $data = [
            'Product_Name'    => $name,
            'Description'     => $description,
            'Category'        => $category,
            'Sub_category'    => $subCategory,
            'Price'           => $price,
            'Stock_Quantity'  => $stock,
            'Low_Stock_Alert' => $this->computeAlert($stock),
        ];

        if (array_key_exists('Image', $input)) {
            $data['Image'] = $image;
        }

        return $data;
    }

    
    private function insertProduct(array $data): int
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO product 
                (Product_Name, Description, Category, Sub_category, Price, Stock_Quantity, Low_Stock_Alert, Image) 
             VALUES (?,?,?,?,?,?,?,?)'
        );
        
        if (!$stmt) {
            $this->serverError('Prepare failed: ' . $this->conn->error);
        }
        
        $null = null;
        $stmt->bind_param(
            'ssssdisb',
            $data['Product_Name'],
            $data['Description'],
            $data['Category'],
            $data['Sub_category'],
            $data['Price'],
            $data['Stock_Quantity'],
            $data['Low_Stock_Alert'],
            $null
        );

        // Send blob data separately (index 7 is the 8th parameter - Image)
        $stmt->send_long_data(7, $data['Image']);

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            $this->serverError('Insert failed: ' . $error);
        }
        
        $id = $stmt->insert_id;
        $stmt->close();
        return (int)$id;
    }

    private function updateProduct(int $productId, array $data): void
    {
        $name = $data['Product_Name'] ?? '';
        $description = $data['Description'] ?? null;
        $category = $data['Category'] ?? '';
        $subCategory = $data['Sub_category'] ?? null;
        $price = (float)($data['Price'] ?? 0);
        $stock = (int)($data['Stock_Quantity'] ?? 0);
        $lowAlert = $data['Low_Stock_Alert'] ?? 'Safe';

        if (array_key_exists('Image', $data) && $data['Image'] !== null) {
            $image = $data['Image'];
            $stmt = $this->conn->prepare(
                'UPDATE product 
                    SET Product_Name = ?, Description = ?, Category = ?, Sub_category = ?, 
                        Price = ?, Stock_Quantity = ?, Low_Stock_Alert = ?, Image = ? 
                  WHERE Product_ID = ?'
            );
            if (!$stmt) {
                $this->serverError('Prepare failed: ' . $this->conn->error);
            }

            $null = null;
            $stmt->bind_param(
                'ssssdisbi',
                $name,
                $description,
                $category,
                $subCategory,
                $price,
                $stock,
                $lowAlert,
                $null,
                $productId
            );
            
            // Send blob data separately (index 7 is the 8th parameter - Image)
            $stmt->send_long_data(7, $image);
        } else {
            $stmt = $this->conn->prepare(
                'UPDATE product 
                    SET Product_Name = ?, Description = ?, Category = ?, Sub_category = ?, 
                        Price = ?, Stock_Quantity = ?, Low_Stock_Alert = ? 
                  WHERE Product_ID = ?'
            );
            if (!$stmt) {
                $this->serverError('Prepare failed: ' . $this->conn->error);
            }

            $stmt->bind_param(
                'ssssdisi',
                $name,
                $description,
                $category,
                $subCategory,
                $price,
                $stock,
                $lowAlert,
                $productId
            );
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            $this->serverError('Update failed: ' . $error);
        }
        $stmt->close();
    }

    private function deleteProduct(int $productId): bool
    {
        $stmt = $this->conn->prepare('DELETE FROM product WHERE Product_ID = ?');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0;
    }

   private function getProductById(int $productId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT Product_ID, Product_Name, Description, Category, Sub_category, 
                    Price, Stock_Quantity, Image
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

    $row['Product_ID']      = (int)($row['Product_ID'] ?? 0);
    $row['Price']           = (float)($row['Price'] ?? 0);
    $row['Stock_Quantity']  = $stock;
    $row['Product_Name']    = $this->safe_trim($row['Product_Name'] ?? '');
    $row['Category']        = $this->safe_trim($row['Category'] ?? '');

    // use safe_trim then convert empty string to null for optional fields
    $desc = $this->safe_trim($row['Description'] ?? '');
    $row['Description'] = $desc === '' ? null : $desc;

    $sub = $this->safe_trim($row['Sub_category'] ?? '');
    $row['Sub_category'] = $sub === '' ? null : $sub;

    if (!empty($row['Image'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($row['Image']) ?: 'image/jpeg';
        $row['Image'] = 'data:' . $mime . ';base64,' . base64_encode($row['Image']);
    } else {
        $row['Image'] = null;
    }

    // auto detect stock alert
    $row['Low_Stock_Alert'] = $this->computeAlert($stock);

    return $row;
}

    private function validationError(string $msg): void { $this->jsonResponse(['status'=>'error','message'=>$msg],422); }
    private function notFound(string $msg='Resource not found.'): void { $this->jsonResponse(['status'=>'error','message'=>$msg],404); }
    private function serverError(string $msg): void { $this->jsonResponse(['status'=>'error','message'=>$msg],500); }
    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        } catch (\mysqli_sql_exception $e) {
            error_log('tableExists prepare failed for ' . $table . ': ' . $e->getMessage());
            return false;
        }

        if (!$stmt) {
            error_log('tableExists prepare returned false for ' . $table . ': ' . $this->conn->error);
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        try {
            $stmt = $this->conn->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        } catch (\mysqli_sql_exception $e) {
            error_log('columnExists prepare failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        if (!$stmt) {
            error_log('columnExists prepare returned false for ' . $table . '.' . $column . ': ' . $this->conn->error);
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

    private function jsonResponse(array $payload, int $code = 200): void
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($payload);
        exit;
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

    private function getOrderDetailRevenueExpression(): string
    {
        if ($this->columnExists('order_detail', 'Subtotal')) {
            return 'SUM(od.Subtotal)';
        }

        $priceColumn = $this->resolveOrderDetailPriceColumn();
        if ($priceColumn !== null) {
            return 'SUM(od.Quantity * od.' . $priceColumn . ')';
        }

        return 'SUM(od.Quantity)';
    }

    private function encodeProductImage($binaryImage): ?string
    {
        if (empty($binaryImage)) {
            return null;
        }

        $mime = 'image/jpeg';

        if (class_exists('\finfo')) {
            try {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->buffer($binaryImage);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            } catch (\Throwable $e) {
                // fall back to default mime
            }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($binaryImage);
    }

private function getInventoryLog(int $productId = 0, int $limit = 50): array
    {
        $sql = 'SELECT l.*, p.Product_Name, u.Username AS Staff_Name
                FROM inventory_log l
                LEFT JOIN product p ON l.Product_ID = p.Product_ID
                LEFT JOIN users   u ON l.Staff_ID = u.User_ID
                WHERE 1=1';
        $params = []; $types = '';

        if ($productId > 0) { $sql .= ' AND l.Product_ID = ?'; $params[] = $productId; $types .= 'i'; }

        $sql .= ' ORDER BY l.Log_Date DESC, l.Log_ID DESC LIMIT ?';
        $params[] = $limit; $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res  = $stmt->get_result();
        $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return array_map(fn($r) => [
            'Log_ID'          => (int)$r['Log_ID'],
            'Product_ID'      => (int)$r['Product_ID'],
            'Product_Name'    => $r['Product_Name'],
            'Quantity_Changed'=> (int)$r['Quantity_Changed'],
            'Reason'          => $r['Reason'],
            'Log_Date'        => $r['Log_Date'],
            'Staff_ID'        => (int)$r['Staff_ID'],
            'Staff_Name'      => $r['Staff_Name'],
        ], $data);
    }

    private function normalizeAnnouncementRow(array $row): array
    {
        $message = $this->safe_trim($row['Message'] ?? '');
        $audienceRaw = strtolower($this->safe_trim($row['Audience'] ?? 'customer'));
        $audience = in_array($audienceRaw, ['customer', 'customers', 'staff', 'all'], true)
            ? ($audienceRaw === 'customers' ? 'customer' : $audienceRaw)
            : 'customer';

        $createdAtRaw = $row['Created_At'] ?? null;
        $createdFormatted = '';
        if ($createdAtRaw) {
            $created = date_create($createdAtRaw);
            if ($created instanceof \DateTimeInterface) {
                $createdFormatted = $created->format('M d, Y h:i A');
            }
        }

        $expiresRaw = $row['Expires_At'] ?? null;
        $expiresFormatted = '';
        $isExpired = false;
        if ($expiresRaw) {
            $expires = date_create($expiresRaw);
            if ($expires instanceof \DateTimeInterface) {
                $expiresFormatted = $expires->format('M d, Y h:i A');
                $now = new \DateTimeImmutable('now');
                $isExpired = $expires < $now;
            }
        }

        return [
            'id' => (int)($row['Announcement_ID'] ?? 0),
            'message' => $message,
            'audience' => $audience,
            'is_active' => (int)($row['Is_Active'] ?? 0) === 1,
            'created_by' => isset($row['Created_By']) ? (int)$row['Created_By'] : null,
            'created_at' => $createdAtRaw,
            'created_at_formatted' => $createdFormatted,
            'expires_at' => $expiresRaw,
            'expires_at_formatted' => $expiresFormatted,
            'is_expired' => $isExpired,
        ];
    }

    private function createAnnouncement(string $message, string $audience, ?string $expiresAt, int $userId): array
    {
        if (!$this->tableExists('announcements')) {
            return [
                'status' => 'error',
                'message' => 'Announcements table not found. Please run create_announcements_table.php.',
                'code' => 500,
            ];
        }

        $trimmedMessage = $this->safe_trim($message);
        if ($trimmedMessage === '') {
            return [
                'status' => 'error',
                'message' => 'Announcement message is required.',
                'code' => 422,
            ];
        }

        if (strlen($trimmedMessage) > 600) {
            return [
                'status' => 'error',
                'message' => 'Announcement message is too long. Please keep it under 600 characters.',
                'code' => 422,
            ];
        }

        $audienceNormalized = strtolower($this->safe_trim($audience));
        $allowedAudiences = ['customer', 'customers', 'all', 'staff'];
        if (!in_array($audienceNormalized, $allowedAudiences, true)) {
            $audienceNormalized = 'customer';
        }
        if ($audienceNormalized === 'customers') {
            $audienceNormalized = 'customer';
        }

        $expiresNormalized = null;
        if ($expiresAt !== null && $this->safe_trim($expiresAt) !== '') {
            $expiresDate = date_create($expiresAt);
            if (!($expiresDate instanceof \DateTimeInterface)) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid expiration date provided.',
                    'code' => 422,
                ];
            }
            $expiresNormalized = $expiresDate->format('Y-m-d H:i:s');
        }

        $createdBy = $userId > 0 ? $userId : 0;

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO announcements (Message, Audience, Is_Active, Expires_At, Created_By)
                 VALUES (?, ?, 1, ?, NULLIF(?, 0))'
            );
        } catch (\mysqli_sql_exception $e) {
            error_log('createAnnouncement prepare failed: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Database error while preparing announcement insert.',
                'code' => 500,
            ];
        }

        if (!$stmt) {
            return [
                'status' => 'error',
                'message' => 'Failed to prepare announcement insert: ' . $this->conn->error,
                'code' => 500,
            ];
        }

        try {
            $stmt->bind_param('sssi', $trimmedMessage, $audienceNormalized, $expiresNormalized, $createdBy);
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            $error = $e->getMessage();
            $stmt->close();
            error_log('createAnnouncement execute failed: ' . $error);
            return [
                'status' => 'error',
                'message' => 'Failed to save announcement: ' . $error,
                'code' => 500,
            ];
        }

        $newId = (int)$stmt->insert_id;
        $stmt->close();

        $announcement = $this->getAnnouncementById($newId);

        return [
            'status' => 'success',
            'data' => $announcement,
        ];
    }

    public function getAnnouncements(int $limit = 50, bool $includeInactive = false): array
    {
        if (!$this->tableExists('announcements')) {
            return [];
        }

        $limit = $limit > 0 ? min($limit, 200) : 50;

        $sql = 'SELECT Announcement_ID, Message, Audience, Is_Active, Expires_At, Created_By, Created_At
                FROM announcements';

        if (!$includeInactive) {
            $sql .= ' WHERE Is_Active = 1 AND (Expires_At IS NULL OR Expires_At >= NOW())';
        }

        $sql .= ' ORDER BY Created_At DESC LIMIT ?';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return array_map([$this, 'normalizeAnnouncementRow'], $rows);
    }

    private function getAnnouncementById(int $announcementId): ?array
    {
        if ($announcementId <= 0 || !$this->tableExists('announcements')) {
            return null;
        }

        $stmt = $this->conn->prepare(
            'SELECT Announcement_ID, Message, Audience, Is_Active, Expires_At, Created_By, Created_At
               FROM announcements WHERE Announcement_ID = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $announcementId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ? $this->normalizeAnnouncementRow($row) : null;
    }

    private function archiveAnnouncement(int $announcementId): bool
    {
        if ($announcementId <= 0 || !$this->tableExists('announcements')) {
            return false;
        }

        $stmt = $this->conn->prepare('UPDATE announcements SET Is_Active = 0 WHERE Announcement_ID = ?');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $announcementId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0;
    }

    public function getSystemHistory(?string $type = null, ?string $date = null, ?string $search = null, int $limit = 200): array
    {
        $filters = $this->normalizeHistoryFilters($type, $date, $search, $limit);

        $events = [];

        if ($filters['include']['inventory']) {
            $events = array_merge($events, $this->collectInventoryHistory($filters));
        }
        if ($filters['include']['orders']) {
            $events = array_merge($events, $this->collectOrderHistory($filters));
        }
        if ($filters['include']['feedback']) {
            $events = array_merge($events, $this->collectFeedbackHistory($filters));
        }
        if ($filters['include']['reservations']) {
            $events = array_merge($events, $this->collectReservationHistory($filters));
        }

        if (!$events) {
            return [];
        }

        usort($events, static function (array $a, array $b): int {
            return strcmp($b['__sort_key'] ?? '', $a['__sort_key'] ?? '');
        });

        $limited = array_slice($events, 0, $filters['limit']);

        foreach ($limited as &$entry) {
            unset($entry['__sort_key']);
        }
        unset($entry);

        return $limited;
    }

    /**
     * @return array{include: array{inventory: bool, orders: bool, feedback: bool, reservations: bool}, inventory_action: ?string, date: string, search: string, search_like: string, limit: int}
     */
    private function normalizeHistoryFilters(?string $type, ?string $date, ?string $search, int $limit): array
    {
        $include = [
            'inventory' => true,
            'orders' => true,
            'feedback' => true,
            'reservations' => true,
        ];

        $inventoryAction = null;

        $typeValue = strtolower(trim((string)$type));
        if ($typeValue !== '') {
            $map = [
                'inventory' => 'inventory',
                'stock' => 'inventory',
                'orders' => 'orders',
                'order' => 'orders',
                'feedback' => 'feedback',
                'review' => 'feedback',
                'reviews' => 'feedback',
                'reservations' => 'reservations',
                'reservation' => 'reservations',
                'staff' => 'reservations',
                'confirmations' => 'reservations',
                'confirmation' => 'reservations',
            ];

            if (isset($map[$typeValue])) {
                foreach ($include as $key => $value) {
                    $include[$key] = ($key === $map[$typeValue]);
                }
            } elseif (preg_match('/^inventory[-:_](add|update|remove)$/', $typeValue, $matches)) {
                foreach ($include as $key => $value) {
                    $include[$key] = ($key === 'inventory');
                }
                $inventoryAction = ucfirst($matches[1]);
            } elseif (in_array($typeValue, ['add', 'update', 'remove'], true)) {
                foreach ($include as $key => $value) {
                    $include[$key] = ($key === 'inventory');
                }
                $inventoryAction = ucfirst($typeValue);
            }
        }

        $dateNormalized = '';
        if ($date !== null && $date !== '') {
            try {
                $dt = new \DateTimeImmutable($date);
                $dateNormalized = $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                $dateNormalized = '';
            }
        }

        $searchNormalized = trim((string)$search);
        $searchLike = $searchNormalized === '' ? '' : '%' . mb_strtolower($searchNormalized, 'UTF-8') . '%';
        $limitNormalized = $limit > 0 ? min($limit, 500) : 200;

        return [
            'include' => $include,
            'inventory_action' => $inventoryAction,
            'date' => $dateNormalized,
            'search' => $searchNormalized,
            'search_like' => $searchLike,
            'limit' => $limitNormalized,
        ];
    }

    /**
     * @param array{include: array<string, bool>, inventory_action: ?string, date: string, search: string, search_like: string, limit: int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function collectInventoryHistory(array $filters): array
    {
        if (!$this->tableExists('inventory_log')) {
            return [];
        }

        $joinUsers = $this->tableExists('users');

        $sql = 'SELECT l.Log_ID, l.Product_ID, l.User_ID, l.Action_Type, l.Quantity_Changed, l.Log_Date,'
            . ' p.Product_Name, p.Category, p.Stock_Quantity';

        if ($joinUsers) {
            $sql .= ', u.Username, u.Name, u.User_Role';
        }

        $sql .= ' FROM inventory_log l'
            . ' LEFT JOIN product p ON l.Product_ID = p.Product_ID';

        if ($joinUsers) {
            $sql .= ' LEFT JOIN users u ON l.User_ID = u.User_ID';
        }

        $sql .= ' WHERE 1=1';

        $types = '';
        $params = [];

        if ($filters['inventory_action']) {
            $types .= 's';
            $params[] = $filters['inventory_action'];
            $sql .= ' AND l.Action_Type = ?';
        }

        if ($filters['date'] !== '') {
            $types .= 's';
            $params[] = $filters['date'];
            $sql .= ' AND l.Log_Date = ?';
        }

        if ($filters['search'] !== '') {
            $types .= $joinUsers ? 'ssss' : 'ss';
            $searchParam = $filters['search_like'];
            $params[] = $searchParam;
            $params[] = $searchParam;
            $sql .= ' AND (LOWER(p.Product_Name) LIKE ? OR LOWER(p.Category) LIKE ?';
            if ($joinUsers) {
                $params[] = $searchParam;
                $params[] = $searchParam;
                $sql .= ' OR LOWER(u.Username) LIKE ? OR LOWER(u.Name) LIKE ?';
            }
            $sql .= ')';
        }

        $limit = min(max($filters['limit'], 50), 500);
        $types .= 'i';
        $params[] = $limit;

        $sql .= ' ORDER BY l.Log_Date DESC, l.Log_ID DESC LIMIT ?';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $this->bindStatementParams($stmt, $types, $params);

        $history = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $logId = (int)($row['Log_ID'] ?? 0);
                    $productName = $this->safe_trim($row['Product_Name'] ?? 'Unknown Product');
                    $category = $this->safe_trim($row['Category'] ?? '');
                    $action = strtolower($this->safe_trim($row['Action_Type'] ?? 'Update'));
                    $quantity = (int)($row['Quantity_Changed'] ?? 0);
                    $currentStock = isset($row['Stock_Quantity']) ? (int)$row['Stock_Quantity'] : null;

                    $mask = $this->maskIdentity($row['Username'] ?? null, $row['Name'] ?? null);

                    $notesParts = [];
                    $absQuantity = abs($quantity);
                    if ($action === 'add') {
                        if ($quantity > 0) {
                            $notesParts[] = 'Added ' . $absQuantity . ' unit' . ($absQuantity === 1 ? '' : 's');
                        } elseif ($quantity < 0) {
                            $notesParts[] = 'Unexpected decrease of ' . $absQuantity . ' unit' . ($absQuantity === 1 ? '' : 's');
                        } else {
                            $notesParts[] = 'Add action recorded (no stock change)';
                        }
                    } elseif ($action === 'remove') {
                        if ($quantity !== 0) {
                            $notesParts[] = 'Removed ' . $absQuantity . ' unit' . ($absQuantity === 1 ? '' : 's');
                        } else {
                            $notesParts[] = 'Remove action recorded (no stock change)';
                        }
                    } else {
                        if ($quantity > 0) {
                            $notesParts[] = 'Adjusted stock by +' . $absQuantity;
                        } elseif ($quantity < 0) {
                            $notesParts[] = 'Adjusted stock by -' . $absQuantity;
                        } else {
                            $notesParts[] = 'Stock count confirmed';
                        }
                    }

                    if ($currentStock !== null) {
                        $notesParts[] = 'Current stock: ' . $currentStock;
                    }

                    $timestamp = $this->normalizeHistoryTimestamp(
                        $row['Log_Date'] ? ($row['Log_Date'] . ' 00:00:00') : null
                    );

                    $history[] = [
                        'id' => 'inventory-' . $logId,
                        'event_category' => 'Inventory',
                        'action_type' => $action,
                        'action_label' => ucfirst($action),
                        'headline' => ucfirst($action) . ' • ' . $productName,
                        'notes' => implode(' | ', $notesParts),
                        'performed_by' => $mask['masked'],
                        'performed_by_masked' => $mask['masked'],
                        'performed_by_full' => $mask['original'],
                        'user_role' => 'Staff',
                        'product_id' => (int)($row['Product_ID'] ?? 0),
                        'product_name' => $productName,
                        'product_category' => $category,
                        'quantity_changed' => $quantity,
                        'change_label' => ($quantity > 0 ? '+' : '') . (string)$quantity,
                        'current_stock' => $currentStock,
                        'log_date' => $timestamp['raw'],
                        'log_date_formatted' => $timestamp['display'],
                        'event_summary' => ucfirst($action) . ' - ' . $productName,
                        '__sort_key' => $timestamp['sort_key'],
                    ];
                }
                $result->free();
            }
        }

        $stmt->close();

        return $history;
    }

    /**
     * @param array{include: array<string, bool>, inventory_action: ?string, date: string, search: string, search_like: string, limit: int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function collectOrderHistory(array $filters): array
    {
        if (!$this->tableExists('orders')) {
            return [];
        }

        $sql = 'SELECT o.OrderID, o.User_ID, o.Order_Date, o.Mode_Payment, o.Total_Amount, o.Status,'
            . ' COALESCE(u.Username, \'\') AS username, COALESCE(u.Name, \'\') AS name, COALESCE(u.User_Role, \'\') AS user_role,'
            . ' COALESCE(SUM(od.Quantity), 0) AS total_items'
            . ' FROM orders o'
            . ' LEFT JOIN users u ON o.User_ID = u.User_ID'
            . ' LEFT JOIN order_detail od ON od.Order_ID = o.OrderID'
            . ' WHERE 1=1';

        $types = '';
        $params = [];

        if ($filters['date'] !== '') {
            $types .= 's';
            $params[] = $filters['date'];
            $sql .= ' AND DATE(o.Order_Date) = ?';
        }

        if ($filters['search'] !== '') {
            $types .= 'ssss';
            $like = $filters['search_like'];
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $sql .= ' AND ('
                . 'CAST(o.OrderID AS CHAR) LIKE ? OR LOWER(o.Status) LIKE ?'
                . ' OR LOWER(u.Username) LIKE ? OR LOWER(u.Name) LIKE ?'
                . ')';
        }

        $sql .= ' GROUP BY o.OrderID, o.User_ID, o.Order_Date, o.Mode_Payment, o.Total_Amount, o.Status, u.Username, u.Name, u.User_Role';

        $limit = min(max($filters['limit'] * 2, 100), 600);
        $types .= 'i';
        $params[] = $limit;

        $sql .= ' ORDER BY o.Order_Date DESC, o.OrderID DESC LIMIT ?';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $this->bindStatementParams($stmt, $types, $params);

        $history = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $orderId = (int)($row['OrderID'] ?? 0);
                    $status = strtolower($this->safe_trim($row['Status'] ?? 'pending'));
                    $mask = $this->maskIdentity($row['username'] ?? null, $row['name'] ?? null);
                    $itemCount = (int)($row['total_items'] ?? 0);
                    $modePayment = $this->safe_trim($row['Mode_Payment'] ?? '');
                    $amount = (float)($row['Total_Amount'] ?? 0.0);

                    $notesParts = [];
                    if ($itemCount > 0) {
                        $notesParts[] = number_format($itemCount) . ' item' . ($itemCount === 1 ? '' : 's');
                    }
                    $notesParts[] = $this->formatPeso($amount);
                    if ($modePayment !== '') {
                        $notesParts[] = $modePayment;
                    }
                    $notesParts[] = 'Status: ' . ucfirst($status);

                    switch ($status) {
                        case 'pending':
                        case 'reserved':
                            $actionLabel = 'Order Placed';
                            $headline = $mask['masked'] . ' placed order #' . $orderId;
                            break;
                        case 'confirmed':
                            $actionLabel = 'Order Confirmed';
                            $headline = 'Order #' . $orderId . ' confirmed';
                            break;
                        case 'completed':
                            $actionLabel = 'Order Completed';
                            $headline = 'Order #' . $orderId . ' completed';
                            break;
                        case 'cancelled':
                            $actionLabel = 'Order Cancelled';
                            $headline = 'Order #' . $orderId . ' cancelled';
                            break;
                        default:
                            $actionLabel = ucfirst($status);
                            $headline = 'Order #' . $orderId . ' updated';
                            break;
                    }

                    $timestamp = $this->normalizeHistoryTimestamp($row['Order_Date'] ?? null);

                    $history[] = [
                        'id' => 'order-' . $orderId,
                        'event_category' => 'Orders',
                        'action_type' => $status,
                        'action_label' => $actionLabel,
                        'headline' => $headline,
                        'notes' => implode(' | ', $notesParts),
                        'performed_by' => $mask['masked'],
                        'performed_by_masked' => $mask['masked'],
                        'performed_by_full' => $mask['original'],
                        'user_role' => $this->safe_trim($row['user_role'] ?? 'Customer') ?: 'Customer',
                        'order_id' => $orderId,
                        'order_status' => $status,
                        'order_amount' => $amount,
                        'order_items' => $itemCount,
                        'mode_payment' => $modePayment,
                        'log_date' => $timestamp['raw'],
                        'log_date_formatted' => $timestamp['display'],
                        'event_summary' => $headline,
                        '__sort_key' => $timestamp['sort_key'],
                    ];
                }
                $result->free();
            }
        }

        $stmt->close();

        return $history;
    }

    /**
     * @param array{include: array<string, bool>, inventory_action: ?string, date: string, search: string, search_like: string, limit: int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function collectFeedbackHistory(array $filters): array
    {
        if (!$this->tableExists('customer_feedback')) {
            return [];
        }

        $sql = 'SELECT f.Feedback_ID, f.OrderID, f.User_ID, f.Product_ID, f.Rating, f.Comment, f.Date_Submitted,'
            . ' COALESCE(u.Username, \'\') AS username, COALESCE(u.Name, \'\') AS name, COALESCE(u.User_Role, \'\') AS user_role,'
            . ' COALESCE(p.Product_Name, CONCAT(\'Product #\', f.Product_ID)) AS product_name'
            . ' FROM customer_feedback f'
            . ' LEFT JOIN users u ON f.User_ID = u.User_ID'
            . ' LEFT JOIN product p ON f.Product_ID = p.Product_ID'
            . ' WHERE 1=1';

        $types = '';
        $params = [];

        if ($filters['date'] !== '') {
            $types .= 's';
            $params[] = $filters['date'];
            $sql .= ' AND DATE(f.Date_Submitted) = ?';
        }

        if ($filters['search'] !== '') {
            $types .= 'sss';
            $like = $filters['search_like'];
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $sql .= ' AND ('
                . 'LOWER(p.Product_Name) LIKE ? OR LOWER(u.Username) LIKE ? OR LOWER(u.Name) LIKE ?'
                . ')';
        }

        $limit = min(max($filters['limit'], 100), 500);
        $types .= 'i';
        $params[] = $limit;

        $sql .= ' ORDER BY f.Date_Submitted DESC, f.Feedback_ID DESC LIMIT ?';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $this->bindStatementParams($stmt, $types, $params);

        $history = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $feedbackId = (int)($row['Feedback_ID'] ?? 0);
                    $productName = $this->safe_trim($row['product_name'] ?? 'Product');
                    $rating = (int)($row['Rating'] ?? 0);
                    $commentRaw = $this->safe_trim($row['Comment'] ?? '');
                    $mask = $this->maskIdentity($row['username'] ?? null, $row['name'] ?? null);

                    $comment = $commentRaw;
                    if ($comment !== '' && mb_strlen($comment, 'UTF-8') > 160) {
                        $comment = mb_substr($comment, 0, 160, 'UTF-8') . '...';
                    }

                    $notes = $comment !== '' ? '"' . $comment . '"' : 'No comment provided.';

                    $timestamp = $this->normalizeHistoryTimestamp($row['Date_Submitted'] ?? null);

                    $history[] = [
                        'id' => 'feedback-' . $feedbackId,
                        'event_category' => 'Feedback',
                        'action_type' => 'feedback_submitted',
                        'action_label' => 'Feedback Submitted',
                        'headline' => $mask['masked'] . ' rated ' . $productName . ' ' . $rating . ' star' . ($rating === 1 ? '' : 's'),
                        'notes' => $notes,
                        'performed_by' => $mask['masked'],
                        'performed_by_masked' => $mask['masked'],
                        'performed_by_full' => $mask['original'],
                        'user_role' => $this->safe_trim($row['user_role'] ?? 'Customer') ?: 'Customer',
                        'feedback_id' => $feedbackId,
                        'order_id' => (int)($row['OrderID'] ?? 0),
                        'product_id' => (int)($row['Product_ID'] ?? 0),
                        'rating' => $rating,
                        'log_date' => $timestamp['raw'],
                        'log_date_formatted' => $timestamp['display'],
                        'event_summary' => $mask['masked'] . ' left feedback',
                        '__sort_key' => $timestamp['sort_key'],
                    ];
                }
                $result->free();
            }
        }

        $stmt->close();

        return $history;
    }

    /**
     * @param array{include: array<string, bool>, inventory_action: ?string, date: string, search: string, search_like: string, limit: int} $filters
     * @return array<int, array<string, mixed>>
     */
    private function collectReservationHistory(array $filters): array
    {
        if (!$this->tableExists('reservation')) {
            return [];
        }

        $sql = 'SELECT r.Reservation_ID, r.User_ID, r.Product_ID, r.Reservation_Date, r.Payment_Status,'
            . ' COALESCE(u.Username, \'\') AS username, COALESCE(u.Name, \'\') AS name,'
            . ' COALESCE(p.Product_Name, CONCAT(\'Product #\', r.Product_ID)) AS product_name'
            . ' FROM reservation r'
            . ' LEFT JOIN users u ON r.User_ID = u.User_ID'
            . ' LEFT JOIN product p ON r.Product_ID = p.Product_ID'
            . ' WHERE 1=1';

        $types = '';
        $params = [];

        if ($filters['date'] !== '') {
            $types .= 's';
            $params[] = $filters['date'];
            $sql .= ' AND DATE(r.Reservation_Date) = ?';
        }

        if ($filters['search'] !== '') {
            $types .= 'sss';
            $like = $filters['search_like'];
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $sql .= ' AND ('
                . 'LOWER(p.Product_Name) LIKE ? OR LOWER(u.Username) LIKE ? OR LOWER(u.Name) LIKE ?'
                . ')';
        }

        $limit = min(max($filters['limit'], 100), 500);
        $types .= 'i';
        $params[] = $limit;

        $sql .= ' ORDER BY r.Reservation_Date DESC, r.Reservation_ID DESC LIMIT ?';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $this->bindStatementParams($stmt, $types, $params);

        $history = [];
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $reservationId = (int)($row['Reservation_ID'] ?? 0);
                    $status = strtolower($this->safe_trim($row['Payment_Status'] ?? 'pending'));
                    $productName = $this->safe_trim($row['product_name'] ?? 'Product');
                    $mask = $this->maskIdentity($row['username'] ?? null, $row['name'] ?? null);

                    switch ($status) {
                        case 'pending':
                            $actionLabel = 'Reservation Requested';
                            $headline = $mask['masked'] . ' requested a reservation';
                            $performedBy = $mask['masked'];
                            break;
                        case 'confirmed':
                            $actionLabel = 'Reservation Confirmed';
                            $headline = 'Reservation confirmed for ' . $mask['masked'];
                            $performedBy = 'Sta***';
                            break;
                        case 'completed':
                            $actionLabel = 'Reservation Completed';
                            $headline = 'Reservation completed for ' . $mask['masked'];
                            $performedBy = 'Sta***';
                            break;
                        case 'cancelled':
                            $actionLabel = 'Reservation Cancelled';
                            $headline = 'Reservation cancelled for ' . $mask['masked'];
                            $performedBy = 'Sta***';
                            break;
                        default:
                            $actionLabel = ucfirst($status);
                            $headline = 'Reservation updated for ' . $mask['masked'];
                            $performedBy = 'Sta***';
                            break;
                    }

                    $timestamp = $this->normalizeHistoryTimestamp($row['Reservation_Date'] ?? null);

                    $notesParts = [];
                    $notesParts[] = 'Item: ' . $productName;
                    $notesParts[] = 'Status: ' . ucfirst($status);
                    if (!empty($row['Reservation_Date'])) {
                        try {
                            $schedule = new \DateTimeImmutable($row['Reservation_Date']);
                            $schedule = $schedule->setTimezone(new \DateTimeZone($this->localTimezone));
                            $notesParts[] = 'Schedule: ' . $schedule->format('M d, Y • h:i A');
                        } catch (\Throwable $e) {
                            // ignore formatting errors
                        }
                    }

                    $history[] = [
                        'id' => 'reservation-' . $reservationId,
                        'event_category' => 'Reservations',
                        'action_type' => $status,
                        'action_label' => $actionLabel,
                        'headline' => $headline,
                        'notes' => implode(' | ', $notesParts),
                        'performed_by' => $performedBy,
                        'performed_by_masked' => $performedBy,
                        'performed_by_full' => $performedBy === $mask['masked'] ? $mask['original'] : null,
                        'user_role' => $performedBy === $mask['masked'] ? 'Customer' : 'Staff',
                        'reservation_id' => $reservationId,
                        'product_id' => (int)($row['Product_ID'] ?? 0),
                        'log_date' => $timestamp['raw'],
                        'log_date_formatted' => $timestamp['display'],
                        'event_summary' => $headline,
                        '__sort_key' => $timestamp['sort_key'],
                    ];
                }
                $result->free();
            }
        }

        $stmt->close();

        return $history;
    }

    private function maskIdentity(?string $username, ?string $name = null): array
    {
        $candidate = $this->safe_trim($username ?? '');
        if ($candidate === '') {
            $candidate = $this->safe_trim($name ?? '');
        }

        if ($candidate === '') {
            return ['masked' => 'Gue***', 'original' => null];
        }

        $candidate = preg_replace('/\s+/', ' ', $candidate);
        $length = mb_strlen($candidate, 'UTF-8');
        $prefixLength = min(3, max(1, $length));
        $prefix = mb_substr($candidate, 0, $prefixLength, 'UTF-8');

        return [
            'masked' => $prefix . '***',
            'original' => $candidate,
        ];
    }

    private function normalizeHistoryTimestamp(?string $value): array
    {
        if ($value === null || $value === '') {
            return [
                'sort_key' => '1970-01-01T00:00:00+08:00',
                'display' => '--',
                'raw' => null,
            ];
        }

        $original = trim($value);
        $assumeUtc = false;
        $isDateOnly = false;

        if ($original === '') {
            return [
                'sort_key' => '1970-01-01T00:00:00+08:00',
                'display' => '--',
                'raw' => null,
            ];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $original)) {
            $isDateOnly = true;
            $value = $original . ' 00:00:00';
        } else {
            $value = $original;
        }

        if (!preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            $assumeUtc = true;
        }

        try {
            if ($assumeUtc) {
                $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } else {
                $dt = new \DateTimeImmutable($value);
            }
        } catch (\Throwable $e) {
            try {
                $dt = new \DateTimeImmutable($value, new \DateTimeZone($this->localTimezone));
            } catch (\Throwable $e2) {
                return [
                    'sort_key' => '1970-01-01T00:00:00+08:00',
                    'display' => $original,
                    'raw' => $original,
                ];
            }
        }

        $dt = $dt->setTimezone(new \DateTimeZone($this->localTimezone));

        return [
            'sort_key' => $dt->format('Y-m-d\TH:i:sP'),
            'display' => $dt->format($isDateOnly ? 'M d, Y' : 'M d, Y • h:i A') . ' PHT',
            'raw' => $dt->format('Y-m-d H:i:s'),
        ];
    }

    private function formatPeso(float $value): string
    {
        return '₱' . number_format($value, 2);
    }

    private function bindStatementParams(\mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '') {
            return;
        }

        $bind = [$types];
        foreach ($params as $index => $param) {
            $bind[] = &$params[$index];
        }
        unset($param);
        $stmt->bind_param(...$bind);
    }

    public function getOwnerProfile(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        // Use lowercase column names to match the DB schema and other code
        $stmt = $this->conn->prepare('SELECT user_id, username, name, email, phonenumber, user_role FROM users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) return null;

        // Normalize keys to the PascalCase variants the views expect
        return [
            'User_ID' => isset($row['user_id']) ? (int)$row['user_id'] : 0,
            'Username' => $row['username'] ?? '',
            'Name' => $row['name'] ?? '',
            'Email' => $row['email'] ?? '',
            'Phonenumber' => $row['phonenumber'] ?? '',
            'User_Role' => $row['user_role'] ?? '',
        ];
    }

    public function updateOwnerProfile(int $userId, string $username, string $name, ?string $email, ?string $phone): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user.');
        }

        $username = trim($username);
        $name = trim($name);
        $email = trim((string)($email ?? ''));
        $phone = trim((string)($phone ?? ''));

        if ($username === '') {
            throw new \InvalidArgumentException('Username is required.');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('Full name is required.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Please provide a valid email address.');
        }

        $checkUsername = $this->conn->prepare('SELECT User_ID FROM users WHERE Username = ? AND User_ID <> ? LIMIT 1');
        if (!$checkUsername) {
            throw new \RuntimeException('Failed to prepare username check: ' . $this->conn->error);
        }
        $checkUsername->bind_param('si', $username, $userId);
        $checkUsername->execute();
        $checkUsername->store_result();
        if ($checkUsername->num_rows > 0) {
            $checkUsername->close();
            throw new \InvalidArgumentException('Username already taken.');
        }
        $checkUsername->close();

        if ($email !== '') {
            $checkEmail = $this->conn->prepare('SELECT User_ID FROM users WHERE Email = ? AND User_ID <> ? LIMIT 1');
            if (!$checkEmail) {
                throw new \RuntimeException('Failed to prepare email check: ' . $this->conn->error);
            }
            $checkEmail->bind_param('si', $email, $userId);
            $checkEmail->execute();
            $checkEmail->store_result();
            if ($checkEmail->num_rows > 0) {
                $checkEmail->close();
                throw new \InvalidArgumentException('Email already in use.');
            }
            $checkEmail->close();
        }

        // Update using lowercase column names and then return a normalized row
        $stmt = $this->conn->prepare(
            'UPDATE users SET username = ?, name = ?, email = NULLIF(?, ""), phonenumber = NULLIF(?, "") WHERE user_id = ?'
        );
        if (!$stmt) {
            throw new \RuntimeException('Failed to prepare update: ' . $this->conn->error);
        }

        $stmt->bind_param('ssssi', $username, $name, $email, $phone, $userId);
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Failed to update profile: ' . $msg);
        }
        $stmt->close();

        $updatedRow = $this->getOwnerProfile($userId);
        if ($updatedRow === null) {
            throw new \RuntimeException('Failed to load updated profile.');
        }

        return $updatedRow;
    }

    private function generateDashboardReportPdf(): void
    {
        $stats = $this->getDashboardStats();
        $weekly = $this->getWeeklyRevenueChart();
        $monthly = $this->getMonthlyRevenueChart();
        $projections = $this->getFundingProjections();
        $inventory = $this->getInventory();
        $topProducts = array_slice($this->getProductPerformance('all'), 0, 5);
        $announcements = $this->getAnnouncements(5, false);

        $inventoryCount = count($inventory);
        $inventoryLowStock = 0;
        $inventoryTotalUnits = 0;
        foreach ($inventory as $product) {
            $alertLevel = strtoupper((string)($product['Low_Stock_Alert'] ?? ''));
            if (in_array($alertLevel, ['LOW', 'CRITICAL', 'OUT OF STOCK'], true)) {
                $inventoryLowStock++;
            }
            $inventoryTotalUnits += (int)($product['Stock_Quantity'] ?? 0);
        }

        $weeklyRevenueTotal = 0.0;
        $weeklyOrderTotal = 0;
        foreach ($weekly as $row) {
            $weeklyRevenueTotal += (float)($row['revenue'] ?? 0.0);
            $weeklyOrderTotal += (int)($row['orders'] ?? 0);
        }

        $monthlyRevenueTotal = 0.0;
        $monthlyOrderTotal = 0;
        foreach ($monthly as $row) {
            $monthlyRevenueTotal += (float)($row['revenue'] ?? 0.0);
            $monthlyOrderTotal += (int)($row['orders'] ?? 0);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));

        $lines = [];
        $lines[] = "Guillermo's Cafe Dashboard Report";
        $lines[] = 'Generated: ' . $now->format('M d, Y h:i A T');
        $lines[] = '';

        $lines[] = 'Summary Metrics';
        $lines[] = '- Total customers: ' . number_format((int)($stats['total_customers'] ?? 0));
        $lines[] = '- Total orders: ' . number_format((int)($stats['total_orders'] ?? 0));
        $lines[] = '- Delivered orders: ' . number_format((int)($stats['total_delivered'] ?? 0));
        $lines[] = '- Lifetime revenue: ' . $this->formatCurrency((float)($stats['total_revenue'] ?? 0.0));
        $lines[] = '';

        $lines[] = 'Recent Performance';
        $lines[] = '- Orders today: ' . number_format((int)($stats['orders_today'] ?? 0)) . ' | Revenue: ' . $this->formatCurrency((float)($stats['revenue_today'] ?? 0.0));
        $lines[] = '- Orders this week: ' . number_format((int)($stats['orders_weekly'] ?? 0)) . ' | Revenue: ' . $this->formatCurrency((float)($stats['revenue_weekly'] ?? 0.0));
        $lines[] = '- Orders this month: ' . number_format((int)($stats['orders_monthly'] ?? 0)) . ' | Revenue: ' . $this->formatCurrency((float)($stats['revenue_monthly'] ?? 0.0));
        $lines[] = '- Revenue last 7 days: ' . $this->formatCurrency($weeklyRevenueTotal) . ' across ' . number_format($weeklyOrderTotal) . ' orders';
        $lines[] = '- Revenue this month to date: ' . $this->formatCurrency($monthlyRevenueTotal) . ' across ' . number_format($monthlyOrderTotal) . ' orders';
        $lines[] = '';

        $lines[] = 'Funding Outlook';
        $lines[] = '- Current month revenue: ' . $this->formatCurrency((float)($projections['current_month_revenue'] ?? 0.0));
        $lines[] = '- Projected next month: ' . $this->formatCurrency((float)($projections['projected_next_month'] ?? 0.0));
        $lines[] = '- Projected 3 months: ' . $this->formatCurrency((float)($projections['projected_3_months'] ?? 0.0));
        $lines[] = '- Projected 6 months: ' . $this->formatCurrency((float)($projections['projected_6_months'] ?? 0.0));
        $lines[] = '- Growth rate: ' . number_format((float)($projections['growth_rate'] ?? 0.0), 2) . '%';
        $lines[] = '- Confidence: ' . ucfirst((string)($projections['confidence'] ?? 'low'));
        $lines[] = '';

        $lines[] = 'Inventory Overview';
        $lines[] = '- Products tracked: ' . number_format($inventoryCount);
        $lines[] = '- Total units in stock: ' . number_format($inventoryTotalUnits);
        $lines[] = '- Items flagged low stock: ' . number_format($inventoryLowStock);
        $lines[] = '';

        if (!empty($topProducts)) {
            $lines[] = 'Top Products (by sales)';
            foreach ($topProducts as $index => $product) {
                $rank = $index + 1;
                $productName = $this->sanitizeReportLine((string)($product['name'] ?? 'Product'));
                $sales = number_format((int)($product['sales'] ?? 0));
                $revenue = $this->formatCurrency((float)($product['revenue'] ?? 0.0));
                $lines[] = "- #$rank $productName | Units Sold: $sales | Revenue: $revenue";
            }
            $lines[] = '';
        }

        if (!empty($weekly)) {
            $lines[] = 'Daily Revenue (last 7 days)';
            foreach ($weekly as $row) {
                $dayLabel = $this->sanitizeReportLine((string)($row['day'] ?? $row['date'] ?? 'Day'));
                $lines[] = '- ' . $dayLabel . ': ' . $this->formatCurrency((float)($row['revenue'] ?? 0.0)) . ' (' . number_format((int)($row['orders'] ?? 0)) . ' orders)';
            }
            $lines[] = '';
        }

        if (!empty($announcements)) {
            $lines[] = 'Active Announcements';
            foreach ($announcements as $announcement) {
                $message = $this->sanitizeReportLine((string)($announcement['message'] ?? ''));
                if ($message === '') {
                    continue;
                }
                $posted = $this->sanitizeReportLine((string)($announcement['created_at_formatted'] ?? '')); 
                $line = '- ' . $message;
                if ($posted !== '') {
                    $line .= ' (Posted ' . $posted . ')';
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }

        $lines[] = 'End of report.';

        $pdf = $this->buildSimplePdf($lines);
        $fileName = 'dashboard_report_' . $now->format('Ymd_His') . '.pdf';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $pdf;
        exit;
    }

    private function formatCurrency(float $value): string
    {
        return 'PHP ' . number_format($value, 2);
    }

    private function sanitizeReportLine(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function buildSimplePdf(array $lines): string
    {
        $marginLeft = 72.0;
        $marginTop = 760.0;
        $lineHeight = 14.0;
        $bottomMargin = 72.0;
        $pageHeight = 792.0;
        $maxLinesPerPage = (int)floor(($marginTop - $bottomMargin) / $lineHeight);
        if ($maxLinesPerPage <= 0) {
            $maxLinesPerPage = 40;
        }

        $pagesContent = [];
        $currentLines = [];
        $lineCounter = 0;

        foreach ($lines as $line) {
            $line = (string)$line;
            if ($line === '') {
                $currentLines[] = '';
                $lineCounter++;
            } else {
                $wrapped = $this->wrapPdfLine($line);
                foreach ($wrapped as $wrappedLine) {
                    $currentLines[] = $wrappedLine;
                    $lineCounter++;
                }
            }

            if ($lineCounter >= $maxLinesPerPage) {
                $pagesContent[] = $currentLines;
                $currentLines = [];
                $lineCounter = 0;
            }
        }

        if (!empty($currentLines)) {
            $pagesContent[] = $currentLines;
        }

        if (empty($pagesContent)) {
            $pagesContent[] = ['(no data)'];
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $nextId = 4;
        $pageRefs = [];

        foreach ($pagesContent as $pageLines) {
            $content = "BT\n/F1 12 Tf\n";
            $y = $marginTop;
            foreach ($pageLines as $lineText) {
                if ($lineText === '') {
                    $y -= $lineHeight;
                    continue;
                }
                $escaped = $this->escapePdfText($lineText);
                $content .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $marginLeft, $y, $escaped);
                $y -= $lineHeight;
            }
            $content .= "ET";

            $stream = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $contentId = $nextId++;
            $objects[$contentId] = $stream;

            $pageId = $nextId++;
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]'
                . ' /Resources << /Font << /F1 3 0 R >> >>'
                . ' /Contents ' . $contentId . ' 0 R >>';

            $pageRefs[] = $pageId;
        }

        $kids = array_map(static fn(int $id): string => $id . ' 0 R', $pageRefs);
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageRefs) . ' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $content) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $content . "\nendobj\n";
        }

        $xrefPosition = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $offset = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPosition . "\n%%EOF";

        return $pdf;
    }

    private function wrapPdfLine(string $line, int $limit = 94): array
    {
        $line = $this->sanitizeReportLine($line);
        if ($line === '') {
            return [''];
        }

        $words = preg_split('/\s+/', $line);
        $current = '';
        $result = [];

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (strlen($candidate) > $limit) {
                if ($current === '') {
                    $result[] = $word;
                    $current = '';
                } else {
                    $result[] = $current;
                    $current = $word;
                }
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        if (empty($result)) {
            $result[] = '';
        }

        return $result;
    }

    private function escapePdfText(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $text);
    }
}
