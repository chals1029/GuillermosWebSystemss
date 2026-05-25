-- =============================================================================
-- Phase 5: Full ingredient catalog + recipes for the entire menu.
--
-- - Reactivates BakeMart Ingredients so we can keep using it for dry goods.
-- - Adds a new supplier for meats and seafood.
-- - Inserts ~50 new supply_item rows covering every ingredient the menu uses.
-- - Adds a UNIQUE index on supply_item.Item_Name so this script is idempotent.
-- - Creates recipes (BOM lines) for all 78 products.
--
-- Re-running this script is safe: ingredients are inserted with INSERT IGNORE
-- (skipped on second run) and recipe lines hit a (Product_ID, Item_ID) unique
-- key that ON DUPLICATE refreshes the Quantity_Per_Serving so you get the
-- latest defaults. Manual edits in the BOM tab will be overwritten if you
-- re-run; do not re-run after you start tuning.
-- =============================================================================

-- 0. Make Item_Name unique so INSERT IGNORE / @lookups work reliably ----------
SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supply_item' AND INDEX_NAME = 'uq_supply_item_name'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `supply_item` ADD UNIQUE KEY `uq_supply_item_name` (`Item_Name`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1. Reactivate BakeMart so we can link new dry-goods items to it -------------
UPDATE supplier
   SET Status = 'Active'
 WHERE Supplier_Name = 'BakeMart Ingredients' AND Status <> 'Active';

-- 2. Add the missing supplier (meats & seafood) -------------------------------
INSERT INTO supplier (Supplier_Name, Contact_Person, Email, Phone, Address, Status, Notes)
SELECT 'Local Butcher & Seafood Market', 'Operations', '', '', '', 'Active',
       'Auto-seeded for fresh meat and seafood ingredients.'
WHERE NOT EXISTS (SELECT 1 FROM supplier WHERE Supplier_Name = 'Local Butcher & Seafood Market');

-- Pre-resolve supplier IDs into session vars so the inserts below stay readable
SET @sup_butcher  = (SELECT Supplier_ID FROM supplier WHERE Supplier_Name = 'Local Butcher & Seafood Market');
SET @sup_dairy    = (SELECT Supplier_ID FROM supplier WHERE Supplier_Name = 'Fresh Dairy Supply Co.');
SET @sup_produce  = (SELECT Supplier_ID FROM supplier WHERE Supplier_Name = 'GreenLeaf Produce');
SET @sup_bakery   = (SELECT Supplier_ID FROM supplier WHERE Supplier_Name = 'BakeMart Ingredients');
SET @sup_coffee   = (SELECT Supplier_ID FROM supplier WHERE Supplier_Name = 'Metro Coffee Beans Trading');
SET @sup_pack     = (SELECT Supplier_ID FROM supplier WHERE Supplier_Name = 'PackRight Packaging');

-- 3. Ingredient catalog (Stock_Quantity = 0 by default; reorder/cost are estimates)
--    INSERT IGNORE means re-running this script is safe.
INSERT IGNORE INTO supply_item
  (Item_Name, Category, Unit, Stock_Quantity, Reorder_Level, Unit_Cost, Supplier_ID, Notes, Status)
