-- Migration 009: Show lineup improvements
-- - retail_price_usd on products
-- - item_type on show_products (product / giveaway / bundle)
-- - whatnot_slot on show_products (if not already added by _migrate_slots.php)

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS retail_price_usd DECIMAL(8,2) NULL DEFAULT NULL;

ALTER TABLE show_products
  ADD COLUMN IF NOT EXISTS whatnot_slot VARCHAR(10) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS item_type ENUM('product','giveaway','bundle') NOT NULL DEFAULT 'product';
