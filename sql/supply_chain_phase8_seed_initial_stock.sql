-- =============================================================================
-- Phase 8: Seed sensible starting stock for the new ingredient catalog.
--
-- Strategy: only fill items that currently sit at 0 (i.e. the ones added by
-- phase 5). Items you already had stock for keep their real counts.
-- Each seed value is roughly enough to support ~30 servings of the heaviest
-- product that uses it — comfortably "Safe" on the dashboard until you do
-- your real first count.
-- =============================================================================

UPDATE supply_item SET Stock_Quantity = CASE Item_Name
  -- Meats & seafood (kg unless noted)
  WHEN 'Whole Eggs'                 THEN 120     -- pcs
  WHEN 'Beef Sirloin'               THEN 8
  WHEN 'Ground Beef'                THEN 10
  WHEN 'Pork Belly'                 THEN 8
  WHEN 'Bacon Strips'               THEN 5
  WHEN 'Hungarian Sausage'          THEN 8
  WHEN 'Ham Slices'                 THEN 5
  WHEN 'Chicken Breast'             THEN 8
  WHEN 'Chicken Adobo (cooked)'     THEN 5
  WHEN 'Mixed Seafood'              THEN 6
  WHEN 'Shrimp (peeled)'            THEN 5
  WHEN 'Tuyo Fillets'               THEN 3

  -- Produce
  WHEN 'Garlic'                     THEN 4
  WHEN 'White Onion'                THEN 6
  WHEN 'Tomato'                     THEN 6
  WHEN 'Lettuce (Iceberg)'          THEN 5
  WHEN 'Cucumber'                   THEN 4
  WHEN 'Lemon'                      THEN 5
  WHEN 'Ginger'                     THEN 2
  WHEN 'Mango'                      THEN 8
  WHEN 'Pineapple'                  THEN 5
  WHEN 'Banana (saba/lakatan)'      THEN 5
  WHEN 'Mushrooms (button)'         THEN 3
  WHEN 'Mixed Berries'              THEN 3
  WHEN 'Walnuts (chopped)'          THEN 3
  WHEN 'Carrot'                     THEN 4

  -- Dairy & cheese
  WHEN 'Mozzarella Cheese'          THEN 8
  WHEN 'Cheddar Cheese'             THEN 6
  WHEN 'Cream Cheese'               THEN 5
  WHEN 'Plain Yogurt'               THEN 8     -- L
  WHEN 'Yakult'                     THEN 60    -- pcs
  WHEN 'Condensed Milk'             THEN 24    -- can

  -- Baking & pantry
  WHEN 'Cooking Oil'                THEN 10
  WHEN 'Salt'                       THEN 3
  WHEN 'Black Pepper (ground)'      THEN 1.5
  WHEN 'Yeast (instant)'            THEN 1.5
  WHEN 'Brown Sugar'                THEN 10
  WHEN 'Cocoa Powder'               THEN 3
  WHEN 'Vanilla Extract'            THEN 1.5
  WHEN 'Baking Powder'              THEN 1.5
  WHEN 'Cinnamon (ground)'          THEN 1.5
  WHEN 'Yema Mix'                   THEN 3
  WHEN 'Spaghetti / Penne Pasta'    THEN 12
  WHEN 'White Rice (cooked)'        THEN 15
  WHEN 'Burger Buns'                THEN 60
  WHEN 'Bread Slices (loaf)'        THEN 120
  WHEN 'Pizza Dough Ball (250g)'    THEN 40
  WHEN 'Marinara Sauce'             THEN 8     -- L
  WHEN 'Pesto Sauce'                THEN 4     -- L
  WHEN 'Chocolate Chips'            THEN 3
  WHEN 'Tortilla Chips'             THEN 5
  WHEN 'Cookie Crumbs'              THEN 3
  WHEN 'Hopia Filling'              THEN 3

  -- Beverage syrups, powders, tea
  WHEN 'Vanilla Syrup'              THEN 6     -- bottle
  WHEN 'Caramel Syrup'              THEN 6
  WHEN 'Salted Caramel Syrup'       THEN 5
  WHEN 'White Mocha Syrup'          THEN 5
  WHEN 'Wintermelon Syrup'          THEN 5
  WHEN 'Biscoff Spread'             THEN 5
  WHEN 'Tapioca Pearls (cooked)'    THEN 6     -- kg
  WHEN 'Black Tea Bags'             THEN 200   -- pcs

  -- Packaging
  WHEN '12oz Paper Cups'            THEN 400
  WHEN '22oz Plastic Cups'          THEN 400
  WHEN 'Paper Straws'               THEN 600

  ELSE Stock_Quantity
