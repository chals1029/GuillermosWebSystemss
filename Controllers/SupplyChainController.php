<?php
declare(strict_types=1);

require_once __DIR__ . '/../Config.php';
require_once __DIR__ . '/SupplyChainService.php';

class SupplyChainController
{
    private \mysqli $conn;
    private SupplyChainService $service;

    /** @var list<string> */
    private array $staffAllowedActions = [
        'supply-summary',
        'supply-purchase-orders',
        'supply-purchase-order-detail',
        'supply-update-po-status',
        'supply-stock-alerts',
        // Ingredients inventory: staff can browse and do quick stock counts.
        'supply-items',
        'supply-adjust-item-stock',
        // History views — read-only, useful for staff context.
        'supply-item-history',
        'supply-order-ingredients',
    ];

    /** @var list<string> Public, token-authenticated actions — no session required. */
    private array $publicActions = [
        'supply-supplier-view-po',
        'supply-supplier-confirm-po',
        'supply-supplier-reply-issue',
    ];

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
        $this->service = new SupplyChainService($this->conn);
    }

    public function handleAjax(): void
    {
        $role = $this->requireAuth();

        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if ($action === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Action required.'], 400);
        }

        if ($role === 'staff' && !in_array($action, $this->staffAllowedActions, true)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Staff cannot perform this supply chain action.'], 403);
        }

        if (!$this->tablesReady()) {
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'Supply chain tables are not installed. Import sql/supply_chain_migration.sql in phpMyAdmin.',
            ], 503);
        }

        switch ($action) {
            case 'supply-stock-alerts':
                $this->jsonResponse(['status' => 'success', 'data' => $this->service->getStockAlerts()]);
                break;
            case 'supply-summary':
                $this->jsonResponse(['status' => 'success', 'data' => $this->getSummary()]);
                break;
            case 'supply-suppliers':
                $this->jsonResponse(['status' => 'success', 'data' => $this->listSuppliers()]);
                break;
            case 'supply-create-supplier':
                $this->requirePost();
                $this->jsonResponse(['status' => 'success', 'data' => $this->createSupplier($this->getRequestData())]);
                break;
            case 'supply-update-supplier':
                $this->requirePost();
                $this->updateSupplier($this->getRequestData());
                $this->jsonResponse(['status' => 'success']);
                break;
            case 'supply-delete-supplier':
                $this->requirePost();
                $this->deleteSupplier($this->getRequestData());
                $this->jsonResponse(['status' => 'success']);
                break;
            case 'supply-items':
                $this->jsonResponse(['status' => 'success', 'data' => $this->listItems()]);
                break;
            case 'supply-create-item':
                $this->requirePost();
                $this->jsonResponse(['status' => 'success', 'data' => $this->createItem($this->getRequestData())]);
                break;
            case 'supply-update-item':
                $this->requirePost();
                $this->updateItem($this->getRequestData());
                $this->jsonResponse(['status' => 'success']);
                break;
            case 'supply-delete-item':
                $this->requirePost();
                $this->deleteItem($this->getRequestData());
                $this->jsonResponse(['status' => 'success']);
                break;
            case 'supply-adjust-item-stock':
                $this->requirePost();
                $this->jsonResponse(['status' => 'success', 'data' => $this->adjustItemStock($this->getRequestData())]);
                break;
            case 'supply-purchase-orders':
                $this->jsonResponse(['status' => 'success', 'data' => $this->listPurchaseOrders()]);
                break;
            case 'supply-purchase-order-detail':
                $poId = (int)($_GET['po_id'] ?? 0);
                if ($poId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'PO ID required.'], 400);
                }
                $detail = $this->getPurchaseOrderDetail($poId);
                if ($detail === null) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Purchase order not found.'], 404);
                }
                $this->jsonResponse(['status' => 'success', 'data' => $detail]);
                break;
            case 'supply-create-purchase-order':
                $this->requirePost();
                $this->jsonResponse(['status' => 'success', 'data' => $this->createPurchaseOrder($this->getRequestData())]);
                break;
            case 'supply-update-po-status':
                $this->requirePost();
                $this->jsonResponse(['status' => 'success', 'data' => $this->updatePurchaseOrderStatus($this->getRequestData())]);
                break;
            case 'supply-po-issues':
                $poId = (int)($_GET['po_id'] ?? 0);
                if ($poId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'PO ID required.'], 400);
                }
                $this->jsonResponse(['status' => 'success', 'data' => $this->listPoIssues($poId)]);
                break;
            case 'supply-create-po-issue':
                $this->requirePost();
                if (strtolower((string)($_SESSION['user_role'] ?? '')) !== 'owner') {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Only owners can file material issues.'], 403);
                }
                $this->jsonResponse($this->createPoIssue($this->getRequestData()));
                break;
            case 'supply-update-po-issue':
                $this->requirePost();
                if (strtolower((string)($_SESSION['user_role'] ?? '')) !== 'owner') {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Only owners can update issues.'], 403);
                }
                $this->jsonResponse($this->updatePoIssue($this->getRequestData()));
                break;
            case 'supply-po-link':
                $poId = (int)($_GET['po_id'] ?? $_POST['po_id'] ?? 0);
                if ($poId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'PO ID required.'], 400);
                }
                $this->jsonResponse(['status' => 'success', 'data' => $this->getOrIssuePoLink($poId)]);
                break;
            case 'supply-email-po-link':
                $this->requirePost();
                $poId = (int)($_POST['po_id'] ?? 0);
                if ($poId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'PO ID required.'], 400);
                }
                $this->jsonResponse($this->emailSupplierPoLink($poId));
                break;
            case 'supply-recipe':
                $productId = (int)($_GET['product_id'] ?? 0);
                if ($productId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Product ID required.'], 400);
                }
                $this->jsonResponse(['status' => 'success', 'data' => $this->service->getRecipeForProduct($productId)]);
                break;
            case 'supply-products-recipes':
                $this->jsonResponse(['status' => 'success', 'data' => $this->service->listProductsForRecipes()]);
                break;
            case 'supply-save-recipe':
                $this->requirePost();
                $this->saveRecipe($this->getRequestData());
                $this->jsonResponse(['status' => 'success']);
                break;
            case 'supply-item-history':
                $itemId = (int)($_GET['item_id'] ?? 0);
                $limit  = (int)($_GET['limit'] ?? 50);
                if ($itemId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Item ID required.'], 400);
                }
                $this->jsonResponse(['status' => 'success', 'data' => $this->getItemHistory($itemId, $limit)]);
                break;
            case 'supply-order-ingredients':
                $orderId = (int)($_GET['order_id'] ?? 0);
                if ($orderId <= 0) {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Order ID required.'], 400);
                }
                $this->jsonResponse(['status' => 'success', 'data' => $this->getOrderIngredients($orderId)]);
                break;
            default:
                $this->jsonResponse(['status' => 'error', 'message' => 'Unknown supply chain action.'], 400);
        }
    }

    private function tablesReady(): bool
    {
        return SupplyChainService::baseTablesReady($this->conn);
    }

    private function requireAuth(): string
    {
        $role = strtolower((string)($_SESSION['user_role'] ?? ''));
        if (!in_array($role, ['owner', 'staff'], true)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }
        return $role;
    }

    /** @param array<string, mixed> $data */
    private function saveRecipe(array $data): void
    {
        $productId = (int)($data['Product_ID'] ?? 0);
        $linesRaw = $data['lines'] ?? '[]';
        $lines = is_string($linesRaw) ? json_decode($linesRaw, true) : $linesRaw;
        if (!is_array($lines)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid recipe lines.'], 400);
        }

        try {
            $this->service->saveRecipe($productId, $lines);
        } catch (\Throwable $e) {
            $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    private function requirePost(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonResponse(['status' => 'error', 'message' => 'POST method required.'], 405);
        }
    }

    /** @return array<string, mixed> */
    private function getRequestData(): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw ?: '', true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
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

    /** @return array<string, int|float> */
    private function getSummary(): array
    {
        return [
            'active_suppliers' => $this->scalarInt("SELECT COUNT(*) AS c FROM supplier WHERE Status = 'Active'"),
            'supply_items' => $this->scalarInt('SELECT COUNT(*) AS c FROM supply_item WHERE Status = \'Active\''),
            'low_stock_items' => $this->scalarInt(
                'SELECT COUNT(*) AS c FROM supply_item WHERE Status = \'Active\' AND Stock_Quantity <= Reorder_Level'
            ),
            'open_purchase_orders' => $this->scalarInt(
                "SELECT COUNT(*) AS c FROM purchase_order WHERE Status IN ('Draft','Ordered','Partial')"
            ),
            'pending_po_value' => $this->scalarFloat(
                "SELECT COALESCE(SUM(Total_Amount),0) AS v FROM purchase_order WHERE Status IN ('Draft','Ordered','Partial')"
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function listSuppliers(): array
    {
        $sql = "SELECT Supplier_ID, Supplier_Name, Contact_Person, Email, Phone, Address, Status, Notes, Created_At
                FROM supplier ORDER BY Supplier_Name ASC";
        return $this->fetchAll($sql);
    }

    /** @param array<string, mixed> $data */
    private function createSupplier(array $data): array
    {
        $name = trim((string)($data['Supplier_Name'] ?? ''));
        if ($name === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Supplier name is required.'], 400);
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO supplier (Supplier_Name, Contact_Person, Email, Phone, Address, Status, Notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $contact = trim((string)($data['Contact_Person'] ?? ''));
        $email = trim((string)($data['Email'] ?? ''));
        $phone = trim((string)($data['Phone'] ?? ''));
        $address = trim((string)($data['Address'] ?? ''));
        $status = $this->normalizeSupplierStatus($data['Status'] ?? 'Active');
        $notes = trim((string)($data['Notes'] ?? ''));

        $stmt->bind_param('sssssss', $name, $contact, $email, $phone, $address, $status, $notes);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $this->getSupplierById($id) ?? [];
    }

    /** @param array<string, mixed> $data */
    private function updateSupplier(array $data): void
    {
        $id = (int)($data['Supplier_ID'] ?? 0);
        if ($id <= 0 || $this->getSupplierById($id) === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Supplier not found.'], 404);
        }

        $name = trim((string)($data['Supplier_Name'] ?? ''));
        if ($name === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Supplier name is required.'], 400);
        }

        $stmt = $this->conn->prepare(
            'UPDATE supplier SET Supplier_Name = ?, Contact_Person = ?, Email = ?, Phone = ?, Address = ?, Status = ?, Notes = ?
             WHERE Supplier_ID = ?'
        );
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $contact = trim((string)($data['Contact_Person'] ?? ''));
        $email = trim((string)($data['Email'] ?? ''));
        $phone = trim((string)($data['Phone'] ?? ''));
        $address = trim((string)($data['Address'] ?? ''));
        $status = $this->normalizeSupplierStatus($data['Status'] ?? 'Active');
        $notes = trim((string)($data['Notes'] ?? ''));

        $stmt->bind_param('sssssssi', $name, $contact, $email, $phone, $address, $status, $notes, $id);
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string, mixed> $data */
    private function deleteSupplier(array $data): void
    {
        $id = (int)($data['Supplier_ID'] ?? 0);
        if ($id <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Supplier ID required.'], 400);
        }

        // Check for any purchase orders (open or historic/closed) to avoid MySQL foreign key crashes
        $hasPo = $this->scalarInt(
            "SELECT COUNT(*) AS c FROM purchase_order WHERE Supplier_ID = ?",
            'i',
            [$id]
        );
        if ($hasPo > 0) {
            $this->jsonResponse([
                'status' => 'error',
                'message' => 'Cannot delete supplier with historic purchase orders. Please set their status to Inactive instead to preserve transaction history.'
            ], 400);
        }

        $stmt = $this->conn->prepare('DELETE FROM supplier WHERE Supplier_ID = ?');
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /** @return list<array<string, mixed>> */
    private function listItems(): array
    {
        $sql = "SELECT si.Item_ID, si.Item_Name, si.Category, si.Unit, si.Stock_Quantity, si.Reorder_Level,
                       si.Unit_Cost, si.Supplier_ID, si.Notes, si.Status, si.Created_At,
                       s.Supplier_Name,
                       CASE WHEN si.Stock_Quantity <= si.Reorder_Level THEN 'Low' ELSE 'OK' END AS Stock_Alert
                FROM supply_item si
                LEFT JOIN supplier s ON s.Supplier_ID = si.Supplier_ID
                ORDER BY si.Item_Name ASC";
        return $this->fetchAll($sql);
    }

    /** @param array<string, mixed> $data */
    private function createItem(array $data): array
    {
        $name = trim((string)($data['Item_Name'] ?? ''));
        if ($name === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Item name is required.'], 400);
        }

        $category = trim((string)($data['Category'] ?? 'General')) ?: 'General';
        $unit = trim((string)($data['Unit'] ?? 'pcs')) ?: 'pcs';
        $stock = max(0, (float)($data['Stock_Quantity'] ?? 0));
        $reorder = max(0, (float)($data['Reorder_Level'] ?? 0));
        $cost = max(0, (float)($data['Unit_Cost'] ?? 0));
        $supplierId = $this->nullableInt($data['Supplier_ID'] ?? null);

        if ($supplierId !== null) {
            $supplier = $this->getSupplierById($supplierId);
            if ($supplier === null) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Selected supplier does not exist.'], 400);
            }
            if (($supplier['Status'] ?? 'Active') === 'Inactive') {
                $this->jsonResponse(['status' => 'error', 'message' => 'Cannot assign an inactive supplier to a supply item.'], 400);
            }
        }
        $notes = trim((string)($data['Notes'] ?? ''));
        $status = $this->normalizeItemStatus($data['Status'] ?? 'Active');

        if ($supplierId === null) {
            $stmt = $this->conn->prepare(
                'INSERT INTO supply_item (Item_Name, Category, Unit, Stock_Quantity, Reorder_Level, Unit_Cost, Notes, Status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('sssdddss', $name, $category, $unit, $stock, $reorder, $cost, $notes, $status);
        } else {
            $stmt = $this->conn->prepare(
                'INSERT INTO supply_item (Item_Name, Category, Unit, Stock_Quantity, Reorder_Level, Unit_Cost, Supplier_ID, Notes, Status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('sssdddiss', $name, $category, $unit, $stock, $reorder, $cost, $supplierId, $notes, $status);
        }
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();

        return $this->getItemById($id) ?? [];
    }

    /** @param array<string, mixed> $data */
    private function updateItem(array $data): void
    {
        $id = (int)($data['Item_ID'] ?? 0);
        if ($id <= 0 || $this->getItemById($id) === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Supply item not found.'], 404);
        }

        $name = trim((string)($data['Item_Name'] ?? ''));
        if ($name === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Item name is required.'], 400);
        }

        $category = trim((string)($data['Category'] ?? 'General')) ?: 'General';
        $unit = trim((string)($data['Unit'] ?? 'pcs')) ?: 'pcs';
        $stock = max(0, (float)($data['Stock_Quantity'] ?? 0));
        $reorder = max(0, (float)($data['Reorder_Level'] ?? 0));
        $cost = max(0, (float)($data['Unit_Cost'] ?? 0));
        $supplierId = $this->nullableInt($data['Supplier_ID'] ?? null);

        if ($supplierId !== null) {
            $supplier = $this->getSupplierById($supplierId);
            if ($supplier === null) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Selected supplier does not exist.'], 400);
            }
            if (($supplier['Status'] ?? 'Active') === 'Inactive') {
                $this->jsonResponse(['status' => 'error', 'message' => 'Cannot assign an inactive supplier to a supply item.'], 400);
            }
        }
        $notes = trim((string)($data['Notes'] ?? ''));
        $status = $this->normalizeItemStatus($data['Status'] ?? 'Active');

        if ($supplierId === null) {
            $stmt = $this->conn->prepare(
                'UPDATE supply_item SET Item_Name = ?, Category = ?, Unit = ?, Stock_Quantity = ?, Reorder_Level = ?,
                        Unit_Cost = ?, Supplier_ID = NULL, Notes = ?, Status = ? WHERE Item_ID = ?'
            );
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('sssdddssi', $name, $category, $unit, $stock, $reorder, $cost, $notes, $status, $id);
        } else {
            $stmt = $this->conn->prepare(
                'UPDATE supply_item SET Item_Name = ?, Category = ?, Unit = ?, Stock_Quantity = ?, Reorder_Level = ?,
                        Unit_Cost = ?, Supplier_ID = ?, Notes = ?, Status = ? WHERE Item_ID = ?'
            );
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('sssdddissi', $name, $category, $unit, $stock, $reorder, $cost, $supplierId, $notes, $status, $id);
        }
        $stmt->execute();
        $stmt->close();
    }

    /** @param array<string, mixed> $data */
    private function deleteItem(array $data): void
    {
        $id = (int)($data['Item_ID'] ?? 0);
        if ($id <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Item ID required.'], 400);
        }

        $used = $this->scalarInt('SELECT COUNT(*) AS c FROM purchase_order_line WHERE Item_ID = ?', 'i', [$id]);
        if ($used > 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Cannot delete item referenced by purchase orders.'], 400);
        }

        // Safety safeguard: Check if item is used in any active product recipes to avoid breaking product recipes silently
        if (SupplyChainService::recipeTableReady($this->conn)) {
            $inRecipe = $this->scalarInt('SELECT COUNT(*) AS c FROM product_recipe WHERE Item_ID = ?', 'i', [$id]);
            if ($inRecipe > 0) {
                $this->jsonResponse([
                    'status' => 'error',
                    'message' => 'Cannot delete item that is currently part of a product recipe. Please remove it from all recipes first.'
                ], 400);
            }
        }

        $stmt = $this->conn->prepare('DELETE FROM supply_item WHERE Item_ID = ?');
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Quick stock-count adjustment for an ingredient (signed delta).
     *
     * Used by both staff (counts during shift) and owners. The reason and
     * actor are written to the application log so any later spot-check has a
     * paper trail. The DB write is atomic and clamped at zero.
     *
     * Expected payload:
     *   - Item_ID:      int (required)
     *   - Delta:        signed float (required, non-zero)
     *   - Reason:       enum string (recount|spoilage|damage|received|other)
     *   - Notes:        optional free text
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>  Updated item row.
     */
    private function adjustItemStock(array $data): array
    {
        $itemId = (int)($data['Item_ID'] ?? 0);
        $delta  = (float)($data['Delta'] ?? 0);
        $reason = $this->normalizeAdjustReason($data['Reason'] ?? 'recount');
        $notes  = mb_substr(trim((string)($data['Notes'] ?? '')), 0, 255);

        if ($itemId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Ingredient ID required.'], 400);
        }
        if ($delta === 0.0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Adjustment must be non-zero.'], 400);
        }
        // Sanity guard: a single adjustment over 10,000 units is almost always a typo.
        if (abs($delta) > 10000) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Adjustment too large. Split into smaller counts.'], 400);
        }

        $current = $this->getItemById($itemId);
        if ($current === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Ingredient not found.'], 404);
        }

        // Atomic: clamp at zero so we never go negative.
        $stmt = $this->conn->prepare(
            'UPDATE supply_item SET Stock_Quantity = GREATEST(Stock_Quantity + ?, 0) WHERE Item_ID = ?'
        );
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
        }
        $stmt->bind_param('di', $delta, $itemId);
        $stmt->execute();
        $stmt->close();

        $this->service->logMovement(
            $itemId,
            $delta,
            'Adjust',
            null,
            null,
            null,
            $reason,
            $notes !== '' ? $notes : null
        );

        $actor = (int)($_SESSION['user_id'] ?? 0);
        $role  = (string)($_SESSION['user_role'] ?? '');
        error_log(sprintf(
            'supply_item adjustment: item=%d delta=%+.3f reason=%s actor=%d/%s notes=%s',
            $itemId, $delta, $reason, $actor, $role, $notes
        ));

        return $this->getItemById($itemId) ?? [];
    }

    private function normalizeAdjustReason(mixed $reason): string
    {
        $value = strtolower(trim((string)$reason));
        $allowed = ['recount', 'spoilage', 'damage', 'received', 'other'];
        return in_array($value, $allowed, true) ? $value : 'recount';
    }

    /** @return list<array<string, mixed>> */
    private function listPurchaseOrders(): array
    {
        $sql = "SELECT po.PO_ID, po.Supplier_ID, po.Order_Date, po.Expected_Delivery, po.Status, po.Total_Amount,
                       po.Notes, po.Created_At, s.Supplier_Name,
                       (SELECT COUNT(*) FROM purchase_order_line pol WHERE pol.PO_ID = po.PO_ID) AS Line_Count
                FROM purchase_order po
                INNER JOIN supplier s ON s.Supplier_ID = po.Supplier_ID
                ORDER BY po.PO_ID DESC";
        return $this->fetchAll($sql);
    }

    /** @return array<string, mixed>|null */
    private function getPurchaseOrderDetail(int $poId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT po.*, s.Supplier_Name FROM purchase_order po
             INNER JOIN supplier s ON s.Supplier_ID = po.Supplier_ID
             WHERE po.PO_ID = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $poId);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$header) {
            return null;
        }

        $lines = $this->fetchAll(
            "SELECT pol.Line_ID, pol.PO_ID, pol.Item_ID, pol.Quantity_Ordered, pol.Quantity_Received, pol.Unit_Cost,
                    si.Item_Name, si.Unit
             FROM purchase_order_line pol
             INNER JOIN supply_item si ON si.Item_ID = pol.Item_ID
             WHERE pol.PO_ID = " . (int)$poId
        );

        $header['lines'] = $lines;
        return $header;
    }

    /** @param array<string, mixed> $data */
    private function createPurchaseOrder(array $data): array
    {
        $supplierId = (int)($data['Supplier_ID'] ?? 0);
        $supplier = $this->getSupplierById($supplierId);
        if ($supplierId <= 0 || $supplier === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Valid supplier is required.'], 400);
        }
        if (($supplier['Status'] ?? 'Active') === 'Inactive') {
            $this->jsonResponse(['status' => 'error', 'message' => 'Cannot create purchase order for an inactive supplier.'], 400);
        }

        $linesRaw = $data['lines'] ?? '[]';
        if (is_string($linesRaw)) {
            $lines = json_decode($linesRaw, true);
        } else {
            $lines = $linesRaw;
        }
        if (!is_array($lines) || count($lines) === 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'At least one line item is required.'], 400);
        }

        $orderDate = trim((string)($data['Order_Date'] ?? date('Y-m-d')));
        $expected = trim((string)($data['Expected_Delivery'] ?? ''));
        $expectedDelivery = $expected !== '' ? $expected : null;
        $status = $this->normalizePoStatus($data['Status'] ?? 'Ordered');
        $notes = trim((string)($data['Notes'] ?? ''));
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $total = 0.0;

        $parsedLines = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $itemId = (int)($line['Item_ID'] ?? 0);
            $qty = (float)($line['Quantity_Ordered'] ?? 0);
            $unitCost = (float)($line['Unit_Cost'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            if ($this->getItemById($itemId) === null) {
                $this->jsonResponse(['status' => 'error', 'message' => "Supply item #{$itemId} not found."], 400);
            }
            $parsedLines[] = ['Item_ID' => $itemId, 'Quantity_Ordered' => $qty, 'Unit_Cost' => $unitCost];
            $total += $qty * $unitCost;
        }

        if (count($parsedLines) === 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Valid line items required.'], 400);
        }

        $this->conn->begin_transaction();
        try {
            $token = $this->generatePoToken();
            if ($expectedDelivery === null) {
                $stmt = $this->conn->prepare(
                    'INSERT INTO purchase_order (Supplier_ID, Order_Date, Expected_Delivery, Status, Total_Amount, Notes, Created_By, PO_Token)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new \RuntimeException('Failed to create purchase order.');
                }
                $stmt->bind_param('issdsis', $supplierId, $orderDate, $status, $total, $notes, $createdBy, $token);
            } else {
                $stmt = $this->conn->prepare(
                    'INSERT INTO purchase_order (Supplier_ID, Order_Date, Expected_Delivery, Status, Total_Amount, Notes, Created_By, PO_Token)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new \RuntimeException('Failed to create purchase order.');
                }
                $stmt->bind_param('isssdsis', $supplierId, $orderDate, $expectedDelivery, $status, $total, $notes, $createdBy, $token);
            }
            $stmt->execute();
            $poId = (int)$stmt->insert_id;
            $stmt->close();

            $lineStmt = $this->conn->prepare(
                'INSERT INTO purchase_order_line (PO_ID, Item_ID, Quantity_Ordered, Quantity_Received, Unit_Cost)
                 VALUES (?, ?, ?, 0, ?)'
            );
            if (!$lineStmt) {
                throw new \RuntimeException('Failed to prepare line insert.');
            }

            foreach ($parsedLines as $line) {
                $itemId = $line['Item_ID'];
                $qty = $line['Quantity_Ordered'];
                $unitCost = $line['Unit_Cost'];
                $lineStmt->bind_param('iidd', $poId, $itemId, $qty, $unitCost);
                $lineStmt->execute();
            }
            $lineStmt->close();

            if ($status === 'Received') {
                $this->receivePurchaseOrderStock($poId);
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to create purchase order.'], 500);
        }

        // Best-effort: email the supplier the secure confirmation link.
        // Failure here must NOT break PO creation — the admin can manually resend.
        try {
            $this->emailSupplierPoLink($poId);
        } catch (\Throwable $e) {
            error_log('Supplier PO link email failed for PO ' . $poId . ': ' . $e->getMessage());
        }

        return $this->getPurchaseOrderDetail($poId) ?? [];
    }

    /** @param array<string, mixed> $data */
    private function updatePurchaseOrderStatus(array $data): array
    {
        $poId = (int)($data['PO_ID'] ?? 0);
        $newStatus = $this->normalizePoStatus($data['Status'] ?? '');
        if ($poId <= 0 || $newStatus === '') {
            $this->jsonResponse(['status' => 'error', 'message' => 'PO ID and status are required.'], 400);
        }

        $role = strtolower((string)($_SESSION['user_role'] ?? ''));
        if ($role === 'staff' && !in_array($newStatus, ['Received', 'Ordered'], true)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Staff can only mark orders as Ordered or Received.'], 403);
        }

        $po = $this->getPurchaseOrderDetail($poId);
        if ($po === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Purchase order not found.'], 404);
        }

        $current = (string)($po['Status'] ?? '');
        if ($current === 'Cancelled' || $current === 'Received') {
            $this->jsonResponse(['status' => 'error', 'message' => 'This purchase order can no longer be updated.'], 400);
        }

        $this->conn->begin_transaction();
        try {
            if ($newStatus === 'Received') {
                $this->receivePurchaseOrderStock($poId);
                $finalStatus = 'Received';
            } elseif ($newStatus === 'Cancelled') {
                if ($role === 'staff') {
                    $this->jsonResponse(['status' => 'error', 'message' => 'Staff cannot cancel purchase orders.'], 403);
                }
                $finalStatus = 'Cancelled';
            } elseif ($newStatus === 'Ordered' && $current === 'Draft') {
                $finalStatus = 'Ordered';
            } else {
                $finalStatus = $newStatus;
            }

            $stmt = $this->conn->prepare('UPDATE purchase_order SET Status = ? WHERE PO_ID = ?');
            if (!$stmt) {
                throw new \RuntimeException('Update failed.');
            }
            $stmt->bind_param('si', $finalStatus, $poId);
            $stmt->execute();
            $stmt->close();
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            $this->jsonResponse(['status' => 'error', 'message' => 'Failed to update purchase order.'], 500);
        }

        // Best-effort: email the supplier when the order has been received.
        // Failure here must NOT fail the status update — the PO is already saved.
        if ($finalStatus === 'Received') {
            try {
                $this->emailSupplierPoReceived($poId);
            } catch (\Throwable $e) {
                error_log('Supplier "received" email failed for PO ' . $poId . ': ' . $e->getMessage());
            }
        }

        return $this->getPurchaseOrderDetail($poId) ?? [];
    }

    private function receivePurchaseOrderStock(int $poId): void
    {
        $lines = $this->fetchAll(
            "SELECT Line_ID, Item_ID, Quantity_Ordered, Quantity_Received FROM purchase_order_line WHERE PO_ID = " . (int)$poId
        );

        foreach ($lines as $line) {
            $lineId = (int)$line['Line_ID'];
            $itemId = (int)$line['Item_ID'];
            $ordered = (float)$line['Quantity_Ordered'];
            $received = (float)$line['Quantity_Received'];
            $toReceive = max(0, $ordered - $received);
            if ($toReceive <= 0) {
                continue;
            }

            $stmt = $this->conn->prepare(
                'UPDATE supply_item SET Stock_Quantity = Stock_Quantity + ? WHERE Item_ID = ?'
            );
            if ($stmt) {
                $stmt->bind_param('di', $toReceive, $itemId);
                $stmt->execute();
                $stmt->close();
                $this->service->logMovement(
                    $itemId,
                    $toReceive,
                    'Receive',
                    null,
                    'PurchaseOrder',
                    $poId,
                    null,
                    'Stock from PO line #' . $lineId
                );
            }

            $lineStmt = $this->conn->prepare(
                'UPDATE purchase_order_line SET Quantity_Received = Quantity_Received + ? WHERE Line_ID = ?'
            );
            if ($lineStmt) {
                $lineStmt->bind_param('di', $toReceive, $lineId);
                $lineStmt->execute();
                $lineStmt->close();
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function getSupplierById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM supplier WHERE Supplier_ID = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function getItemById(int $id): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM supply_item WHERE Item_ID = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function normalizeSupplierStatus(mixed $status): string
    {
        $value = strtolower(trim((string)$status));
        return $value === 'inactive' ? 'Inactive' : 'Active';
    }

    private function normalizeItemStatus(mixed $status): string
    {
        return $this->normalizeSupplierStatus($status);
    }

    private function normalizePoStatus(mixed $status): string
    {
        $value = ucfirst(strtolower(trim((string)$status)));
        $allowed = ['Draft', 'Ordered', 'Confirmed', 'Shipped', 'Partial', 'Received', 'Cancelled'];
        return in_array($value, $allowed, true) ? $value : '';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }

    private function scalarInt(string $sql, string $types = '', array $params = []): int
    {
        if ($types === '') {
            $result = $this->conn->query($sql);
            if (!$result) {
                return 0;
            }
            $row = $result->fetch_assoc();
            return (int)($row['c'] ?? $row['v'] ?? 0);
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['c'] ?? 0);
    }

    private function scalarFloat(string $sql): float
    {
        $result = $this->conn->query($sql);
        if (!$result) {
            return 0.0;
        }
        $row = $result->fetch_assoc();
        return (float)($row['v'] ?? 0);
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql): array
    {
        $result = $this->conn->query($sql);
        if (!$result) {
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC) ?: [];
    }

    // ---------------------------------------------------------------------
    // Supplier confirmation (public, token-authenticated B2B flow — Option B)
    // ---------------------------------------------------------------------

    /**
     * Generate a 64-char URL-safe random token. 32 bytes of CSPRNG entropy is
     * effectively unguessable (256 bits) — same strength used for password
     * reset and session tokens.
     */
    private function generatePoToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Lazy-issue or return the supplier link for a PO. If a legacy PO exists
     * without a token (created before this migration) we generate one on first
     * request so existing records remain useable.
     *
     * @return array{po_id:int, token:string, url:string, status:string, supplier_name:string}
     */
    private function getOrIssuePoLink(int $poId): array
    {
        $po = $this->getPurchaseOrderDetail($poId);
        if ($po === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Purchase order not found.'], 404);
        }

        $token = (string)($po['PO_Token'] ?? '');
        if ($token === '') {
            $token = $this->generatePoToken();
            $stmt = $this->conn->prepare('UPDATE purchase_order SET PO_Token = ? WHERE PO_ID = ?');
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('si', $token, $poId);
            $stmt->execute();
            $stmt->close();
        }

        return [
            'po_id'         => $poId,
            'token'         => $token,
            'url'           => $this->buildSupplierLinkUrl($poId, $token),
            'status'        => (string)($po['Status'] ?? ''),
            'supplier_name' => (string)($po['Supplier_Name'] ?? ''),
        ];
    }

    private function buildSupplierLinkUrl(int $poId, string $token): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $this->detectBasePath();
        return sprintf('%s://%s%s/supplier_po.php?id=%d&token=%s', $scheme, $host, $base, $poId, $token);
    }

    /**
     * Resolve the public URL prefix for this install. APP_BASE_PATH wins when
     * explicitly configured; otherwise we derive the prefix from the project's
     * filesystem location relative to DOCUMENT_ROOT (handles Laragon's
     * /GuillermosWebSystemss subdirectory transparently).
     */
    private function detectBasePath(): string
    {
        $envBase = defined('APP_BASE_PATH') ? (string)APP_BASE_PATH : '';
        if ($envBase !== '') {
            return $envBase;
        }

        $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        $projectDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/'); // /Controllers → project root
        if ($docRoot !== '' && stripos($projectDir, $docRoot) === 0) {
            $derived = substr($projectDir, strlen($docRoot));
            return $derived === '' ? '' : '/' . ltrim($derived, '/');
        }

        // Last-resort fallback: walk up from the current script.
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $segments = array_values(array_filter(explode('/', $script), 'strlen'));
        // Strip the script filename and any /Views/* depth so we land on project root.
        return $segments === [] ? '' : '/' . $segments[0];
    }

    /**
     * Public entry point. Authenticates via PO_ID + token and returns a
     * sanitized payload safe to render on a customer-facing page.
     *
     * @return array{po:array<string,mixed>, lines:list<array<string,mixed>>, supplier:array<string,mixed>}|null
     */
    public function getSupplierPoByToken(int $poId, string $token): ?array
    {
        if ($poId <= 0 || $token === '' || strlen($token) > 128) {
            return null;
        }
        if (!$this->tablesReady()) {
            return null;
        }

        $stmt = $this->conn->prepare(
            'SELECT po.PO_ID, po.Supplier_ID, po.Order_Date, po.Expected_Delivery, po.Status,
                    po.Total_Amount, po.Notes, po.Supplier_Notes, po.Confirmed_At, po.PO_Token,
                    s.Supplier_Name, s.Contact_Person, s.Email, s.Phone
             FROM purchase_order po
             INNER JOIN supplier s ON s.Supplier_ID = po.Supplier_ID
             WHERE po.PO_ID = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $poId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $stored = (string)($row['PO_Token'] ?? '');
        // Timing-safe comparison defeats remote token enumeration.
        if ($stored === '' || !hash_equals($stored, $token)) {
            return null;
        }

        $lines = $this->fetchAll(
            'SELECT pol.Line_ID, pol.Item_ID, pol.Quantity_Ordered, pol.Unit_Cost,
                    si.Item_Name, si.Unit
             FROM purchase_order_line pol
             INNER JOIN supply_item si ON si.Item_ID = pol.Item_ID
             WHERE pol.PO_ID = ' . (int)$poId
        );

        // Strip the token from the public payload — never echo it back.
        unset($row['PO_Token']);

        return [
            'po'       => $row,
            'lines'    => $lines,
            'issues'   => $this->listPoIssues($poId, true),
            'supplier' => [
                'Supplier_Name'  => $row['Supplier_Name'] ?? '',
                'Contact_Person' => $row['Contact_Person'] ?? '',
                'Email'          => $row['Email'] ?? '',
                'Phone'          => $row['Phone'] ?? '',
            ],
        ];
    }

    /**
     * Public action handler — invoked by supplier_po.php for both viewing and
     * submitting confirmations. Routes around session-based requireAuth().
     */
    public function handlePublicAjax(): void
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if (!in_array($action, $this->publicActions, true)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unknown action.'], 400);
        }
        if (!$this->tablesReady()) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Supply chain not installed.'], 503);
        }

        $data = $this->getRequestData();
        $poId  = (int)($_GET['id'] ?? $data['id'] ?? 0);
        $token = trim((string)($_GET['token'] ?? $data['token'] ?? ''));

        $payload = $this->getSupplierPoByToken($poId, $token);
        if ($payload === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Invalid or expired link.'], 403);
        }

        if ($action === 'supply-supplier-view-po') {
            $this->jsonResponse(['status' => 'success', 'data' => $payload]);
        }

        if ($action === 'supply-supplier-reply-issue') {
            $this->requirePost();
            $issueId = (int)($data['issue_id'] ?? 0);
            $reply   = mb_substr(trim((string)($data['reply'] ?? '')), 0, 1000);
            if ($issueId <= 0 || $reply === '') {
                $this->jsonResponse(['status' => 'error', 'message' => 'Issue ID and reply are required.'], 400);
            }
            $owns = $this->scalarInt(
                'SELECT COUNT(*) AS c FROM supply_issue WHERE Issue_ID = ? AND PO_ID = ?',
                'ii',
                [$issueId, $poId]
            );
            if ($owns === 0) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Issue not found for this order.'], 404);
            }
            $stmt = $this->conn->prepare(
                "UPDATE supply_issue
                 SET Supplier_Reply = ?,
                     Status = CASE WHEN Status = 'Open' THEN 'Acknowledged' ELSE Status END
                 WHERE Issue_ID = ?"
            );
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('si', $reply, $issueId);
            $stmt->execute();
            $stmt->close();
            $this->jsonResponse([
                'status'  => 'success',
                'message' => 'Reply received. Thank you.',
                'data'    => $this->getSupplierPoByToken($poId, $token),
            ]);
        }

        // supply-supplier-confirm-po
        $this->requirePost();

        $current = (string)($payload['po']['Status'] ?? '');
        if (in_array($current, ['Cancelled', 'Received'], true)) {
            $this->jsonResponse(['status' => 'error', 'message' => 'This order can no longer be updated.'], 400);
        }

        // Supplier picks a number of days until delivery (simpler than a date picker).
        $rawDays = $data['Lead_Time_Days'] ?? null;
        $expected = null;
        if ($rawDays !== null && $rawDays !== '') {
            $days = (int)$rawDays;
            if ($days < 1 || $days > 60) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Please choose a delivery window between 1 and 60 days.'], 400);
            }
            $expected = date('Y-m-d', strtotime("+{$days} days"));
        }

        $supplierNotes = mb_substr(trim((string)($data['Supplier_Notes'] ?? '')), 0, 1000);
        $newStatus = $this->normalizePoStatus($data['Status'] ?? 'Confirmed');
        if (!in_array($newStatus, ['Confirmed', 'Shipped'], true)) {
            $newStatus = 'Confirmed';
        }

        if ($expected === null) {
            $stmt = $this->conn->prepare(
                'UPDATE purchase_order
                 SET Status = ?, Supplier_Notes = ?, Confirmed_At = NOW()
                 WHERE PO_ID = ?'
            );
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('ssi', $newStatus, $supplierNotes, $poId);
        } else {
            $stmt = $this->conn->prepare(
                'UPDATE purchase_order
                 SET Status = ?, Supplier_Notes = ?, Expected_Delivery = ?, Confirmed_At = NOW()
                 WHERE PO_ID = ?'
            );
            if (!$stmt) {
                $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
            }
            $stmt->bind_param('sssi', $newStatus, $supplierNotes, $expected, $poId);
        }
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Could not save your confirmation. Please try again.'], 500);
        }

        $this->jsonResponse([
            'status'  => 'success',
            'message' => 'Thank you. The order has been ' . strtolower($newStatus) . '.',
            'data'    => $this->getSupplierPoByToken($poId, $token),
        ]);
    }

    /**
     * Mail the secure confirmation link to the supplier on record. Called
     * automatically right after createPurchaseOrder() and on-demand via the
     * "Email to supplier" button in the dashboard.
     *
     * @return array{status:string, message:string}
     */
    private function emailSupplierPoLink(int $poId): array
    {
        require_once __DIR__ . '/EmailApiController.php';

        $stmt = $this->conn->prepare(
            'SELECT po.PO_ID, po.Order_Date, po.Total_Amount, po.Notes,
                    s.Supplier_Name, s.Contact_Person, s.Email,
                    (SELECT COUNT(*) FROM purchase_order_line pol WHERE pol.PO_ID = po.PO_ID) AS Line_Count
             FROM purchase_order po
             INNER JOIN supplier s ON s.Supplier_ID = po.Supplier_ID
             WHERE po.PO_ID = ? LIMIT 1'
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Database error.'];
        }
        $stmt->bind_param('i', $poId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['status' => 'error', 'message' => 'Purchase order not found.'];
        }

        $email = trim((string)($row['Email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'  => 'error',
                'message' => 'This supplier has no valid email on file. Add one in Suppliers and try again.',
            ];
        }

        $link = $this->getOrIssuePoLink($poId);

        $result = \EmailApiController::sendSupplierPoLinkEmail(
            $email,
            (string)($row['Contact_Person'] ?: $row['Supplier_Name']),
            [
                'po_id'         => $poId,
                'supplier_name' => (string)$row['Supplier_Name'],
                'order_date'    => (string)($row['Order_Date'] ?? ''),
                'total_amount'  => (float)($row['Total_Amount'] ?? 0),
                'line_count'    => (int)($row['Line_Count'] ?? 0),
                'notes'         => (string)($row['Notes'] ?? ''),
                'url'           => (string)$link['url'],
            ]
        );

        if ($result === true) {
            return [
                'status'  => 'success',
                'message' => 'Confirmation link emailed to ' . $email . '.',
            ];
        }

        return [
            'status'  => 'error',
            'message' => is_string($result) ? $result : 'Failed to send email.',
        ];
    }

    // ---------------------------------------------------------------------
    // Material issues (refunds / replacements / credit notes)
    // ---------------------------------------------------------------------

    private function issuesTableReady(): bool
    {
        $result = $this->conn->query("SHOW TABLES LIKE 'supply_issue'");
        return $result instanceof \mysqli_result && $result->num_rows > 0;
    }

    /** @return list<array<string,mixed>> */
    private function listPoIssues(int $poId, bool $publicView = false): array
    {
        if ($poId <= 0 || !$this->issuesTableReady()) {
            return [];
        }

        $cols = $publicView
            ? 'Issue_ID, PO_ID, Line_ID, Issue_Type, Action_Requested, Quantity_Affected,
               Status, Buyer_Notes, Supplier_Reply, Created_At, Resolved_At'
            : 'si.Issue_ID, si.PO_ID, si.Line_ID, si.Issue_Type, si.Action_Requested,
               si.Quantity_Affected, si.Status, si.Buyer_Notes, si.Supplier_Reply,
               si.Created_At, si.Resolved_At, si.Reported_By,
               sup.Item_Name, sup.Unit';

        if ($publicView) {
            $sql = "SELECT si.Issue_ID, si.PO_ID, si.Line_ID, si.Issue_Type, si.Action_Requested,
                           si.Quantity_Affected, si.Status, si.Buyer_Notes, si.Supplier_Reply,
                           si.Created_At, si.Resolved_At,
                           sup.Item_Name, sup.Unit
                    FROM supply_issue si
                    LEFT JOIN purchase_order_line pol ON pol.Line_ID = si.Line_ID
                    LEFT JOIN supply_item sup ON sup.Item_ID = pol.Item_ID
                    WHERE si.PO_ID = " . (int)$poId . "
                    ORDER BY si.Created_At DESC";
        } else {
            $sql = "SELECT {$cols}
                    FROM supply_issue si
                    LEFT JOIN purchase_order_line pol ON pol.Line_ID = si.Line_ID
                    LEFT JOIN supply_item sup ON sup.Item_ID = pol.Item_ID
                    WHERE si.PO_ID = " . (int)$poId . "
                    ORDER BY si.Created_At DESC";
        }
        return $this->fetchAll($sql);
    }

    /** @param array<string,mixed> $data */
    private function createPoIssue(array $data): array
    {
        if (!$this->issuesTableReady()) {
            return ['status' => 'error', 'message' => 'Issue tracking is not installed. Run sql/supply_chain_phase4_issues.sql.'];
        }

        $poId   = (int)($data['PO_ID'] ?? 0);
        $lineId = $this->nullableInt($data['Line_ID'] ?? null);
        $type   = $this->normalizeIssueType($data['Issue_Type'] ?? 'Other');
        $action = $this->normalizeIssueAction($data['Action_Requested'] ?? 'Replacement');
        $qty    = max(0.0, (float)($data['Quantity_Affected'] ?? 0));
        $notes  = mb_substr(trim((string)($data['Buyer_Notes'] ?? '')), 0, 1000);
        $userId = (int)($_SESSION['user_id'] ?? 0) ?: null;

        if ($poId <= 0 || $this->getPurchaseOrderDetail($poId) === null) {
            return ['status' => 'error', 'message' => 'Purchase order not found.'];
        }

        if ($lineId !== null) {
            $owns = $this->scalarInt(
                'SELECT COUNT(*) AS c FROM purchase_order_line WHERE Line_ID = ? AND PO_ID = ?',
                'ii',
                [$lineId, $poId]
            );
            if ($owns === 0) {
                return ['status' => 'error', 'message' => 'That line does not belong to this PO.'];
            }
        }

        $stmt = $this->conn->prepare(
            'INSERT INTO supply_issue (PO_ID, Line_ID, Issue_Type, Action_Requested, Quantity_Affected, Buyer_Notes, Reported_By)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return ['status' => 'error', 'message' => 'Database error.'];
        }
        $stmt->bind_param('iissdsi', $poId, $lineId, $type, $action, $qty, $notes, $userId);
        $stmt->execute();
        $issueId = (int)$stmt->insert_id;
        $stmt->close();

        // Best-effort email notification to the supplier.
        try {
            $this->emailSupplierIssue($issueId);
        } catch (\Throwable $e) {
            error_log('Supplier issue email failed for issue ' . $issueId . ': ' . $e->getMessage());
        }

        return [
            'status'  => 'success',
            'message' => 'Issue filed and supplier notified.',
            'data'    => $this->listPoIssues($poId),
        ];
    }

    /** @param array<string,mixed> $data */
    private function updatePoIssue(array $data): array
    {
        if (!$this->issuesTableReady()) {
            return ['status' => 'error', 'message' => 'Issue tracking is not installed.'];
        }

        $issueId = (int)($data['Issue_ID'] ?? 0);
        $newStatus = $this->normalizeIssueStatus($data['Status'] ?? '');
        if ($issueId <= 0 || $newStatus === '') {
            return ['status' => 'error', 'message' => 'Issue ID and status are required.'];
        }

        // Look up the parent PO for the response payload.
        $poRow = $this->conn->query('SELECT PO_ID FROM supply_issue WHERE Issue_ID = ' . (int)$issueId)?->fetch_assoc();
        if (!$poRow) {
            return ['status' => 'error', 'message' => 'Issue not found.'];
        }
        $poId = (int)$poRow['PO_ID'];

        $resolvedAt = in_array($newStatus, ['Resolved', 'Rejected'], true) ? date('Y-m-d H:i:s') : null;
        if ($resolvedAt === null) {
            $stmt = $this->conn->prepare('UPDATE supply_issue SET Status = ?, Resolved_At = NULL WHERE Issue_ID = ?');
            if (!$stmt) { return ['status' => 'error', 'message' => 'Database error.']; }
            $stmt->bind_param('si', $newStatus, $issueId);
        } else {
            $stmt = $this->conn->prepare('UPDATE supply_issue SET Status = ?, Resolved_At = ? WHERE Issue_ID = ?');
            if (!$stmt) { return ['status' => 'error', 'message' => 'Database error.']; }
            $stmt->bind_param('ssi', $newStatus, $resolvedAt, $issueId);
        }
        $stmt->execute();
        $stmt->close();

        return [
            'status'  => 'success',
            'message' => 'Issue updated.',
            'data'    => $this->listPoIssues($poId),
        ];
    }

    private function normalizeIssueType(mixed $type): string
    {
        $value = (string)$type;
        $allowed = ['Damaged', 'Wrong_Quantity', 'Expired', 'Wrong_Item', 'Other'];
        return in_array($value, $allowed, true) ? $value : 'Other';
    }

    private function normalizeIssueAction(mixed $action): string
    {
        $value = (string)$action;
        $allowed = ['Refund', 'Replacement', 'Credit_Note'];
        return in_array($value, $allowed, true) ? $value : 'Replacement';
    }

    private function normalizeIssueStatus(mixed $status): string
    {
        $value = ucfirst(strtolower(trim((string)$status)));
        $allowed = ['Open', 'Acknowledged', 'Resolved', 'Rejected'];
        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * Send the "materials received" acknowledgement to the supplier.
     */
    private function emailSupplierPoReceived(int $poId): void
    {
        require_once __DIR__ . '/EmailApiController.php';

        $stmt = $this->conn->prepare(
            'SELECT po.PO_ID, po.Total_Amount, s.Supplier_Name, s.Contact_Person, s.Email,
                    (SELECT COUNT(*) FROM purchase_order_line pol WHERE pol.PO_ID = po.PO_ID) AS Line_Count
             FROM purchase_order po
             INNER JOIN supplier s ON s.Supplier_ID = po.Supplier_ID
             WHERE po.PO_ID = ? LIMIT 1'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $poId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return;
        }
        $email = trim((string)($row['Email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $link = $this->getOrIssuePoLink($poId);

        \EmailApiController::sendSupplierPoReceivedEmail(
            $email,
            (string)($row['Contact_Person'] ?: $row['Supplier_Name']),
            [
                'po_id'         => $poId,
                'supplier_name' => (string)$row['Supplier_Name'],
                'received_date' => date('M d, Y'),
                'total_amount'  => (float)($row['Total_Amount'] ?? 0),
                'line_count'    => (int)($row['Line_Count'] ?? 0),
                'url'           => (string)$link['url'],
            ]
        );
    }

    /**
     * Send an "issue reported" email to the supplier with details of the
     * just-filed problem and the secure link.
     */
    private function emailSupplierIssue(int $issueId): void
    {
        require_once __DIR__ . '/EmailApiController.php';

        $stmt = $this->conn->prepare(
            'SELECT si.Issue_ID, si.PO_ID, si.Issue_Type, si.Action_Requested, si.Quantity_Affected, si.Buyer_Notes,
                    s.Supplier_Name, s.Contact_Person, s.Email,
                    sup.Item_Name
             FROM supply_issue si
             INNER JOIN purchase_order po ON po.PO_ID = si.PO_ID
             INNER JOIN supplier s ON s.Supplier_ID = po.Supplier_ID
             LEFT JOIN purchase_order_line pol ON pol.Line_ID = si.Line_ID
             LEFT JOIN supply_item sup ON sup.Item_ID = pol.Item_ID
             WHERE si.Issue_ID = ? LIMIT 1'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $issueId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return;
        }
        $email = trim((string)($row['Email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $poId = (int)$row['PO_ID'];
        $link = $this->getOrIssuePoLink($poId);

        \EmailApiController::sendSupplierIssueEmail(
            $email,
            (string)($row['Contact_Person'] ?: $row['Supplier_Name']),
            [
                'po_id'             => $poId,
                'supplier_name'     => (string)$row['Supplier_Name'],
                'item_name'         => (string)($row['Item_Name'] ?? '—'),
                'issue_type'        => (string)$row['Issue_Type'],
                'action'            => (string)$row['Action_Requested'],
                'quantity_affected' => (float)($row['Quantity_Affected'] ?? 0),
                'buyer_notes'       => (string)($row['Buyer_Notes'] ?? ''),
                'url'               => (string)$link['url'],
            ]
        );
    }

    // ---------------------------------------------------------------------
    // History endpoints
    // ---------------------------------------------------------------------

    /**
     * Recent stock movements for one ingredient.
     * @return list<array<string,mixed>>
     */
    private function getItemHistory(int $itemId, int $limit = 50): array
    {
        if (!SupplyChainService::historyTableReady($this->conn)) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $stmt = $this->conn->prepare(
            'SELECT sil.Log_ID, sil.Item_ID, sil.Product_ID, sil.Action_Type,
                    sil.Quantity_Delta, sil.Balance_After,
                    sil.Reference_Type, sil.Reference_ID, sil.Reason, sil.Notes,
                    sil.User_ID, sil.User_Role, sil.Created_At,
                    p.Product_Name, si.Unit
             FROM supply_item_log sil
             LEFT JOIN product p ON p.Product_ID = sil.Product_ID
             LEFT JOIN supply_item si ON si.Item_ID = sil.Item_ID
             WHERE sil.Item_ID = ?
             ORDER BY sil.Created_At DESC, sil.Log_ID DESC
             LIMIT ' . $limit
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return $rows;
    }

    /**
     * Aggregate the ingredients consumed by one order. Useful for the order
     * detail panel: shows exactly what went into making the order.
     * @return list<array<string,mixed>>
     */
    private function getOrderIngredients(int $orderId): array
    {
        if (!SupplyChainService::historyTableReady($this->conn)) {
            return [];
        }
        $stmt = $this->conn->prepare(
            "SELECT si.Item_ID, si.Item_Name, si.Unit,
                    SUM(CASE WHEN sil.Action_Type = 'Sale'   THEN -sil.Quantity_Delta ELSE 0 END) AS Consumed,
                    SUM(CASE WHEN sil.Action_Type = 'Refund' THEN  sil.Quantity_Delta ELSE 0 END) AS Restored,
                    GROUP_CONCAT(DISTINCT p.Product_Name SEPARATOR ', ') AS Products
             FROM supply_item_log sil
             INNER JOIN supply_item si ON si.Item_ID = sil.Item_ID
             LEFT JOIN product p ON p.Product_ID = sil.Product_ID
             WHERE sil.Reference_Type = 'Order' AND sil.Reference_ID = ?
             GROUP BY si.Item_ID, si.Item_Name, si.Unit
             HAVING ABS(Consumed) > 0 OR ABS(Restored) > 0
             ORDER BY si.Item_Name ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return $rows;
    }
}
