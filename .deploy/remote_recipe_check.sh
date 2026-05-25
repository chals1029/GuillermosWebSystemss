#!/usr/bin/env bash
set -e
DB='u435394025_guillermos_db'
_DEPLOY_DIR=$(dirname "${BASH_SOURCE[0]:-$0}"); . "$_DEPLOY_DIR/_loadsecrets.sh"; ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "$VPS_PASSWORD" > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT

echo '====== A. PRODUCTS — which have a recipe vs. which dont ======'
sudo -A mysql -uroot "$DB" --table -e "
  SELECT p.Product_ID, p.Product_Name, p.Category,
         COUNT(pr.Recipe_ID) AS Recipe_Lines,
         CASE WHEN COUNT(pr.Recipe_ID) > 0 THEN 'YES' ELSE '— (no auto-deduct)' END AS Has_Recipe
  FROM product p
  LEFT JOIN product_recipe pr ON pr.Product_ID = p.Product_ID
  GROUP BY p.Product_ID, p.Product_Name, p.Category
  ORDER BY Recipe_Lines DESC, p.Product_Name ASC;
"

echo
echo '====== B. RECIPE LINES — every product/ingredient combination ======'
sudo -A mysql -uroot "$DB" --table -e "
  SELECT p.Product_Name AS Product,
         si.Item_Name AS Ingredient,
         pr.Quantity_Per_Serving AS Qty_Per_Serving,
         si.Unit,
         si.Stock_Quantity AS Current_Stock,
         si.Reorder_Level
  FROM product_recipe pr
  INNER JOIN product p ON p.Product_ID = pr.Product_ID
  INNER JOIN supply_item si ON si.Item_ID = pr.Item_ID
  ORDER BY p.Product_Name, si.Item_Name;
"

echo
echo '====== C. INGREDIENTS — each one and how many products use it ======'
sudo -A mysql -uroot "$DB" --table -e "
  SELECT si.Item_ID,
         si.Item_Name AS Ingredient,
         si.Unit,
         si.Stock_Quantity AS Stock,
         si.Reorder_Level,
         COUNT(pr.Recipe_ID) AS Used_By_Products,
         GROUP_CONCAT(p.Product_Name ORDER BY p.Product_Name SEPARATOR ', ') AS Products
  FROM supply_item si
  LEFT JOIN product_recipe pr ON pr.Item_ID = si.Item_ID
  LEFT JOIN product p ON p.Product_ID = pr.Product_ID
  GROUP BY si.Item_ID, si.Item_Name, si.Unit, si.Stock_Quantity, si.Reorder_Level
  ORDER BY Used_By_Products DESC, si.Item_Name ASC;
"

echo
echo '====== D. SUPPLIERS in the database ======'
sudo -A mysql -uroot "$DB" --table -e "
  SELECT Supplier_ID, Supplier_Name, Status,
         (SELECT COUNT(*) FROM supply_item si WHERE si.Supplier_ID = s.Supplier_ID) AS Items_Linked
  FROM supplier s
  ORDER BY Supplier_Name;
"