END
WHERE Stock_Quantity = 0
  AND Item_Name IN (
    'Whole Eggs','Beef Sirloin','Ground Beef','Pork Belly','Bacon Strips','Hungarian Sausage',
    'Ham Slices','Chicken Breast','Chicken Adobo (cooked)','Mixed Seafood','Shrimp (peeled)','Tuyo Fillets',
    'Garlic','White Onion','Tomato','Lettuce (Iceberg)','Cucumber','Lemon','Ginger','Mango','Pineapple',
    'Banana (saba/lakatan)','Mushrooms (button)','Mixed Berries','Walnuts (chopped)','Carrot',
    'Mozzarella Cheese','Cheddar Cheese','Cream Cheese','Plain Yogurt','Yakult','Condensed Milk',
    'Cooking Oil','Salt','Black Pepper (ground)','Yeast (instant)','Brown Sugar','Cocoa Powder',
    'Vanilla Extract','Baking Powder','Cinnamon (ground)','Yema Mix','Spaghetti / Penne Pasta',
    'White Rice (cooked)','Burger Buns','Bread Slices (loaf)','Pizza Dough Ball (250g)',
    'Marinara Sauce','Pesto Sauce','Chocolate Chips','Tortilla Chips','Cookie Crumbs','Hopia Filling',
    'Vanilla Syrup','Caramel Syrup','Salted Caramel Syrup','White Mocha Syrup','Wintermelon Syrup',
    'Biscoff Spread','Tapioca Pearls (cooked)','Black Tea Bags',
    '12oz Paper Cups','22oz Plastic Cups','Paper Straws'
  );

-- Also write each seed as an audit row so the history reflects this opening count.
INSERT INTO supply_item_log
  (Item_ID, Action_Type, Quantity_Delta, Balance_After, Reason, Notes)
SELECT Item_ID, 'Adjust', Stock_Quantity, Stock_Quantity, 'received', 'Phase 8 opening count seed'
FROM supply_item
WHERE Item_Name IN (
    'Whole Eggs','Beef Sirloin','Ground Beef','Pork Belly','Bacon Strips','Hungarian Sausage',
    'Ham Slices','Chicken Breast','Chicken Adobo (cooked)','Mixed Seafood','Shrimp (peeled)','Tuyo Fillets',
    'Garlic','White Onion','Tomato','Lettuce (Iceberg)','Cucumber','Lemon','Ginger','Mango','Pineapple',
    'Banana (saba/lakatan)','Mushrooms (button)','Mixed Berries','Walnuts (chopped)','Carrot',
    'Mozzarella Cheese','Cheddar Cheese','Cream Cheese','Plain Yogurt','Yakult','Condensed Milk',
    'Cooking Oil','Salt','Black Pepper (ground)','Yeast (instant)','Brown Sugar','Cocoa Powder',
    'Vanilla Extract','Baking Powder','Cinnamon (ground)','Yema Mix','Spaghetti / Penne Pasta',
    'White Rice (cooked)','Burger Buns','Bread Slices (loaf)','Pizza Dough Ball (250g)',
    'Marinara Sauce','Pesto Sauce','Chocolate Chips','Tortilla Chips','Cookie Crumbs','Hopia Filling',
    'Vanilla Syrup','Caramel Syrup','Salted Caramel Syrup','White Mocha Syrup','Wintermelon Syrup',
    'Biscoff Spread','Tapioca Pearls (cooked)','Black Tea Bags',
    '12oz Paper Cups','22oz Plastic Cups','Paper Straws'
  )
  AND Stock_Quantity > 0
  AND NOT EXISTS (
    SELECT 1 FROM supply_item_log sil
    WHERE sil.Item_ID = supply_item.Item_ID
      AND sil.Notes  = 'Phase 8 opening count seed'
  );
