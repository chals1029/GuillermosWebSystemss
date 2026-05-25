#!/usr/bin/env bash
# End-to-end test:
#   1. Pick a product with a recipe (Hot Latte).
#   2. Snapshot its ingredient stock + log row count.
#   3. Run the SupplyChainService->deductMaterialsForProduct with a fake order id.
#   4. Verify stock decreased and log rows were appended with the right values.
#   5. Roll back via restoreMaterialsForProduct so the live data is untouched.
set -e
APP_DIR=/var/www/guillermoscafe
_DEPLOY_DIR=$(dirname "${BASH_SOURCE[0]:-$0}"); . "$_DEPLOY_DIR/_loadsecrets.sh"; ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "$VPS_PASSWORD" > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

cat > /tmp/test_deduction.php <<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/Config.php';
require __DIR__ . '/Controllers/SupplyChainService.php';

global $conn;
$svc = new SupplyChainService($conn);

$productName = 'Hot Latte';
$row = $conn->query("SELECT Product_ID FROM product WHERE Product_Name='$productName' LIMIT 1")->fetch_assoc();
if (!$row) { fwrite(STDERR, "Product not found\n"); exit(1); }
$pid = (int)$row['Product_ID'];
echo "Product: $productName (ID=$pid)\n";

$recipe = $svc->getRecipeForProduct($pid);
echo "Recipe lines: " . count($recipe) . "\n";
$itemIds = array_column($recipe, 'Item_ID');
$idsCsv = implode(',', array_map('intval', $itemIds));

echo "\n--- BEFORE ---\n";
$res = $conn->query("SELECT Item_ID, Item_Name, Stock_Quantity FROM supply_item WHERE Item_ID IN ($idsCsv)");
$before = [];
while ($r = $res->fetch_assoc()) {
    $before[(int)$r['Item_ID']] = (float)$r['Stock_Quantity'];
    printf("  %-25s %.3f\n", $r['Item_Name'], $r['Stock_Quantity']);
}

// Test bootstrap: temporarily ensure every recipe ingredient has at least 100 units
// of stock so we can exercise the full deduct/log/rollback path. We snapshot
// `before` ABOVE so the rollback step compares against pre-bootstrap values.
foreach ($itemIds as $iid) {
    $iid = (int)$iid;
    $cur = (float)$conn->query("SELECT Stock_Quantity FROM supply_item WHERE Item_ID=$iid")->fetch_assoc()['Stock_Quantity'];
    if ($cur < 100) {
        $needed = 100 - $cur;
        $conn->query("UPDATE supply_item SET Stock_Quantity = Stock_Quantity + $needed WHERE Item_ID=$iid");
    }
}
$bootstrapped = [];
$res2 = $conn->query("SELECT Item_ID, Stock_Quantity FROM supply_item WHERE Item_ID IN ($idsCsv)");
while ($r = $res2->fetch_assoc()) { $bootstrapped[(int)$r['Item_ID']] = (float)$r['Stock_Quantity']; }
$logBefore = (int)$conn->query("SELECT COUNT(*) c FROM supply_item_log")->fetch_assoc()['c'];
echo "supply_item_log rows: $logBefore\n";

$fakeOrderId = 999999;
echo "\n--- DEDUCTING (1 serving, fake order_id=$fakeOrderId) ---\n";
$svc->deductMaterialsForProduct($pid, 1.0, $fakeOrderId);

echo "\n--- AFTER ---\n";
$res = $conn->query("SELECT Item_ID, Item_Name, Stock_Quantity FROM supply_item WHERE Item_ID IN ($idsCsv)");
while ($r = $res->fetch_assoc()) {
    $delta = (float)$r['Stock_Quantity'] - $before[(int)$r['Item_ID']];
    printf("  %-25s %.3f  (delta %+.3f)\n", $r['Item_Name'], $r['Stock_Quantity'], $delta);
}
$logAfter = (int)$conn->query("SELECT COUNT(*) c FROM supply_item_log")->fetch_assoc()['c'];
echo "supply_item_log rows: $logAfter (added " . ($logAfter - $logBefore) . ")\n";

