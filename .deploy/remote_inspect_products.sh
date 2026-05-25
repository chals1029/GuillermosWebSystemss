#!/usr/bin/env bash
set -e
DB='u435394025_guillermos_db'
ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' 'Drake24Charles' > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

echo '=== A. product table schema ==='
sudo -A mysql -uroot "$DB" --table -e "DESCRIBE product;"

echo
echo '=== B. inventory_log schema (existing history?) ==='
sudo -A mysql -uroot "$DB" --table -e "DESCRIBE inventory_log;"
echo '--- inventory_log row count ---'
sudo -A mysql -uroot "$DB" -N -e "SELECT COUNT(*) FROM inventory_log;"
echo '--- inventory_log sample (last 10) ---'
sudo -A mysql -uroot "$DB" --table -e "SELECT * FROM inventory_log ORDER BY 1 DESC LIMIT 10;"

echo
echo '=== C. order_detail schema (where deduction is triggered) ==='
sudo -A mysql -uroot "$DB" --table -e "DESCRIBE order_detail;"
echo '--- order_detail row count ---'
sudo -A mysql -uroot "$DB" -N -e "SELECT COUNT(*) FROM order_detail;"

echo
echo '=== D. supply_item full data (current stock + supplier) ==='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT si.Item_ID, si.Item_Name, si.Category, si.Unit, si.Stock_Quantity, si.Reorder_Level, si.Unit_Cost,
         s.Supplier_Name AS Supplier
  FROM supply_item si
  LEFT JOIN supplier s ON s.Supplier_ID = si.Supplier_ID
  ORDER BY si.Category, si.Item_Name;"

echo
echo '=== E. all products with full detail ==='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT Product_ID, Product_Name, Category, Price, Stock_Quantity, Low_Stock_Alert
  FROM product
  ORDER BY Category, Product_Name;"

echo
echo '=== F. product table — does it have a Size column or anything beverage-specific? ==='
sudo -A mysql -uroot "$DB" --table -e "
  SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='product'
  ORDER BY ORDINAL_POSITION;"
