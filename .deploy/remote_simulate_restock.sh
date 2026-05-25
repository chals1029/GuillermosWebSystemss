#!/usr/bin/env bash
# Simulate stocking up enough ingredients to make 30 Hot Lattes, then verify
# that the product flips Out of Stock → Safe automatically.
set -e
APP_DIR=/var/www/guillermoscafe
_DEPLOY_DIR=$(dirname "${BASH_SOURCE[0]:-$0}"); . "$_DEPLOY_DIR/_loadsecrets.sh"; ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "$VPS_PASSWORD" > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

DB='u435394025_guillermos_db'

cat > /tmp/sim_restock.php <<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/Config.php';
require __DIR__ . '/Controllers/SupplyChainService.php';
global $conn;
$svc = new SupplyChainService($conn);

$pid = (int)$conn->query("SELECT Product_ID FROM product WHERE Product_Name='Hot Latte' LIMIT 1")->fetch_assoc()['Product_ID'];

echo "BEFORE restock:\n";
$row = $conn->query("SELECT Stock_Quantity, Low_Stock_Alert FROM product WHERE Product_ID=$pid")->fetch_assoc();
printf("  Hot Latte: %s servings (%s)\n", $row['Stock_Quantity'], $row['Low_Stock_Alert']);

// Stock up: bump each Hot Latte ingredient to 50x its qty per serving.
$res = $conn->query("
  SELECT pr.Item_ID, pr.Quantity_Per_Serving, si.Item_Name
  FROM product_recipe pr
  INNER JOIN supply_item si ON si.Item_ID = pr.Item_ID
  WHERE pr.Product_ID = $pid
");
$snapshots = [];
while ($r = $res->fetch_assoc()) {
    $iid = (int)$r['Item_ID'];
    $cur = (float)$conn->query("SELECT Stock_Quantity FROM supply_item WHERE Item_ID=$iid")->fetch_assoc()['Stock_Quantity'];
    $snapshots[$iid] = $cur;
    $target = 50 * (float)$r['Quantity_Per_Serving'];
    if ($cur < $target) {
        $delta = $target - $cur;
        $conn->query("UPDATE supply_item SET Stock_Quantity = Stock_Quantity + $delta WHERE Item_ID=$iid");
    }
}

// Trigger recompute the same way every controller path does.
$svc->recomputeProductsForIngredients(array_keys($snapshots));

echo "\nAFTER restock (target ~50 servings):\n";
$row = $conn->query("SELECT Stock_Quantity, Low_Stock_Alert FROM product WHERE Product_ID=$pid")->fetch_assoc();
printf("  Hot Latte: %s servings (%s)\n", $row['Stock_Quantity'], $row['Low_Stock_Alert']);

// Sell 45 servings to drive it back into Critical/Out of Stock territory
echo "\nSelling 45 servings...\n";
try {
    $svc->deductMaterialsForProduct($pid, 45.0, 999998);
    $row = $conn->query("SELECT Stock_Quantity, Low_Stock_Alert FROM product WHERE Product_ID=$pid")->fetch_assoc();
    printf("  Hot Latte after sale: %s servings (%s)\n", $row['Stock_Quantity'], $row['Low_Stock_Alert']);
} catch (\Throwable $e) {
    echo "  Sale rejected: " . $e->getMessage() . "\n";
}

echo "\nRolling back...\n";
foreach ($snapshots as $iid => $orig) {
    $stmt = $conn->prepare("UPDATE supply_item SET Stock_Quantity=? WHERE Item_ID=?");
    $stmt->bind_param('di', $orig, $iid);
    $stmt->execute();
    $stmt->close();
}
$conn->query("DELETE FROM supply_item_log WHERE Reference_Type='Order' AND Reference_ID=999998");
$svc->recomputeProductsForIngredients(array_keys($snapshots));

$row = $conn->query("SELECT Stock_Quantity, Low_Stock_Alert FROM product WHERE Product_ID=$pid")->fetch_assoc();
printf("Hot Latte after rollback: %s servings (%s)\n", $row['Stock_Quantity'], $row['Low_Stock_Alert']);
PHP

sudo -A install -o BeaBunda -g www-data -m 0640 /tmp/sim_restock.php "$APP_DIR/sim_restock.php"
sudo -A -u www-data php "$APP_DIR/sim_restock.php"
sudo -A rm -f "$APP_DIR/sim_restock.php" /tmp/sim_restock.php
