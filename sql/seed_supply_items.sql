-- Sample materials (ingredients & supplies) for Guillermo's Café
-- Requires sql/seed_suppliers.sql (Supplier_ID 1–5)

INSERT INTO `supply_item` (`Item_Name`, `Category`, `Unit`, `Stock_Quantity`, `Reorder_Level`, `Unit_Cost`, `Supplier_ID`, `Notes`, `Status`) VALUES
('Arabica Coffee Beans', 'Coffee', 'kg', 25.00, 10.00, 450.00, 1, 'House blend roast', 'Active'),
('Robusta Coffee Beans', 'Coffee', 'kg', 18.50, 8.00, 320.00, 1, 'Espresso base', 'Active'),
('Full Cream Milk', 'Dairy', 'L', 40.00, 15.00, 85.00, 2, 'Refrigerated', 'Active'),
('Whipping Cream', 'Dairy', 'L', 12.00, 5.00, 120.00, 2, NULL, 'Active'),
('All-Purpose Flour', 'Baking', 'kg', 50.00, 20.00, 55.00, 3, NULL, 'Active'),
('Granulated Sugar', 'Baking', 'kg', 35.00, 15.00, 48.00, 3, NULL, 'Active'),
('Unsalted Butter', 'Baking', 'kg', 8.00, 5.00, 380.00, 3, 'Low stock item', 'Active'),
('Fresh Strawberries', 'Produce', 'kg', 6.00, 3.00, 280.00, 4, 'Seasonal', 'Active'),
('Fresh Milk (Oat)', 'Produce', 'L', 10.00, 4.00, 95.00, 4, 'Non-dairy alternative', 'Active'),
('Ice Cream Base (Vanilla)', 'Dairy', 'L', 5.00, 8.00, 210.00, 2, 'Below reorder — needs PO', 'Active'),
('16oz Paper Cups', 'Packaging', 'pcs', 500.00, 200.00, 3.50, 5, NULL, 'Active'),
('Plastic Dome Lids', 'Packaging', 'pcs', 450.00, 200.00, 2.00, 5, NULL, 'Active'),
('Takeout Box (Medium)', 'Packaging', 'pcs', 120.00, 50.00, 8.00, 5, NULL, 'Active'),
('Chocolate Syrup', 'Beverage', 'bottle', 15.00, 6.00, 185.00, 3, '750ml bottle', 'Active'),
('Matcha Powder', 'Beverage', 'g', 500.00, 200.00, 0.85, 1, 'Per gram cost', 'Active');
