-- =============================================================================
-- Phase 7: Append-only ingredient movement log.
-- Every change to supply_item.Stock_Quantity should write one row here.
--
-- Action_Type:
--   Sale     - automatic deduction triggered by a customer/staff order
--   Refund   - automatic restore triggered by a cancellation/refund
--   Receive  - PO marked Received (stock added)
--   Adjust   - manual stock count from owner/staff
--   Recipe   - a recipe edit changed the ingredient bill (informational only)
-- Reference_Type / Reference_ID:
--   - Sale/Refund -> Order
--   - Receive     -> PurchaseOrder
--   - Adjust      -> NULL (free-form)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `supply_item_log` (
  `Log_ID`          int          NOT NULL AUTO_INCREMENT,
  `Item_ID`         int          NOT NULL,
  `Product_ID`      int          DEFAULT NULL,
  `Action_Type`     enum('Sale','Refund','Receive','Adjust','Recipe')
                     COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Adjust',
  `Quantity_Delta`  decimal(12,3) NOT NULL,
  `Balance_After`   decimal(12,3) NOT NULL,
  `Reference_Type`  varchar(40)  COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Reference_ID`    int          DEFAULT NULL,
  `Reason`          varchar(40)  COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Notes`           varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `User_ID`         int          DEFAULT NULL,
  `User_Role`       varchar(20)  COLLATE utf8mb4_general_ci DEFAULT NULL,
  `Created_At`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`Log_ID`),
  KEY `idx_sil_item`     (`Item_ID`, `Created_At`),
  KEY `idx_sil_product`  (`Product_ID`),
  KEY `idx_sil_action`   (`Action_Type`, `Created_At`),
  KEY `idx_sil_reference`(`Reference_Type`, `Reference_ID`),
  CONSTRAINT `fk_sil_item`    FOREIGN KEY (`Item_ID`)    REFERENCES `supply_item` (`Item_ID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sil_product` FOREIGN KEY (`Product_ID`) REFERENCES `product`     (`Product_ID`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