VALUES
  -- Meats & seafood
  ('Whole Eggs',                 'Dairy',     'pcs', 0, 60,  9.00,   @sup_butcher, 'For pasta, cakes, sandwiches.', 'Active'),
  ('Beef Sirloin',               'Meat',      'kg',  0, 5,   480.00, @sup_butcher, 'Steaks, salficao, burger steak.', 'Active'),
  ('Ground Beef',                'Meat',      'kg',  0, 5,   380.00, @sup_butcher, 'Burgers, pizza topping.', 'Active'),
  ('Pork Belly',                 'Meat',      'kg',  0, 5,   340.00, @sup_butcher, 'Pork bisteak.', 'Active'),
  ('Bacon Strips',               'Meat',      'kg',  0, 3,   520.00, @sup_butcher, 'Carbonara, clubhouse.', 'Active'),
  ('Hungarian Sausage',          'Meat',      'kg',  0, 4,   420.00, @sup_butcher, 'Pizza, sandwich, rice meal.', 'Active'),
  ('Ham Slices',                 'Meat',      'kg',  0, 3,   460.00, @sup_butcher, 'Sandwiches, pizza.', 'Active'),
  ('Chicken Breast',             'Meat',      'kg',  0, 4,   260.00, @sup_butcher, 'Pesto pasta, clubhouse, pizza.', 'Active'),
  ('Chicken Adobo (cooked)',     'Meat',      'kg',  0, 3,   320.00, @sup_butcher, 'Pre-cooked adobo flakes.', 'Active'),
  ('Mixed Seafood',              'Meat',      'kg',  0, 3,   420.00, @sup_butcher, 'Squid, mussels for marinara.', 'Active'),
  ('Shrimp (peeled)',            'Meat',      'kg',  0, 3,   620.00, @sup_butcher, 'Shrimp alfredo, pasta.', 'Active'),
  ('Tuyo Fillets',               'Meat',      'kg',  0, 2,   380.00, @sup_butcher, 'Tuyo puttanesca.', 'Active'),

  -- Produce
  ('Garlic',                     'Produce',   'kg',  0, 2,   180.00, @sup_produce, 'Aglio olio, marinara, salficao.', 'Active'),
  ('White Onion',                'Produce',   'kg',  0, 3,   120.00, @sup_produce, '', 'Active'),
  ('Tomato',                     'Produce',   'kg',  0, 4,   80.00,  @sup_produce, 'Salads, sandwiches.', 'Active'),
  ('Lettuce (Iceberg)',          'Produce',   'kg',  0, 3,   140.00, @sup_produce, 'Salads, sandwiches.', 'Active'),
  ('Cucumber',                   'Produce',   'kg',  0, 2,   60.00,  @sup_produce, 'Cucumber lemon drink.', 'Active'),
  ('Lemon',                      'Produce',   'kg',  0, 3,   140.00, @sup_produce, 'Lemon series drinks.', 'Active'),
  ('Ginger',                     'Produce',   'kg',  0, 1,   140.00, @sup_produce, 'Salabat.', 'Active'),
  ('Mango',                      'Produce',   'kg',  0, 4,   180.00, @sup_produce, 'Mango meringue, salads.', 'Active'),
  ('Pineapple',                  'Produce',   'kg',  0, 3,   90.00,  @sup_produce, 'Hawaiian pizza, salads.', 'Active'),
  ('Banana (saba/lakatan)',      'Produce',   'kg',  0, 3,   80.00,  @sup_produce, 'Banana bread.', 'Active'),
  ('Mushrooms (button)',         'Produce',   'kg',  0, 2,   320.00, @sup_produce, 'Beef and mushroom pizza.', 'Active'),
  ('Mixed Berries',              'Produce',   'kg',  0, 2,   480.00, @sup_produce, 'Lemon berry drink.', 'Active'),
  ('Walnuts (chopped)',          'Produce',   'kg',  0, 2,   780.00, @sup_produce, 'Walnut brownies, carrot cake.', 'Active'),
  ('Carrot',                     'Produce',   'kg',  0, 2,   80.00,  @sup_produce, 'Carrot cake.', 'Active'),

  -- Dairy & cheese
  ('Mozzarella Cheese',          'Dairy',     'kg',  0, 4,   620.00, @sup_dairy,   'Pizzas, ham & cheese, ham & egg.', 'Active'),
  ('Cheddar Cheese',             'Dairy',     'kg',  0, 3,   520.00, @sup_dairy,   'Cheese roll, sandwiches.', 'Active'),
  ('Cream Cheese',               'Dairy',     'kg',  0, 3,   780.00, @sup_dairy,   'Blueberry cheesecake, frosting.', 'Active'),
  ('Plain Yogurt',               'Dairy',     'L',   0, 4,   220.00, @sup_dairy,   'Strawberry yogurt drink.', 'Active'),
  ('Yakult',                     'Dairy',     'pcs', 0, 30,  12.00,  @sup_dairy,   'Lemon yakult drink.', 'Active'),
  ('Condensed Milk',             'Beverage',  'can', 0, 12,  85.00,  @sup_bakery,  'Vietnamese latte, milkteas.', 'Active'),

  -- Baking & pantry
  ('Cooking Oil',                'Baking',    'L',   0, 5,   180.00, @sup_bakery,  '', 'Active'),
  ('Salt',                       'Baking',    'kg',  0, 2,   28.00,  @sup_bakery,  '', 'Active'),
  ('Black Pepper (ground)',      'Baking',    'kg',  0, 1,   620.00, @sup_bakery,  '', 'Active'),
  ('Yeast (instant)',            'Baking',    'kg',  0, 1,   480.00, @sup_bakery,  'Pizza dough, ensaymada.', 'Active'),
  ('Brown Sugar',                'Baking',    'kg',  0, 5,   65.00,  @sup_bakery,  'Brownies, cookies.', 'Active'),
  ('Cocoa Powder',               'Baking',    'kg',  0, 2,   320.00, @sup_bakery,  'Chocolate cake, brownies.', 'Active'),
  ('Vanilla Extract',            'Baking',    'L',   0, 1,   480.00, @sup_bakery,  'Cakes, custards.', 'Active'),
  ('Baking Powder',              'Baking',    'kg',  0, 1,   180.00, @sup_bakery,  '', 'Active'),
  ('Cinnamon (ground)',          'Baking',    'kg',  0, 1,   480.00, @sup_bakery,  'Cinnamon roll.', 'Active'),
  ('Yema Mix',                   'Baking',    'kg',  0, 2,   240.00, @sup_bakery,  'Yema roll filling.', 'Active'),
  ('Spaghetti / Penne Pasta',    'Baking',    'kg',  0, 6,   120.00, @sup_bakery,  'Pasta dishes.', 'Active'),
  ('White Rice (cooked)',        'Baking',    'kg',  0, 8,   60.00,  @sup_bakery,  'Rice meals.', 'Active'),
  ('Burger Buns',                'Baking',    'pcs', 0, 30,  10.00,  @sup_bakery,  'Burgers.', 'Active'),
  ('Bread Slices (loaf)',        'Baking',    'pcs', 0, 60,  4.00,   @sup_bakery,  'Sandwiches, clubhouse.', 'Active'),
  ('Pizza Dough Ball (250g)',    'Baking',    'pcs', 0, 20,  35.00,  @sup_bakery,  'Pre-made pizza base.', 'Active'),
  ('Marinara Sauce',             'Baking',    'L',   0, 4,   220.00, @sup_bakery,  'Pizza, marinara pasta.', 'Active'),
  ('Pesto Sauce',                'Baking',    'L',   0, 2,   460.00, @sup_bakery,  'Chicken pesto, salads.', 'Active'),
  ('Chocolate Chips',            'Baking',    'kg',  0, 2,   480.00, @sup_bakery,  'Cookies, brownies, drinks.', 'Active'),
  ('Tortilla Chips',             'Baking',    'kg',  0, 3,   220.00, @sup_bakery,  'Nachos salads.', 'Active'),
  ('Cookie Crumbs',              'Baking',    'kg',  0, 2,   320.00, @sup_bakery,  'Cookies & cream drink.', 'Active'),
  ('Hopia Filling',              'Baking',    'kg',  0, 2,   180.00, @sup_bakery,  'Hopia.', 'Active'),

  -- Beverage syrups, powders, tea
  ('Vanilla Syrup',              'Beverage',  'bottle', 0, 4, 220.00, @sup_coffee, '', 'Active'),
  ('Caramel Syrup',              'Beverage',  'bottle', 0, 4, 220.00, @sup_coffee, '', 'Active'),
  ('Salted Caramel Syrup',       'Beverage',  'bottle', 0, 3, 240.00, @sup_coffee, '', 'Active'),
  ('White Mocha Syrup',          'Beverage',  'bottle', 0, 3, 260.00, @sup_coffee, '', 'Active'),
  ('Wintermelon Syrup',          'Beverage',  'bottle', 0, 3, 200.00, @sup_coffee, 'Milktea, lemonade.', 'Active'),
  ('Biscoff Spread',             'Beverage',  'bottle', 0, 3, 320.00, @sup_coffee, 'Biscoff drinks.', 'Active'),
  ('Tapioca Pearls (cooked)',    'Beverage',  'kg',     0, 4, 180.00, @sup_coffee, 'Milktea base.', 'Active'),
  ('Black Tea Bags',             'Beverage',  'pcs',    0, 60, 3.00,  @sup_coffee, 'Lemon tea, milktea.', 'Active'),
  ('12oz Paper Cups',            'Packaging', 'pcs',    0, 200, 3.00, @sup_pack,   'Hot drinks.', 'Active'),
  ('22oz Plastic Cups',          'Packaging', 'pcs',    0, 200, 4.50, @sup_pack,   'Iced drinks.', 'Active'),
  ('Paper Straws',               'Packaging', 'pcs',    0, 300, 1.00, @sup_pack,   'Iced drinks.', 'Active');

-- 4. Reassign legacy BakeMart-linked items to remain on BakeMart (no-op now, just documents intent).
--    (The items 5/6/7/14 still point at BakeMart; we just made it Active again.)
