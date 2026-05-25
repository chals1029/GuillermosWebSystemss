-- Phase 3: Secure supplier confirmation links (Option B)
-- Adds a tokenized public confirmation flow for purchase orders.
-- Run after sql/supply_chain_migration.sql and sql/supply_chain_phase2_migration.sql.
--
-- Idempotent across MySQL 8.x and MariaDB 10.x (uses INFORMATION_SCHEMA checks).

-- 1. Add PO_Token column ----------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order' AND COLUMN_NAME = 'PO_Token'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `purchase_order` ADD COLUMN `PO_Token` VARCHAR(64) COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `Created_By`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Add Supplier_Notes column ---------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order' AND COLUMN_NAME = 'Supplier_Notes'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `purchase_order` ADD COLUMN `Supplier_Notes` TEXT COLLATE utf8mb4_general_ci NULL DEFAULT NULL AFTER `PO_Token`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add Confirmed_At column -----------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order' AND COLUMN_NAME = 'Confirmed_At'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `purchase_order` ADD COLUMN `Confirmed_At` DATETIME NULL DEFAULT NULL AFTER `Supplier_Notes`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Unique index on PO_Token (defeats collisions and enumeration) ---------
SET @idx_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order' AND INDEX_NAME = 'uq_po_token'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `purchase_order` ADD UNIQUE KEY `uq_po_token` (`PO_Token`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Extend the Status enum with Confirmed and Shipped --------------------
ALTER TABLE `purchase_order`
  MODIFY COLUMN `Status`
    ENUM('Draft','Ordered','Confirmed','Shipped','Partial','Received','Cancelled')
    COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Draft';
