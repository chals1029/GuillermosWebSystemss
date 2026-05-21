-- Supply chain tables for Guillermo's Web Systems
-- Import via phpMyAdmin after selecting u435394025_guillermos_db

CREATE TABLE IF NOT EXISTS `supplier` (
  `Supplier_ID` int NOT NULL AUTO_INCREMENT,
  `Supplier_Name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `Contact_Person` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Email` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Phone` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Address` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Status` enum('Active','Inactive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Active',
  `Notes` text COLLATE utf8mb4_general_ci,
  `Created_At` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Supplier_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `supply_item` (
  `Item_ID` int NOT NULL AUTO_INCREMENT,
  `Item_Name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `Category` varchar(80) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'General',
  `Unit` varchar(30) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pcs',
  `Stock_Quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `Reorder_Level` decimal(10,2) NOT NULL DEFAULT '0.00',
  `Unit_Cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  `Supplier_ID` int DEFAULT NULL,
  `Notes` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Status` enum('Active','Inactive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Active',
  `Created_At` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Item_ID`),
  KEY `idx_supply_item_supplier` (`Supplier_ID`),
  CONSTRAINT `fk_supply_item_supplier` FOREIGN KEY (`Supplier_ID`) REFERENCES `supplier` (`Supplier_ID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `purchase_order` (
  `PO_ID` int NOT NULL AUTO_INCREMENT,
  `Supplier_ID` int NOT NULL,
  `Order_Date` date NOT NULL,
  `Expected_Delivery` date DEFAULT NULL,
  `Status` enum('Draft','Ordered','Partial','Received','Cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Draft',
  `Total_Amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `Notes` text COLLATE utf8mb4_general_ci,
  `Created_By` int DEFAULT NULL,
  `Created_At` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`PO_ID`),
  KEY `idx_po_supplier` (`Supplier_ID`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`Supplier_ID`) REFERENCES `supplier` (`Supplier_ID`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_line` (
  `Line_ID` int NOT NULL AUTO_INCREMENT,
  `PO_ID` int NOT NULL,
  `Item_ID` int NOT NULL,
  `Quantity_Ordered` decimal(10,2) NOT NULL,
  `Quantity_Received` decimal(10,2) NOT NULL DEFAULT '0.00',
  `Unit_Cost` decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`Line_ID`),
  KEY `idx_pol_po` (`PO_ID`),
  KEY `idx_pol_item` (`Item_ID`),
  CONSTRAINT `fk_pol_po` FOREIGN KEY (`PO_ID`) REFERENCES `purchase_order` (`PO_ID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pol_item` FOREIGN KEY (`Item_ID`) REFERENCES `supply_item` (`Item_ID`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
