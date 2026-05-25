#!/usr/bin/env bash
# Pull the latest code on the VPS and run a one-shot recompute over every
# product so the menu reflects what current ingredient stock can produce.
set -e
APP_DIR=/var/www/guillermoscafe
ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' 'Drake24Charles' > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

echo '== Pull code =='
cd "$APP_DIR"
sudo -A git fetch --all --prune
sudo -A git reset --hard origin/main
sudo -A chown -R BeaBunda:www-data "$APP_DIR"
sudo -A find "$APP_DIR" -type d -exec chmod 2775 {} +
sudo -A find "$APP_DIR" -type f -exec chmod 0664 {} +
sudo -A systemctl reload apache2

echo '== Run recompute =='
cat > /tmp/recompute.php <<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/Config.php';
require __DIR__ . '/Controllers/SupplyChainService.php';
global $conn;
$svc = new SupplyChainService($conn);
$count = $svc->recomputeAllProducts();
echo "Recomputed: $count product(s)\n";
PHP

sudo -A install -o BeaBunda -g www-data -m 0640 /tmp/recompute.php "$APP_DIR/recompute.php"
sudo -A -u www-data php "$APP_DIR/recompute.php"
sudo -A rm -f "$APP_DIR/recompute.php" /tmp/recompute.php

echo
echo '== Show before/after summary =='
DB='u435394025_guillermos_db'
sudo -A mysql -uroot "$DB" --table -e "
  SELECT Low_Stock_Alert, COUNT(*) AS Products
  FROM product
  GROUP BY Low_Stock_Alert
  ORDER BY FIELD(Low_Stock_Alert, 'Out of Stock', 'Critical', 'Low', 'Safe');
"
echo
echo '== A few examples =='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT p.Product_ID, p.Product_Name, p.Stock_Quantity AS Producible, p.Low_Stock_Alert AS Status,
         (SELECT MIN(FLOOR(si.Stock_Quantity / pr.Quantity_Per_Serving))
          FROM product_recipe pr
          INNER JOIN supply_item si ON si.Item_ID = pr.Item_ID
          WHERE pr.Product_ID = p.Product_ID) AS Computed
  FROM product p
  WHERE EXISTS (SELECT 1 FROM product_recipe pr WHERE pr.Product_ID = p.Product_ID)
  ORDER BY p.Stock_Quantity DESC
  LIMIT 15;
"
