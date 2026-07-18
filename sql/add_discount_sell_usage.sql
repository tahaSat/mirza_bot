-- DiscountSell usage log for purchase/extend tracking.
CREATE TABLE IF NOT EXISTS DiscountSellUsage (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(255) NOT NULL,
  id_user VARCHAR(64) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'buy',
  code_product VARCHAR(100) NULL,
  name_product VARCHAR(255) NULL,
  code_panel VARCHAR(100) NULL,
  name_panel VARCHAR(255) NULL,
  id_invoice VARCHAR(100) NULL,
  price_original VARCHAR(50) NULL,
  price_final VARCHAR(50) NULL,
  created_at INT UNSIGNED NOT NULL,
  KEY idx_discount_usage_code (code),
  KEY idx_discount_usage_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