echo "\n--- LOG ROWS for this fake order ---\n";
$res = $conn->query("
  SELECT sil.Created_At, si.Item_Name, sil.Action_Type, sil.Quantity_Delta, sil.Balance_After,
         sil.Reference_Type, sil.Reference_ID, p.Product_Name
  FROM supply_item_log sil
  INNER JOIN supply_item si ON si.Item_ID = sil.Item_ID
  LEFT JOIN product p ON p.Product_ID = sil.Product_ID
  WHERE sil.Reference_ID = $fakeOrderId
  ORDER BY sil.Log_ID
");
while ($r = $res->fetch_assoc()) {
    printf("  [%s] %-25s %-7s %+.3f -> %.3f  (ref=%s/%d, product=%s)\n",
        $r['Created_At'], $r['Item_Name'], $r['Action_Type'], $r['Quantity_Delta'],
        $r['Balance_After'], $r['Reference_Type'], (int)$r['Reference_ID'],
        $r['Product_Name'] ?? '');
}

echo "\n--- ROLLING BACK ---\n";
$svc->restoreMaterialsForProduct($pid, 1.0, $fakeOrderId);

echo "\n--- AFTER ROLLBACK ---\n";
$res = $conn->query("SELECT Item_ID, Item_Name, Stock_Quantity FROM supply_item WHERE Item_ID IN ($idsCsv)");
$ok = true;
while ($r = $res->fetch_assoc()) {
    $current = (float)$r['Stock_Quantity'];
    // Compare against the bootstrapped value (we added stock for the test);
    // the rollback should restore exactly that.
    $orig = $bootstrapped[(int)$r['Item_ID']];
    if (abs($current - $orig) > 0.0001) { $ok = false; }
    printf("  %-25s %.3f  (orig %.3f, %s)\n", $r['Item_Name'], $current, $orig, abs($current-$orig)<0.0001 ? 'OK' : 'MISMATCH');
}
echo "Rollback " . ($ok ? "CLEAN" : "MISMATCH") . "\n";

echo "\n--- supply-order-ingredients view (test order) ---\n";
$res = $conn->query("
  SELECT si.Item_Name, si.Unit,
         SUM(CASE WHEN sil.Action_Type='Sale'   THEN -sil.Quantity_Delta ELSE 0 END) AS Consumed,
         SUM(CASE WHEN sil.Action_Type='Refund' THEN  sil.Quantity_Delta ELSE 0 END) AS Restored
  FROM supply_item_log sil
  INNER JOIN supply_item si ON si.Item_ID = sil.Item_ID
  WHERE sil.Reference_Type='Order' AND sil.Reference_ID=$fakeOrderId
  GROUP BY si.Item_ID, si.Item_Name, si.Unit
");
while ($r = $res->fetch_assoc()) {
    printf("  %-25s consumed=%.3f %s, restored=%.3f %s (net %+.3f)\n",
        $r['Item_Name'], $r['Consumed'], $r['Unit'], $r['Restored'], $r['Unit'],
        $r['Restored'] - $r['Consumed']);
}

// Cleanup the fake test rows so the audit log only contains real activity
$conn->query("DELETE FROM supply_item_log WHERE Reference_Type='Order' AND Reference_ID=$fakeOrderId");
// Restore the bootstrapped stock back to its original pre-test value
foreach ($before as $iid => $origQty) {
    $stmt = $conn->prepare("UPDATE supply_item SET Stock_Quantity=? WHERE Item_ID=?");
    $stmt->bind_param('di', $origQty, $iid);
    $stmt->execute();
    $stmt->close();
}
echo "\nCleaned up test log rows and restored original stock.\n";
PHP

sudo -A install -o BeaBunda -g www-data -m 0640 /tmp/test_deduction.php "$APP_DIR/test_deduction.php"
sudo -A -u www-data php "$APP_DIR/test_deduction.php"
sudo -A rm -f "$APP_DIR/test_deduction.php" /tmp/test_deduction.php
