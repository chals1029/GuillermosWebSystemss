#!/usr/bin/env bash
set -e
DB='u435394025_guillermos_db'
_DEPLOY_DIR=$(dirname "${BASH_SOURCE[0]:-$0}"); . "$_DEPLOY_DIR/_loadsecrets.sh"; ASKPASS=$(mktemp); printf '#!/usr/bin/env bash\necho %q\n' "$VPS_PASSWORD" > "$ASKPASS"; chmod 700 "$ASKPASS"
export SUDO_ASKPASS="$ASKPASS"
trap 'rm -f "$ASKPASS"' EXIT
sudo -A mysql -uroot "$DB" --table -e "
SELECT p.Product_Name, p.Stock_Quantity AS Producible, p.Low_Stock_Alert
FROM product p
WHERE p.Product_Name='All Cheese Pizza';

SELECT si.Item_Name, pr.Quantity_Per_Serving, si.Stock_Quantity AS InStock,
       FLOOR(si.Stock_Quantity / pr.Quantity_Per_Serving) AS Servings_From_This_Item
FROM product p
JOIN product_recipe pr ON pr.Product_ID = p.Product_ID
JOIN supply_item si ON si.Item_ID = pr.Item_ID
WHERE p.Product_Name='All Cheese Pizza'
ORDER BY Servings_From_This_Item;
"
