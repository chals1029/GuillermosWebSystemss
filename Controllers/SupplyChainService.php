<?php
declare(strict_types=1);

/**
 * Shared supply chain logic: BOM, material stock, and alerts.
 */
class SupplyChainService
{
    private \mysqli $conn;

    public function __construct(?\mysqli $mysqli = null)
    {
        if ($mysqli instanceof \mysqli) {
            $this->conn = $mysqli;
            return;
        }

        global $conn;
        if (!isset($conn) || !$conn instanceof \mysqli) {
            throw new \RuntimeException('Database connection not available.');
        }
        $this->conn = $conn;
    }

    public static function baseTablesReady(\mysqli $conn): bool
    {
        $required = ['supplier', 'supply_item', 'purchase_order', 'purchase_order_line'];
        foreach ($required as $table) {
            $escaped = $conn->real_escape_string($table);
            $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
            if (!$result instanceof \mysqli_result || $result->num_rows === 0) {
                return false;
            }
            $result->free();
        }
        return true;
    }

    public static function recipeTableReady(\mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'product_recipe'");
        return $result instanceof \mysqli_result && $result->num_rows > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecipeForProduct(int $productId): array
    {
        if (!self::recipeTableReady($this->conn) || $productId <= 0) {
            return [];
        }

        $stmt = $this->conn->prepare(
            'SELECT pr.Recipe_ID, pr.Product_ID, pr.Item_ID, pr.Quantity_Per_Serving, pr.Notes,
                    si.Item_Name, si.Unit, si.Stock_Quantity, si.Reorder_Level
             FROM product_recipe pr
             INNER JOIN supply_item si ON si.Item_ID = pr.Item_ID
             WHERE pr.Product_ID = ?
             ORDER BY si.Item_Name ASC'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
        return $rows;
    }

    /**
     * @param list<array{Item_ID:int, Quantity_Per_Serving:float}> $lines
     */
    public function saveRecipe(int $productId, array $lines): void
    {
        if (!self::recipeTableReady($this->conn)) {
            throw new \RuntimeException('Recipe table not installed. Import sql/supply_chain_phase2_migration.sql');
        }
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Product ID is required.');
        }

        $productCheck = $this->conn->prepare('SELECT Product_ID FROM product WHERE Product_ID = ? LIMIT 1');
        if (!$productCheck) {
            throw new \RuntimeException('Database error.');
        }
        $productCheck->bind_param('i', $productId);
        $productCheck->execute();
        $exists = $productCheck->get_result()->fetch_assoc();
        $productCheck->close();
        if (!$exists) {
            throw new \InvalidArgumentException('Product not found.');
        }

        $this->conn->begin_transaction();
        try {
            $del = $this->conn->prepare('DELETE FROM product_recipe WHERE Product_ID = ?');
            if (!$del) {
                throw new \RuntimeException('Failed to reset recipe.');
            }
            $del->bind_param('i', $productId);
            $del->execute();
            $del->close();

            if (count($lines) > 0) {
                $ins = $this->conn->prepare(
                    'INSERT INTO product_recipe (Product_ID, Item_ID, Quantity_Per_Serving, Notes) VALUES (?, ?, ?, ?)'
                );
                if (!$ins) {
                    throw new \RuntimeException('Failed to save recipe lines.');
                }
                foreach ($lines as $line) {
                    $itemId = (int)($line['Item_ID'] ?? 0);
                    $qty = max(0.001, (float)($line['Quantity_Per_Serving'] ?? 0));
                    $notes = trim((string)($line['Notes'] ?? ''));
                    if ($itemId <= 0) {
                        continue;
                    }
                    $ins->bind_param('iids', $productId, $itemId, $qty, $notes);
                    $ins->execute();
                }
                $ins->close();
            }

            $this->conn->commit();

            // Recipe edited: producible servings can change (different ingredients
            // or different qty per serving). Recompute this one product so the
            // menu/Stock_Quantity reflects the new BOM immediately.
            $this->recomputeProductStock($productId);
        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Validate and deduct materials for one product quantity sold.
     * Each ingredient deducted gets one row in supply_item_log so the owner
     * has a full audit trail per order.
     *
     * @param int        $orderId  Optional Order_ID to attach to the log row.
     * @throws \RuntimeException when recipe exists but materials are insufficient
     */
    public function deductMaterialsForProduct(int $productId, float $servings, ?int $orderId = null): void
    {
        if ($servings <= 0 || !self::recipeTableReady($this->conn)) {
            return;
        }

        $recipe = $this->getRecipeForProduct($productId);
        if ($recipe === []) {
            return;
        }

        foreach ($recipe as $line) {
            $needed = (float)$line['Quantity_Per_Serving'] * $servings;
            $available = (float)$line['Stock_Quantity'];
            if ($available < $needed) {
                $name = (string)($line['Item_Name'] ?? 'material');
                throw new \RuntimeException("Insufficient material: {$name} (need " . round($needed, 3) . ', have ' . round($available, 3) . ')');
            }
        }

        foreach ($recipe as $line) {
            $needed = (float)$line['Quantity_Per_Serving'] * $servings;
            $itemId = (int)$line['Item_ID'];
            $stmt = $this->conn->prepare(
                'UPDATE supply_item SET Stock_Quantity = Stock_Quantity - ? WHERE Item_ID = ?'
            );
            if ($stmt) {
                $stmt->bind_param('di', $needed, $itemId);
                $stmt->execute();
                $stmt->close();
                $this->logMovement(
                    $itemId,
                    -$needed,
                    'Sale',
                    $productId,
                    $orderId !== null ? 'Order' : null,
                    $orderId,
                    null,
                    null
                );
            }
        }

        // Cascade: every product whose recipe touches these ingredients now
        // has a different "max producible servings" — refresh their cached
        // product.Stock_Quantity so the customer/staff menus correctly gray
        // out anything that ran out.
        $itemIds = array_map(static fn($l) => (int)$l['Item_ID'], $recipe);
        $this->recomputeProductsForIngredients($itemIds);
    }

    public function restoreMaterialsForProduct(int $productId, float $servings, ?int $orderId = null): void
    {
        if ($servings <= 0 || !self::recipeTableReady($this->conn)) {
            return;
        }

        $recipe = $this->getRecipeForProduct($productId);
        foreach ($recipe as $line) {
            $restore = (float)$line['Quantity_Per_Serving'] * $servings;
            $itemId = (int)$line['Item_ID'];
            $stmt = $this->conn->prepare(
                'UPDATE supply_item SET Stock_Quantity = Stock_Quantity + ? WHERE Item_ID = ?'
            );
            if ($stmt) {
                $stmt->bind_param('di', $restore, $itemId);
                $stmt->execute();
                $stmt->close();
                $this->logMovement(
                    $itemId,
                    $restore,
                    'Refund',
                    $productId,
                    $orderId !== null ? 'Order' : null,
                    $orderId,
                    null,
                    null
                );
            }
        }

        // Refunds bring stock back; cascade-refresh affected products.
        $itemIds = array_map(static fn($l) => (int)$l['Item_ID'], $recipe);
        $this->recomputeProductsForIngredients($itemIds);
    }

    /**
     * Append-only audit row for any change to supply_item.Stock_Quantity.
     * Silently no-ops if the log table doesn't exist (older deploys).
     */
    public function logMovement(
        int $itemId,
        float $delta,
        string $actionType,
        ?int $productId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $reason = null,
        ?string $notes = null
    ): void {
        if (!self::historyTableReady($this->conn)) {
            return;
        }

        // Read the new balance after the calling code has applied the delta.
        $balance = 0.0;
        $stmt = $this->conn->prepare('SELECT Stock_Quantity FROM supply_item WHERE Item_ID = ?');
        if ($stmt) {
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $balance = (float)($row['Stock_Quantity'] ?? 0);
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $userRole = isset($_SESSION['user_role']) ? (string)$_SESSION['user_role'] : null;
        if ($userRole !== null && $userRole === '') {
            $userRole = null;
        }

        $ins = $this->conn->prepare(
            'INSERT INTO supply_item_log
                (Item_ID, Product_ID, Action_Type, Quantity_Delta, Balance_After,
                 Reference_Type, Reference_ID, Reason, Notes, User_ID, User_Role)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$ins) {
            return;
        }
        $ins->bind_param(
            'iisddsissis',
            $itemId,
            $productId,
            $actionType,
            $delta,
            $balance,
            $referenceType,
            $referenceId,
            $reason,
            $notes,
            $userId,
            $userRole
        );
        $ins->execute();
        $ins->close();
    }

    public static function historyTableReady(\mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'supply_item_log'");
        return $result instanceof \mysqli_result && $result->num_rows > 0;
    }

    /**
     * Recompute product.Stock_Quantity + Low_Stock_Alert for every product
     * whose recipe references any of the given ingredient IDs.
     *
     * @param list<int> $itemIds
     */
    public function recomputeProductsForIngredients(array $itemIds): void
    {
        if (!self::recipeTableReady($this->conn)) {
            return;
        }
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return;
        }
        $csv = implode(',', $itemIds);
        $result = $this->conn->query(
            "SELECT DISTINCT Product_ID FROM product_recipe WHERE Item_ID IN ($csv)"
        );
        if (!$result) {
            return;
        }
        while ($row = $result->fetch_assoc()) {
            $this->recomputeProductStock((int)$row['Product_ID']);
        }
    }

    /**
     * Compute the maximum number of servings a product can produce given
     * current ingredient stock. Updates product.Stock_Quantity and
     * Low_Stock_Alert in one shot. No-op for products without a recipe
     * (they're hand-managed).
     *
     * Producible servings = floor( min over all recipe lines of
     *                              ingredient.Stock_Quantity / qty_per_serving ).
     *
     * Threshold mapping (matches the existing computeStockAlert in Owner.php):
     *   >= 20  Safe
     *   >= 10  Low
     *   >=  1  Critical
     *      0   Out of Stock
     */
    public function recomputeProductStock(int $productId): ?int
    {
        if ($productId <= 0 || !self::recipeTableReady($this->conn)) {
            return null;
        }

        $stmt = $this->conn->prepare(
            'SELECT pr.Quantity_Per_Serving, si.Stock_Quantity
             FROM product_recipe pr
             INNER JOIN supply_item si ON si.Item_ID = pr.Item_ID
             WHERE pr.Product_ID = ?'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        if ($rows === []) {
            // No recipe: skip. Manual stock management remains in effect.
            return null;
        }

        $maxServings = PHP_INT_MAX;
        foreach ($rows as $row) {
            $perServing = (float)$row['Quantity_Per_Serving'];
            $available  = (float)$row['Stock_Quantity'];
            if ($perServing <= 0) {
                continue;
            }
            $servings = (int)floor($available / $perServing);
            if ($servings < $maxServings) {
                $maxServings = $servings;
            }
        }
        if ($maxServings === PHP_INT_MAX) {
            $maxServings = 0;
        }
        $maxServings = max(0, $maxServings);

        if ($maxServings >= 20)      { $alert = 'Safe'; }
        elseif ($maxServings >= 10)  { $alert = 'Low'; }
        elseif ($maxServings >= 1)   { $alert = 'Critical'; }
        else                         { $alert = 'Out of Stock'; }

        $upd = $this->conn->prepare(
            'UPDATE product SET Stock_Quantity = ?, Low_Stock_Alert = ? WHERE Product_ID = ?'
        );
        if ($upd) {
            $upd->bind_param('isi', $maxServings, $alert, $productId);
            $upd->execute();
            $upd->close();
        }
        return $maxServings;
    }

    /**
     * One-shot bootstrap: recompute every product that has a recipe.
     * Useful right after the catalog migration to bring product stock in
     * line with what the ingredient pantry can actually produce.
     *
     * @return int Number of products updated.
     */
    public function recomputeAllProducts(): int
    {
        if (!self::recipeTableReady($this->conn)) {
            return 0;
        }
        $result = $this->conn->query('SELECT DISTINCT Product_ID FROM product_recipe');
        if (!$result) {
            return 0;
        }
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            if ($this->recomputeProductStock((int)$row['Product_ID']) !== null) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return array{
     *   low_stock_materials: list<array<string,mixed>>,
     *   low_stock_products: list<array<string,mixed>>,
     *   open_purchase_orders: int,
     *   pending_po_value: float
     * }
     */
    public function getStockAlerts(int $materialLimit = 8, int $productLimit = 8): array
    {
        $alerts = [
            'low_stock_materials' => [],
            'low_stock_products' => [],
            'open_purchase_orders' => 0,
            'pending_po_value' => 0.0,
        ];

        if (!self::baseTablesReady($this->conn)) {
            return $alerts;
        }

        try {
            $materialLimit = max(1, min(20, $materialLimit));
            $sql = "SELECT Item_ID, Item_Name, Category, Unit, Stock_Quantity, Reorder_Level, Unit_Cost
                    FROM supply_item
                    WHERE Status = 'Active' AND Stock_Quantity <= Reorder_Level
                    ORDER BY (Stock_Quantity / NULLIF(Reorder_Level, 0)) ASC, Item_Name ASC
                    LIMIT {$materialLimit}";
            $result = $this->conn->query($sql);
            if ($result) {
                $alerts['low_stock_materials'] = $result->fetch_all(MYSQLI_ASSOC) ?: [];
            }

            $open = $this->conn->query(
                "SELECT COUNT(*) AS c, COALESCE(SUM(Total_Amount),0) AS v FROM purchase_order WHERE Status IN ('Draft','Ordered','Partial')"
            );
            if ($open && ($row = $open->fetch_assoc())) {
                $alerts['open_purchase_orders'] = (int)($row['c'] ?? 0);
                $alerts['pending_po_value'] = (float)($row['v'] ?? 0);
            }

            $productLimit = max(1, min(20, $productLimit));
            $productSql = "SELECT Product_ID, Product_Name, Category, Stock_Quantity, Low_Stock_Alert
                             FROM product
                             WHERE Low_Stock_Alert IN ('Low','Critical','Out of Stock')
                             ORDER BY FIELD(Low_Stock_Alert, 'Out of Stock', 'Critical', 'Low'), Product_Name ASC
                             LIMIT {$productLimit}";
            $pResult = $this->conn->query($productSql);
            if ($pResult) {
                $alerts['low_stock_products'] = $pResult->fetch_all(MYSQLI_ASSOC) ?: [];
            }
        } catch (\Throwable $e) {
            // Missing tables or query failure — return empty alerts instead of breaking the dashboard
        }

        return $alerts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProductsForRecipes(): array
    {
        if (self::recipeTableReady($this->conn)) {
            $result = $this->conn->query(
                'SELECT p.Product_ID, p.Product_Name, p.Category,
                        (SELECT COUNT(*) FROM product_recipe pr WHERE pr.Product_ID = p.Product_ID) AS Recipe_Lines
                 FROM product p
                 ORDER BY p.Product_Name ASC'
            );
        } else {
            $result = $this->conn->query(
                'SELECT Product_ID, Product_Name, Category, 0 AS Recipe_Lines FROM product ORDER BY Product_Name ASC'
            );
        }
        if (!$result) {
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC) ?: [];
    }
}
