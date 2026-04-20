-- Migration 006: USD-Only — operation is based in Miami, no MXN
-- Run once on the server

ALTER TABLE products
  DROP COLUMN IF EXISTS cost_mxn,
  DROP COLUMN IF EXISTS fx_rate_at_purchase,
  CHANGE COLUMN rescue_price_mxn rescue_price_usd DECIMAL(10,2) NULL DEFAULT NULL;

ALTER TABLE purchase_batches
  DROP COLUMN IF EXISTS fx_rate;
