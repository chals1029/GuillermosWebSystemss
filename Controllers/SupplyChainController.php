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

        $openPo = $this->scalarInt(
            "SELECT COUNT(*) AS c FROM purchase_order WHERE Supplier_ID = ? AND Status IN ('Draft','Ordered','Partial')",
            'i',
            [$id]
        );
        if ($openPo > 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Cannot delete supplier with open purchase orders.'], 400);
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

        $stmt = $this->conn->prepare('DELETE FROM supply_item WHERE Item_ID = ?');
        if (!$stmt) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Database error.'], 500);
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
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
        if ($supplierId <= 0 || $this->getSupplierById($supplierId) === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Valid supplier is required.'], 400);
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
            if ($expectedDelivery === null) {
                $stmt = $this->conn->prepare(
                    'INSERT INTO purchase_order (Supplier_ID, Order_Date, Expected_Delivery, Status, Total_Amount, Notes, Created_By)
                     VALUES (?, ?, NULL, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new \RuntimeException('Failed to create purchase order.');
                }
                $stmt->bind_param('issdsi', $supplierId, $orderDate, $status, $total, $notes, $createdBy);
            } else {
                $stmt = $this->conn->prepare(
                    'INSERT INTO purchase_order (Supplier_ID, Order_Date, Expected_Delivery, Status, Total_Amount, Notes, Created_By)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new \RuntimeException('Failed to create purchase order.');
                }
                $stmt->bind_param('isssdsi', $supplierId, $orderDate, $expectedDelivery, $status, $total, $notes, $createdBy);
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
        $allowed = ['Draft', 'Ordered', 'Partial', 'Received', 'Cancelled'];
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
}
