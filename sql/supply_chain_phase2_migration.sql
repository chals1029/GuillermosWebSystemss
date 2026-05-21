-- Phase 2: Bill of Materials (recipes linking menu products to supply materials)
-- Run after sql/supply_chain_migration.sql

CREATE TABLE IF NOT EXISTS `product_recipe` (
  `Recipe_ID` int NOT NULL AUTO_INCREMENT,
  `Product_ID` int NOT NULL,
  `Item_ID` int NOT NULL,
  `Quantity_Per_Serving` decimal(10,3) NOT NULL DEFAULT '1.000',
  `Notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Created_At` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Recipe_ID`),
  UNIQUE KEY `uq_product_item` (`Product_ID`,`Item_ID`),
  KEY `idx_recipe_product` (`Product_ID`),
  KEY `idx_recipe_item` (`Item_ID`),
  CONSTRAINT `fk_recipe_product` FOREIGN KEY (`Product_ID`) REFERENCES `product` (`Product_ID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_recipe_item` FOREIGN KEY (`Item_ID`) REFERENCES `supply_item` (`Item_ID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
