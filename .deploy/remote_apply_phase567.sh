#!/usr/bin/env bash
set -euo pipefail
DB='u435394025_guillermos_db'
APP_DIR='/var/www/guillermoscafe'

ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' 'Drake24Charles' > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

echo '== Pulling latest code =='
cd "$APP_DIR"
sudo -A git fetch --all --prune
sudo -A git reset --hard origin/main
sudo -A chown -R BeaBunda:www-data "$APP_DIR"
sudo -A find "$APP_DIR" -type d -exec chmod 2775 {} +
sudo -A find "$APP_DIR" -type f -exec chmod 0664 {} +

echo
echo '== Phase 5: ingredient catalog =='
sudo -A mysql -uroot "$DB" < "$APP_DIR/sql/supply_chain_phase5_full_catalog.sql"

echo
echo '== Phase 7: history table =='
sudo -A mysql -uroot "$DB" < "$APP_DIR/sql/supply_chain_phase7_history.sql"

echo
echo '== Phase 6: recipes (with unmatched-row warnings) =='
sudo -A mysql -uroot "$DB" --table < "$APP_DIR/sql/supply_chain_phase6_recipes.sql"

echo
echo '== Verify ingredient + recipe coverage =='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT 'Suppliers'    AS metric, COUNT(*) AS count FROM supplier
  UNION ALL SELECT 'Active suppliers', COUNT(*) FROM supplier WHERE Status='Active'
  UNION ALL SELECT 'Supply items',     COUNT(*) FROM supply_item
  UNION ALL SELECT 'Active items',     COUNT(*) FROM supply_item WHERE Status='Active'
  UNION ALL SELECT 'Products',         COUNT(*) FROM product
  UNION ALL SELECT 'Products w/ recipe', COUNT(DISTINCT Product_ID) FROM product_recipe
  UNION ALL SELECT 'Recipe lines',     COUNT(*) FROM product_recipe
  UNION ALL SELECT 'Log table rows',   COUNT(*) FROM supply_item_log;
"

echo
echo '== Products STILL without a recipe (should be small or zero) =='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT p.Product_ID, p.Product_Name, p.Category
  FROM product p
  LEFT JOIN product_recipe pr ON pr.Product_ID = p.Product_ID
  WHERE pr.Recipe_ID IS NULL
  ORDER BY p.Category, p.Product_Name;
"

echo
echo '== Reload Apache (clear PHP opcache) =='
sudo -A systemctl reload apache2
echo 'done.'
