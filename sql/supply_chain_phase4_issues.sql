-- Phase 4: Material-receipt issues, refunds, and replacement requests.
-- Run after sql/supply_chain_phase3_supplier_link.sql.
--
-- A supply_issue is an admin-filed problem against a received PO line:
-- damaged, wrong quantity, expired, wrong item, or "other". The admin requests
-- one of three actions — Refund, Replacement, or Credit_Note — and the
-- supplier is notified by email with the secure PO link to view and reply.

CREATE TABLE IF NOT EXISTS `supply_issue` (
  `Issue_ID` int NOT NULL AUTO_INCREMENT,
  `PO_ID` int NOT NULL,
  `Line_ID` int DEFAULT NULL,
  `Issue_Type` enum('Damaged','Wrong_Quantity','Expired','Wrong_Item','Other')
       COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Other',
  `Action_Requested` enum('Refund','Replacement','Credit_Note')
       COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Replacement',
  `Quantity_Affected` decimal(10,2) NOT NULL DEFAULT '0.00',
  `Status` enum('Open','Acknowledged','Resolved','Rejected')
       COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Open',
  `Buyer_Notes` text COLLATE utf8mb4_general_ci,
  `Supplier_Reply` text COLLATE utf8mb4_general_ci,
  `Reported_By` int DEFAULT NULL,
  `Created_At` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Resolved_At` datetime DEFAULT NULL,
  PRIMARY KEY (`Issue_ID`),
  KEY `idx_si_po` (`PO_ID`),
  KEY `idx_si_line` (`Line_ID`),
  KEY `idx_si_status` (`Status`),
  CONSTRAINT `fk_si_po` FOREIGN KEY (`PO_ID`) REFERENCES `purchase_order` (`PO_ID`)
       ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_si_line` FOREIGN KEY (`Line_ID`) REFERENCES `purchase_order_line` (`Line_ID`)
       ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
