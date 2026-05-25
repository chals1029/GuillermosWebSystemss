#!/usr/bin/env bash
# Apply phase 8 (opening stock seed) and recompute every product so the menu
# stops being entirely Out of Stock.
set -euo pipefail
APP_DIR=/var/www/guillermoscafe
DB='u435394025_guillermos_db'

_DEPLOY_DIR=$(dirname "${BASH_SOURCE[0]:-$0}"); . "$_DEPLOY_DIR/_loadsecrets.sh"; ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "$VPS_PASSWORD" > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

echo '== Pull latest code =='
cd "$APP_DIR"
sudo -A git fetch --all --prune
sudo -A git reset --hard origin/main

echo
echo '== Apply phase 8 seed =='
sudo -A mysql -uroot "$DB" < "$APP_DIR/sql/supply_chain_phase8_seed_initial_stock.sql"

echo
echo '== Recompute all product stock from current ingredient stock =='
cat > /tmp/rec.php <<'PHP'
<?php
require __DIR__ . '/Config.php';
require __DIR__ . '/Controllers/SupplyChainService.php';
global $conn;
$svc = new SupplyChainService($conn);
echo "Recomputed: " . $svc->recomputeAllProducts() . " product(s)\n";
PHP
sudo -A install -o BeaBunda -g www-data -m 0640 /tmp/rec.php "$APP_DIR/rec.php"
sudo -A -u www-data php "$APP_DIR/rec.php"
sudo -A rm -f "$APP_DIR/rec.php" /tmp/rec.php

echo
echo '== Status summary (should mostly be Safe / Low) =='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT Low_Stock_Alert, COUNT(*) AS Products
  FROM product
  GROUP BY Low_Stock_Alert
  ORDER BY FIELD(Low_Stock_Alert, 'Out of Stock','Critical','Low','Safe');
"

echo
echo '== Anything still Out of Stock (and why) =='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT p.Product_ID, p.Product_Name, p.Stock_Quantity AS Producible,
         GROUP_CONCAT(CONCAT(si.Item_Name, '=', si.Stock_Quantity, ' ', si.Unit) ORDER BY si.Item_Name SEPARATOR ' | ') AS Bottlenecks
  FROM product p
  INNER JOIN product_recipe pr ON pr.Product_ID = p.Product_ID
  INNER JOIN supply_item si    ON si.Item_ID    = pr.Item_ID
  WHERE p.Low_Stock_Alert = 'Out of Stock'
    AND si.Stock_Quantity < pr.Quantity_Per_Serving
  GROUP BY p.Product_ID, p.Product_Name, p.Stock_Quantity
  LIMIT 30;
"
