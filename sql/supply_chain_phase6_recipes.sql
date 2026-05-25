-- =============================================================================
-- Phase 6: Recipes (BOM) for every product in the menu.
--
-- Standard cafe portion sizes used as defaults — tune in the BOM tab as
-- needed. Each (Product_ID, Item_ID) pair is unique, so re-running this
-- script refreshes the Quantity_Per_Serving without duplicating rows.
-- =============================================================================

-- Helper: a small staging table storing Product_Name + Item_Name + Qty per serving.
-- Using a regular (non-temporary) table because we reference it multiple times
-- in the same SELECT (MySQL doesn't allow that for TEMPORARY tables).
DROP TABLE IF EXISTS _recipe_seed;
CREATE TABLE _recipe_seed (
  Product_Name VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  Item_Name    VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  Qty          DECIMAL(10,3) NOT NULL,
  Notes        VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO _recipe_seed (Product_Name, Item_Name, Qty, Notes) VALUES
-- ============= PASTA (8 oz portion = ~100g pasta dry) =============
('Carbonara',        'Spaghetti / Penne Pasta', 0.100, NULL),
('Carbonara',        'Bacon Strips',            0.060, NULL),
('Carbonara',        'Whole Eggs',              1.000, NULL),
('Carbonara',        'Full Cream Milk',         0.060, NULL),
('Carbonara',        'Whipping Cream',          0.040, NULL),
('Carbonara',        'Cheddar Cheese',          0.030, NULL),
('Carbonara',        'Garlic',                  0.005, NULL),
('Carbonara',        'Salt',                    0.002, NULL),
('Carbonara',        'Black Pepper (ground)',   0.001, NULL),
('Carbonara',        'Takeout Box (Medium)',    1.000, 'For takeout orders'),

('Spaghetti',        'Spaghetti / Penne Pasta', 0.100, NULL),
('Spaghetti',        'Marinara Sauce',          0.120, NULL),
('Spaghetti',        'Ground Beef',             0.060, NULL),
('Spaghetti',        'Cheddar Cheese',          0.020, NULL),
('Spaghetti',        'Garlic',                  0.005, NULL),
('Spaghetti',        'Takeout Box (Medium)',    1.000, NULL),

('Seafood Marinara', 'Spaghetti / Penne Pasta', 0.100, NULL),
('Seafood Marinara', 'Marinara Sauce',          0.120, NULL),
('Seafood Marinara', 'Mixed Seafood',           0.100, NULL),
('Seafood Marinara', 'Garlic',                  0.005, NULL),
('Seafood Marinara', 'Takeout Box (Medium)',    1.000, NULL),

('Seafood Aglio Olio','Spaghetti / Penne Pasta',0.100, NULL),
('Seafood Aglio Olio','Cooking Oil',            0.030, NULL),
('Seafood Aglio Olio','Mixed Seafood',          0.100, NULL),
('Seafood Aglio Olio','Garlic',                 0.010, NULL),
('Seafood Aglio Olio','Takeout Box (Medium)',   1.000, NULL),

('Shrimp Alfredo',   'Spaghetti / Penne Pasta', 0.100, NULL),
('Shrimp Alfredo',   'Shrimp (peeled)',         0.090, NULL),
('Shrimp Alfredo',   'Whipping Cream',          0.080, NULL),
('Shrimp Alfredo',   'Full Cream Milk',         0.060, NULL),
('Shrimp Alfredo',   'Cheddar Cheese',          0.030, NULL),
('Shrimp Alfredo',   'Garlic',                  0.005, NULL),
('Shrimp Alfredo',   'Takeout Box (Medium)',    1.000, NULL),

('Chicken Pesto',    'Spaghetti / Penne Pasta', 0.100, NULL),
('Chicken Pesto',    'Pesto Sauce',             0.060, NULL),
('Chicken Pesto',    'Chicken Breast',          0.090, NULL),
('Chicken Pesto',    'Cheddar Cheese',          0.020, NULL),
('Chicken Pesto',    'Takeout Box (Medium)',    1.000, NULL),

('Tuyo Puttanesca',  'Spaghetti / Penne Pasta', 0.100, NULL),
('Tuyo Puttanesca',  'Tuyo Fillets',            0.060, NULL),
('Tuyo Puttanesca',  'Marinara Sauce',          0.100, NULL),
('Tuyo Puttanesca',  'Garlic',                  0.008, NULL),
('Tuyo Puttanesca',  'Cooking Oil',             0.020, NULL),
('Tuyo Puttanesca',  'Takeout Box (Medium)',    1.000, NULL),

-- ============= RICE MEALS =============
('Beef Salficao',          'White Rice (cooked)', 0.250, NULL),
('Beef Salficao',          'Beef Sirloin',        0.120, NULL),
('Beef Salficao',          'Garlic',              0.008, NULL),
('Beef Salficao',          'White Onion',         0.020, NULL),
('Beef Salficao',          'Cooking Oil',         0.020, NULL),
('Beef Salficao',          'Takeout Box (Medium)',1.000, NULL),

('Beef Burger Steak',      'White Rice (cooked)', 0.250, NULL),
('Beef Burger Steak',      'Ground Beef',         0.120, NULL),
('Beef Burger Steak',      'Whole Eggs',          1.000, NULL),
('Beef Burger Steak',      'White Onion',         0.020, NULL),
('Beef Burger Steak',      'Takeout Box (Medium)',1.000, NULL),

('Pork Bisteak',           'White Rice (cooked)', 0.250, NULL),
('Pork Bisteak',           'Pork Belly',          0.130, NULL),
('Pork Bisteak',           'White Onion',         0.025, NULL),
('Pork Bisteak',           'Cooking Oil',         0.020, NULL),
('Pork Bisteak',           'Takeout Box (Medium)',1.000, NULL),

('Chicken Adobo Flakes',   'White Rice (cooked)',     0.250, NULL),
('Chicken Adobo Flakes',   'Chicken Adobo (cooked)',  0.120, NULL),
('Chicken Adobo Flakes',   'Garlic',                  0.005, NULL),
('Chicken Adobo Flakes',   'Cooking Oil',             0.015, NULL),
('Chicken Adobo Flakes',   'Takeout Box (Medium)',    1.000, NULL),

('Hungarian Sausage',      'White Rice (cooked)', 0.250, 'Rice meal version'),
('Hungarian Sausage',      'Hungarian Sausage',   0.150, NULL),
('Hungarian Sausage',      'Whole Eggs',          1.000, NULL),
('Hungarian Sausage',      'Takeout Box (Medium)',1.000, NULL),

('Seafood',                'White Rice (cooked)', 0.250, NULL),
('Seafood',                'Mixed Seafood',       0.140, NULL),
('Seafood',                'Garlic',              0.005, NULL),
('Seafood',                'Cooking Oil',         0.020, NULL),
('Seafood',                'Takeout Box (Medium)',1.000, NULL);


-- ============= SANDWICHES & SALADS =============
INSERT INTO _recipe_seed (Product_Name, Item_Name, Qty, Notes) VALUES
('Beef Burger',         'Burger Buns',            1.000, NULL),
('Beef Burger',         'Ground Beef',            0.120, NULL),
('Beef Burger',         'Cheddar Cheese',         0.020, NULL),
('Beef Burger',         'Lettuce (Iceberg)',      0.020, NULL),
('Beef Burger',         'Tomato',                 0.020, NULL),
('Beef Burger',         'Takeout Box (Medium)',   1.000, NULL),

('Clubhouse Sandwich',  'Bread Slices (loaf)',    3.000, NULL),
('Clubhouse Sandwich',  'Chicken Breast',         0.080, NULL),
('Clubhouse Sandwich',  'Bacon Strips',           0.030, NULL),
('Clubhouse Sandwich',  'Ham Slices',             0.030, NULL),
('Clubhouse Sandwich',  'Cheddar Cheese',         0.020, NULL),
('Clubhouse Sandwich',  'Lettuce (Iceberg)',      0.020, NULL),
('Clubhouse Sandwich',  'Tomato',                 0.020, NULL),
('Clubhouse Sandwich',  'Whole Eggs',             1.000, NULL),
('Clubhouse Sandwich',  'Takeout Box (Medium)',   1.000, NULL),

('Ham & Egg Sandwich',  'Bread Slices (loaf)',    2.000, NULL),
('Ham & Egg Sandwich',  'Ham Slices',             0.040, NULL),
('Ham & Egg Sandwich',  'Whole Eggs',             1.000, NULL),
('Ham & Egg Sandwich',  'Mozzarella Cheese',      0.020, NULL),
('Ham & Egg Sandwich',  'Takeout Box (Medium)',   1.000, NULL),

('Hungarian Sausage',   'Bread Slices (loaf)',    2.000, 'Sandwich version'),
('Hungarian Sausage',   'Hungarian Sausage',      0.120, NULL),
('Hungarian Sausage',   'Mozzarella Cheese',      0.020, NULL),
('Hungarian Sausage',   'Lettuce (Iceberg)',      0.015, NULL),
('Hungarian Sausage',   'Takeout Box (Medium)',   1.000, NULL),

('Green Salads',        'Lettuce (Iceberg)',      0.080, NULL),
('Green Salads',        'Tomato',                 0.040, NULL),
('Green Salads',        'Cucumber',               0.030, NULL),
('Green Salads',        'Pesto Sauce',            0.020, NULL),
('Green Salads',        'Takeout Box (Medium)',   1.000, NULL),

('Nachos Salads',       'Tortilla Chips',         0.080, NULL),
('Nachos Salads',       'Ground Beef',            0.060, NULL),
('Nachos Salads',       'Cheddar Cheese',         0.040, NULL),
('Nachos Salads',       'Tomato',                 0.030, NULL),
('Nachos Salads',       'Takeout Box (Medium)',   1.000, NULL),

-- ============= PIZZAS (one personal pizza ~ 9 in) =============
('All Cheese Pizza',     'Pizza Dough Ball (250g)',1.000, NULL),
('All Cheese Pizza',     'Marinara Sauce',         0.080, NULL),
('All Cheese Pizza',     'Mozzarella Cheese',      0.150, NULL),
('All Cheese Pizza',     'Cheddar Cheese',         0.050, NULL),
('All Cheese Pizza',     'Cream Cheese',           0.040, NULL),
('All Cheese Pizza',     'Takeout Box (Medium)',   1.000, NULL),

('Beef & Mushroom',      'Pizza Dough Ball (250g)',1.000, NULL),
('Beef & Mushroom',      'Marinara Sauce',         0.080, NULL),
('Beef & Mushroom',      'Mozzarella Cheese',      0.140, NULL),
('Beef & Mushroom',      'Ground Beef',            0.080, NULL),
('Beef & Mushroom',      'Mushrooms (button)',     0.060, NULL),
('Beef & Mushroom',      'Takeout Box (Medium)',   1.000, NULL),

('Cheesy Chicken Pizza', 'Pizza Dough Ball (250g)',1.000, NULL),
('Cheesy Chicken Pizza', 'Marinara Sauce',         0.080, NULL),
('Cheesy Chicken Pizza', 'Mozzarella Cheese',      0.150, NULL),
('Cheesy Chicken Pizza', 'Cheddar Cheese',         0.040, NULL),
('Cheesy Chicken Pizza', 'Chicken Breast',         0.080, NULL),
('Cheesy Chicken Pizza', 'Takeout Box (Medium)',   1.000, NULL),

('Guillermo''s Pizza',   'Pizza Dough Ball (250g)',1.000, NULL),
('Guillermo''s Pizza',   'Marinara Sauce',         0.080, NULL),
('Guillermo''s Pizza',   'Mozzarella Cheese',      0.150, NULL),
('Guillermo''s Pizza',   'Hungarian Sausage',      0.060, NULL),
('Guillermo''s Pizza',   'Bacon Strips',           0.030, NULL),
('Guillermo''s Pizza',   'Mushrooms (button)',     0.040, NULL),
('Guillermo''s Pizza',   'Takeout Box (Medium)',   1.000, NULL),

('Ham & Cheese',         'Pizza Dough Ball (250g)',1.000, NULL),
('Ham & Cheese',         'Marinara Sauce',         0.080, NULL),
('Ham & Cheese',         'Mozzarella Cheese',      0.150, NULL),
('Ham & Cheese',         'Ham Slices',             0.060, NULL),
('Ham & Cheese',         'Takeout Box (Medium)',   1.000, NULL),

('Hawaiian Pizza',       'Pizza Dough Ball (250g)',1.000, NULL),
('Hawaiian Pizza',       'Marinara Sauce',         0.080, NULL),
('Hawaiian Pizza',       'Mozzarella Cheese',      0.140, NULL),
('Hawaiian Pizza',       'Ham Slices',             0.050, NULL),
('Hawaiian Pizza',       'Pineapple',              0.060, NULL),
('Hawaiian Pizza',       'Takeout Box (Medium)',   1.000, NULL),

('Hungarian Sausage',    'Pizza Dough Ball (250g)',1.000, 'Pizza version'),
('Hungarian Sausage',    'Marinara Sauce',         0.080, NULL),
('Hungarian Sausage',    'Mozzarella Cheese',      0.140, NULL),
('Hungarian Sausage',    'Hungarian Sausage',      0.080, NULL),
('Hungarian Sausage',    'Takeout Box (Medium)',   1.000, NULL);


-- ============= CAKES (whole cake recipe — divide if you sell by slice) =======
INSERT INTO _recipe_seed (Product_Name, Item_Name, Qty, Notes) VALUES
('Blueberry Cheesecake', 'All-Purpose Flour',     0.250, 'Crust'),
('Blueberry Cheesecake', 'Granulated Sugar',      0.300, NULL),
('Blueberry Cheesecake', 'Cream Cheese',          0.500, NULL),
('Blueberry Cheesecake', 'Whipping Cream',        0.250, NULL),
('Blueberry Cheesecake', 'Whole Eggs',            4.000, NULL),
('Blueberry Cheesecake', 'Mixed Berries',         0.200, NULL),
('Blueberry Cheesecake', 'Unsalted Butter',       0.150, NULL),

('Boiled Icing',         'Granulated Sugar',      0.400, NULL),
('Boiled Icing',         'Whole Eggs',            3.000, NULL),
('Boiled Icing',         'Vanilla Extract',       0.005, NULL),

('Carrot Cake',          'All-Purpose Flour',     0.350, NULL),
('Carrot Cake',          'Granulated Sugar',      0.300, NULL),
('Carrot Cake',          'Carrot',                0.300, NULL),
('Carrot Cake',          'Whole Eggs',            4.000, NULL),
('Carrot Cake',          'Cooking Oil',           0.200, NULL),
('Carrot Cake',          'Cinnamon (ground)',     0.005, NULL),
('Carrot Cake',          'Walnuts (chopped)',     0.080, NULL),
('Carrot Cake',          'Cream Cheese',          0.250, 'Frosting'),

('Chocolate Cake',       'All-Purpose Flour',     0.350, NULL),
('Chocolate Cake',       'Granulated Sugar',      0.300, NULL),
('Chocolate Cake',       'Cocoa Powder',          0.080, NULL),
('Chocolate Cake',       'Whole Eggs',            6.000, NULL),
('Chocolate Cake',       'Full Cream Milk',       0.300, NULL),
('Chocolate Cake',       'Unsalted Butter',       0.200, NULL),
('Chocolate Cake',       'Chocolate Syrup',       0.500, 'Bottle fraction'),

('Fruits & Cream Cake',  'All-Purpose Flour',     0.300, NULL),
('Fruits & Cream Cake',  'Granulated Sugar',      0.250, NULL),
('Fruits & Cream Cake',  'Whole Eggs',            5.000, NULL),
('Fruits & Cream Cake',  'Whipping Cream',        0.400, NULL),
('Fruits & Cream Cake',  'Mixed Berries',         0.150, NULL),
('Fruits & Cream Cake',  'Mango',                 0.150, NULL),

('Mango Meringue',       'All-Purpose Flour',     0.250, NULL),
('Mango Meringue',       'Granulated Sugar',      0.300, NULL),
('Mango Meringue',       'Whole Eggs',            6.000, NULL),
('Mango Meringue',       'Mango',                 0.350, NULL),
('Mango Meringue',       'Whipping Cream',        0.250, NULL),

('Sans Rival Cake',      'All-Purpose Flour',     0.200, NULL),
('Sans Rival Cake',      'Granulated Sugar',      0.300, NULL),
('Sans Rival Cake',      'Whole Eggs',            6.000, NULL),
('Sans Rival Cake',      'Unsalted Butter',       0.300, NULL),
('Sans Rival Cake',      'Walnuts (chopped)',     0.150, NULL),

-- ============= BREADS =============
('Banana Bread',         'All-Purpose Flour',     0.220, NULL),
('Banana Bread',         'Granulated Sugar',      0.150, NULL),
('Banana Bread',         'Whole Eggs',            2.000, NULL),
('Banana Bread',         'Banana (saba/lakatan)', 0.300, NULL),
('Banana Bread',         'Cooking Oil',           0.080, NULL),
('Banana Bread',         'Baking Powder',         0.005, NULL),

('Cheese Roll',          'All-Purpose Flour',     0.060, NULL),
('Cheese Roll',          'Granulated Sugar',      0.020, NULL),
('Cheese Roll',          'Cheddar Cheese',        0.030, NULL),
('Cheese Roll',          'Whole Eggs',            0.500, NULL),
('Cheese Roll',          'Unsalted Butter',       0.015, NULL),

('Cinnamon Roll',        'All-Purpose Flour',     0.080, NULL),
('Cinnamon Roll',        'Granulated Sugar',      0.025, NULL),
('Cinnamon Roll',        'Cinnamon (ground)',     0.003, NULL),
('Cinnamon Roll',        'Yeast (instant)',       0.002, NULL),
('Cinnamon Roll',        'Unsalted Butter',       0.020, NULL),

('Ensaymada',            'All-Purpose Flour',     0.080, NULL),
('Ensaymada',            'Granulated Sugar',      0.025, NULL),
('Ensaymada',            'Cheddar Cheese',        0.020, NULL),
('Ensaymada',            'Unsalted Butter',       0.025, NULL),
('Ensaymada',            'Whole Eggs',            0.500, NULL),
('Ensaymada',            'Yeast (instant)',       0.002, NULL),

('Yema Roll',            'All-Purpose Flour',     0.060, NULL),
('Yema Roll',            'Yema Mix',              0.030, NULL),
('Yema Roll',            'Whole Eggs',            0.500, NULL),
('Yema Roll',            'Unsalted Butter',       0.015, NULL),

-- ============= PIE / COOKIES / BARS =============
('Caramel Brownies',     'All-Purpose Flour',     0.150, NULL),
('Caramel Brownies',     'Brown Sugar',           0.180, NULL),
('Caramel Brownies',     'Cocoa Powder',          0.040, NULL),
('Caramel Brownies',     'Whole Eggs',            3.000, NULL),
('Caramel Brownies',     'Unsalted Butter',       0.150, NULL),
('Caramel Brownies',     'Caramel Syrup',         0.250, 'Bottle fraction'),

('Walnut Brownies',      'All-Purpose Flour',     0.150, NULL),
('Walnut Brownies',      'Brown Sugar',           0.180, NULL),
('Walnut Brownies',      'Cocoa Powder',          0.040, NULL),
('Walnut Brownies',      'Whole Eggs',            3.000, NULL),
('Walnut Brownies',      'Unsalted Butter',       0.150, NULL),
('Walnut Brownies',      'Walnuts (chopped)',     0.100, NULL),

('Classic Bakery Hopia', 'All-Purpose Flour',     0.060, NULL),
('Classic Bakery Hopia', 'Hopia Filling',         0.040, NULL),
('Classic Bakery Hopia', 'Cooking Oil',           0.015, NULL),

('Classic Chocolate Chip Cookies', 'All-Purpose Flour', 0.150, NULL),
('Classic Chocolate Chip Cookies', 'Brown Sugar',       0.120, NULL),
('Classic Chocolate Chip Cookies', 'Granulated Sugar',  0.060, NULL),
('Classic Chocolate Chip Cookies', 'Whole Eggs',        2.000, NULL),
('Classic Chocolate Chip Cookies', 'Unsalted Butter',   0.120, NULL),
('Classic Chocolate Chip Cookies', 'Chocolate Chips',   0.150, NULL),

('Revel Bar (White or Dark Chocolate)', 'All-Purpose Flour', 0.140, NULL),
('Revel Bar (White or Dark Chocolate)', 'Brown Sugar',       0.140, NULL),
('Revel Bar (White or Dark Chocolate)', 'Whole Eggs',        2.000, NULL),
('Revel Bar (White or Dark Chocolate)', 'Unsalted Butter',   0.120, NULL),
('Revel Bar (White or Dark Chocolate)', 'Chocolate Chips',   0.180, NULL);


-- ============= COFFEE BEVERAGES — HOT (12oz cup base) =====================
INSERT INTO _recipe_seed (Product_Name, Item_Name, Qty, Notes) VALUES
('Hot Americano',       'Arabica Coffee Beans', 0.018, 'Double shot'),
('Hot Americano',       '12oz Paper Cups',      1.000, NULL),

('Hot Latte',           'Arabica Coffee Beans', 0.018, NULL),
('Hot Latte',           'Full Cream Milk',      0.180, NULL),
('Hot Latte',           '12oz Paper Cups',      1.000, NULL),

('Hot Spanish Latte',   'Arabica Coffee Beans', 0.018, NULL),
('Hot Spanish Latte',   'Full Cream Milk',      0.150, NULL),
('Hot Spanish Latte',   'Condensed Milk',       0.040, NULL),
('Hot Spanish Latte',   '12oz Paper Cups',      1.000, NULL),

('Hot Caramel',         'Arabica Coffee Beans', 0.018, NULL),
('Hot Caramel',         'Full Cream Milk',      0.170, NULL),
('Hot Caramel',         'Caramel Syrup',        0.030, NULL),
('Hot Caramel',         '12oz Paper Cups',      1.000, NULL),

('Hot Salted Caramel',  'Arabica Coffee Beans',  0.018, NULL),
('Hot Salted Caramel',  'Full Cream Milk',       0.170, NULL),
('Hot Salted Caramel',  'Salted Caramel Syrup',  0.030, NULL),
('Hot Salted Caramel',  '12oz Paper Cups',       1.000, NULL),

('Hot White Mocha',     'Arabica Coffee Beans', 0.018, NULL),
('Hot White Mocha',     'Full Cream Milk',      0.170, NULL),
('Hot White Mocha',     'White Mocha Syrup',    0.030, NULL),
('Hot White Mocha',     '12oz Paper Cups',      1.000, NULL),

('Hot Dark Mocha',      'Arabica Coffee Beans', 0.018, NULL),
('Hot Dark Mocha',      'Full Cream Milk',      0.170, NULL),
('Hot Dark Mocha',      'Chocolate Syrup',      0.030, NULL),
('Hot Dark Mocha',      '12oz Paper Cups',      1.000, NULL),

('Hot Matcha',          'Matcha Powder',        4.000, NULL),
('Hot Matcha',          'Full Cream Milk',      0.180, NULL),
('Hot Matcha',          'Granulated Sugar',     0.010, NULL),
('Hot Matcha',          '12oz Paper Cups',      1.000, NULL),

('Hot Vanilla',         'Arabica Coffee Beans', 0.018, NULL),
('Hot Vanilla',         'Full Cream Milk',      0.170, NULL),
('Hot Vanilla',         'Vanilla Syrup',        0.030, NULL),
('Hot Vanilla',         '12oz Paper Cups',      1.000, NULL),

('Hot Breeve',          'Arabica Coffee Beans', 0.018, NULL),
('Hot Breeve',          'Full Cream Milk',      0.090, NULL),
('Hot Breeve',          'Whipping Cream',       0.080, NULL),
('Hot Breeve',          '12oz Paper Cups',      1.000, NULL),

('Hot Biscoff',         'Arabica Coffee Beans', 0.018, NULL),
('Hot Biscoff',         'Full Cream Milk',      0.170, NULL),
('Hot Biscoff',         'Biscoff Spread',       0.025, NULL),
('Hot Biscoff',         '12oz Paper Cups',      1.000, NULL),

('Hot Vietnamese Latte','Robusta Coffee Beans', 0.018, NULL),
('Hot Vietnamese Latte','Condensed Milk',       0.060, NULL),
('Hot Vietnamese Latte','12oz Paper Cups',      1.000, NULL),

-- ============= COFFEE BEVERAGES — ICED (22oz cup base) ====================
('Iced Americano',       'Arabica Coffee Beans', 0.018, NULL),
('Iced Americano',       '22oz Plastic Cups',    1.000, NULL),
('Iced Americano',       'Plastic Dome Lids',    1.000, NULL),
('Iced Americano',       'Paper Straws',         1.000, NULL),

('Iced Latte',           'Arabica Coffee Beans', 0.018, NULL),
('Iced Latte',           'Full Cream Milk',      0.220, NULL),
('Iced Latte',           '22oz Plastic Cups',    1.000, NULL),
('Iced Latte',           'Plastic Dome Lids',    1.000, NULL),
('Iced Latte',           'Paper Straws',         1.000, NULL),

('Iced Spanish Latte',   'Arabica Coffee Beans', 0.018, NULL),
('Iced Spanish Latte',   'Full Cream Milk',      0.180, NULL),
('Iced Spanish Latte',   'Condensed Milk',       0.045, NULL),
('Iced Spanish Latte',   '22oz Plastic Cups',    1.000, NULL),
('Iced Spanish Latte',   'Plastic Dome Lids',    1.000, NULL),
('Iced Spanish Latte',   'Paper Straws',         1.000, NULL),

('Iced Caramel',         'Arabica Coffee Beans', 0.018, NULL),
('Iced Caramel',         'Full Cream Milk',      0.200, NULL),
('Iced Caramel',         'Caramel Syrup',        0.030, NULL),
('Iced Caramel',         '22oz Plastic Cups',    1.000, NULL),
('Iced Caramel',         'Plastic Dome Lids',    1.000, NULL),
('Iced Caramel',         'Paper Straws',         1.000, NULL),

('Iced Salted Caramel',  'Arabica Coffee Beans', 0.018, NULL),
('Iced Salted Caramel',  'Full Cream Milk',      0.200, NULL),
('Iced Salted Caramel',  'Salted Caramel Syrup', 0.030, NULL),
('Iced Salted Caramel',  '22oz Plastic Cups',    1.000, NULL),
('Iced Salted Caramel',  'Plastic Dome Lids',    1.000, NULL),
('Iced Salted Caramel',  'Paper Straws',         1.000, NULL),

('Iced White Mocha',     'Arabica Coffee Beans', 0.018, NULL),
('Iced White Mocha',     'Full Cream Milk',      0.200, NULL),
('Iced White Mocha',     'White Mocha Syrup',    0.030, NULL),
('Iced White Mocha',     '22oz Plastic Cups',    1.000, NULL),
('Iced White Mocha',     'Plastic Dome Lids',    1.000, NULL),
('Iced White Mocha',     'Paper Straws',         1.000, NULL),

('Iced Dark Mocha',      'Arabica Coffee Beans', 0.018, NULL),
('Iced Dark Mocha',      'Full Cream Milk',      0.200, NULL),
('Iced Dark Mocha',      'Chocolate Syrup',      0.030, NULL),
('Iced Dark Mocha',      '22oz Plastic Cups',    1.000, NULL),
('Iced Dark Mocha',      'Plastic Dome Lids',    1.000, NULL),
('Iced Dark Mocha',      'Paper Straws',         1.000, NULL),

('Iced Matcha',          'Matcha Powder',        5.000, NULL),
('Iced Matcha',          'Full Cream Milk',      0.220, NULL),
('Iced Matcha',          'Granulated Sugar',     0.012, NULL),
('Iced Matcha',          '22oz Plastic Cups',    1.000, NULL),
('Iced Matcha',          'Plastic Dome Lids',    1.000, NULL),
('Iced Matcha',          'Paper Straws',         1.000, NULL),

('Iced Vanilla',         'Arabica Coffee Beans', 0.018, NULL),
('Iced Vanilla',         'Full Cream Milk',      0.200, NULL),
('Iced Vanilla',         'Vanilla Syrup',        0.030, NULL),
('Iced Vanilla',         '22oz Plastic Cups',    1.000, NULL),
('Iced Vanilla',         'Plastic Dome Lids',    1.000, NULL),
('Iced Vanilla',         'Paper Straws',         1.000, NULL),

('Iced Breeve',          'Arabica Coffee Beans', 0.018, NULL),
('Iced Breeve',          'Full Cream Milk',      0.110, NULL),
('Iced Breeve',          'Whipping Cream',       0.090, NULL),
('Iced Breeve',          '22oz Plastic Cups',    1.000, NULL),
('Iced Breeve',          'Plastic Dome Lids',    1.000, NULL),
('Iced Breeve',          'Paper Straws',         1.000, NULL),

('Iced Biscoff',         'Arabica Coffee Beans', 0.018, NULL),
('Iced Biscoff',         'Full Cream Milk',      0.200, NULL),
('Iced Biscoff',         'Biscoff Spread',       0.030, NULL),
('Iced Biscoff',         '22oz Plastic Cups',    1.000, NULL),
('Iced Biscoff',         'Plastic Dome Lids',    1.000, NULL),
('Iced Biscoff',         'Paper Straws',         1.000, NULL),

('Iced Vietnamese Latte','Robusta Coffee Beans', 0.018, NULL),
('Iced Vietnamese Latte','Condensed Milk',       0.070, NULL),
('Iced Vietnamese Latte','22oz Plastic Cups',    1.000, NULL),
('Iced Vietnamese Latte','Plastic Dome Lids',    1.000, NULL),
('Iced Vietnamese Latte','Paper Straws',         1.000, NULL),

-- ============= NON-COFFEE =================================================
('Iced Cookies & Cream',         'Cookie Crumbs',     0.030, NULL),
('Iced Cookies & Cream',         'Full Cream Milk',   0.220, NULL),
('Iced Cookies & Cream',         'Whipping Cream',    0.030, NULL),
('Iced Cookies & Cream',         'Granulated Sugar',  0.015, NULL),
('Iced Cookies & Cream',         '22oz Plastic Cups', 1.000, NULL),
('Iced Cookies & Cream',         'Plastic Dome Lids', 1.000, NULL),
('Iced Cookies & Cream',         'Paper Straws',      1.000, NULL),

('Iced Double Chocolate Latte',  'Chocolate Syrup',   0.040, NULL),
('Iced Double Chocolate Latte',  'Cocoa Powder',      0.010, NULL),
('Iced Double Chocolate Latte',  'Full Cream Milk',   0.220, NULL),
('Iced Double Chocolate Latte',  '22oz Plastic Cups', 1.000, NULL),
('Iced Double Chocolate Latte',  'Plastic Dome Lids', 1.000, NULL),
('Iced Double Chocolate Latte',  'Paper Straws',      1.000, NULL),

('Iced Matcha Latte',            'Matcha Powder',     5.000, NULL),
('Iced Matcha Latte',            'Full Cream Milk',   0.180, NULL),
('Iced Matcha Latte',            'Fresh Milk (Oat)',  0.040, NULL),
('Iced Matcha Latte',            'Granulated Sugar',  0.012, NULL),
('Iced Matcha Latte',            '22oz Plastic Cups', 1.000, NULL),
('Iced Matcha Latte',            'Plastic Dome Lids', 1.000, NULL),
('Iced Matcha Latte',            'Paper Straws',      1.000, NULL),

-- ============= MILKTEA ====================================================
('Iced Wintermelon Milktea',     'Wintermelon Syrup', 0.040, NULL),
('Iced Wintermelon Milktea',     'Full Cream Milk',   0.180, NULL),
('Iced Wintermelon Milktea',     'Tapioca Pearls (cooked)', 0.040, NULL),
('Iced Wintermelon Milktea',     'Granulated Sugar',  0.015, NULL),
('Iced Wintermelon Milktea',     '22oz Plastic Cups', 1.000, NULL),
('Iced Wintermelon Milktea',     'Plastic Dome Lids', 1.000, NULL),
('Iced Wintermelon Milktea',     'Paper Straws',      1.000, NULL),

('Iced Salted Caramel Milktea',  'Salted Caramel Syrup', 0.040, NULL),
('Iced Salted Caramel Milktea',  'Black Tea Bags',       1.000, NULL),
('Iced Salted Caramel Milktea',  'Full Cream Milk',      0.180, NULL),
('Iced Salted Caramel Milktea',  'Tapioca Pearls (cooked)', 0.040, NULL),
('Iced Salted Caramel Milktea',  '22oz Plastic Cups',    1.000, NULL),
('Iced Salted Caramel Milktea',  'Plastic Dome Lids',    1.000, NULL),
('Iced Salted Caramel Milktea',  'Paper Straws',         1.000, NULL),

-- ============= LEMON SERIES ===============================================
('Iced Lemon Tea',         'Black Tea Bags', 1.000, NULL),
('Iced Lemon Tea',         'Lemon',          0.030, NULL),
('Iced Lemon Tea',         'Granulated Sugar', 0.015, NULL),
('Iced Lemon Tea',         '22oz Plastic Cups', 1.000, NULL),
('Iced Lemon Tea',         'Plastic Dome Lids', 1.000, NULL),
('Iced Lemon Tea',         'Paper Straws',      1.000, NULL),

('Iced Winterlemonade',    'Lemon',             0.040, NULL),
('Iced Winterlemonade',    'Wintermelon Syrup', 0.030, NULL),
('Iced Winterlemonade',    'Granulated Sugar',  0.015, NULL),
('Iced Winterlemonade',    '22oz Plastic Cups', 1.000, NULL),
('Iced Winterlemonade',    'Plastic Dome Lids', 1.000, NULL),
('Iced Winterlemonade',    'Paper Straws',      1.000, NULL),

('Iced Lemon Yakult',      'Lemon',             0.030, NULL),
('Iced Lemon Yakult',      'Yakult',            2.000, NULL),
('Iced Lemon Yakult',      'Granulated Sugar',  0.010, NULL),
('Iced Lemon Yakult',      '22oz Plastic Cups', 1.000, NULL),
('Iced Lemon Yakult',      'Plastic Dome Lids', 1.000, NULL),
('Iced Lemon Yakult',      'Paper Straws',      1.000, NULL),

('Iced Lemon Berry',       'Lemon',             0.030, NULL),
('Iced Lemon Berry',       'Mixed Berries',     0.040, NULL),
('Iced Lemon Berry',       'Granulated Sugar',  0.015, NULL),
('Iced Lemon Berry',       '22oz Plastic Cups', 1.000, NULL),
('Iced Lemon Berry',       'Plastic Dome Lids', 1.000, NULL),
('Iced Lemon Berry',       'Paper Straws',      1.000, NULL),

('Iced Cucumber Lemon',    'Lemon',             0.030, NULL),
('Iced Cucumber Lemon',    'Cucumber',          0.060, NULL),
('Iced Cucumber Lemon',    'Granulated Sugar',  0.012, NULL),
('Iced Cucumber Lemon',    '22oz Plastic Cups', 1.000, NULL),
('Iced Cucumber Lemon',    'Plastic Dome Lids', 1.000, NULL),
('Iced Cucumber Lemon',    'Paper Straws',      1.000, NULL),

-- ============= FRUITS & YOGURT ============================================
('Iced Strawberry Yogurt', 'Plain Yogurt',      0.180, NULL),
('Iced Strawberry Yogurt', 'Fresh Strawberries',0.060, NULL),
('Iced Strawberry Yogurt', 'Granulated Sugar',  0.015, NULL),
('Iced Strawberry Yogurt', '22oz Plastic Cups', 1.000, NULL),
('Iced Strawberry Yogurt', 'Plastic Dome Lids', 1.000, NULL),
('Iced Strawberry Yogurt', 'Paper Straws',      1.000, NULL),

('Iced Fruity Salabat',    'Ginger',            0.020, NULL),
('Iced Fruity Salabat',    'Lemon',             0.020, NULL),
('Iced Fruity Salabat',    'Mango',             0.060, NULL),
('Iced Fruity Salabat',    'Granulated Sugar',  0.015, NULL),
('Iced Fruity Salabat',    '22oz Plastic Cups', 1.000, NULL),
('Iced Fruity Salabat',    'Plastic Dome Lids', 1.000, NULL),
('Iced Fruity Salabat',    'Paper Straws',      1.000, NULL);

-- ============= INSERT THE LINKS — resolve names → IDs in one shot =========
-- Note: products with duplicate names (like "Hungarian Sausage" in 3 categories)
-- get the SAME ingredients applied to all matching rows. Tune later in the BOM
-- tab if you want them to differ.

INSERT INTO product_recipe (Product_ID, Item_ID, Quantity_Per_Serving, Notes)
SELECT p.Product_ID, si.Item_ID, rs.Qty, rs.Notes
FROM _recipe_seed rs
JOIN product p     ON p.Product_Name = rs.Product_Name
JOIN supply_item si ON si.Item_Name  = rs.Item_Name
ON DUPLICATE KEY UPDATE
  Quantity_Per_Serving = VALUES(Quantity_Per_Serving),
  Notes                = VALUES(Notes);

-- Report what we inserted vs. what couldn't be matched (debug aid)
SELECT 'Recipes inserted/updated' AS message,
       (SELECT COUNT(*) FROM _recipe_seed) AS seed_rows,
       (SELECT COUNT(*) FROM _recipe_seed rs
          JOIN product p ON p.Product_Name = rs.Product_Name
          JOIN supply_item si ON si.Item_Name = rs.Item_Name) AS matched_rows,
       (SELECT COUNT(*) FROM product_recipe) AS total_recipe_rows;

-- Show any seed rows that did NOT match (typically a typo in this file)
SELECT 'UNMATCHED seed' AS warning, rs.Product_Name, rs.Item_Name, rs.Qty
FROM _recipe_seed rs
LEFT JOIN product p ON p.Product_Name = rs.Product_Name
LEFT JOIN supply_item si ON si.Item_Name = rs.Item_Name
WHERE p.Product_ID IS NULL OR si.Item_ID IS NULL;

DROP TABLE IF EXISTS _recipe_seed;
