-- Migration 005 — Packing tracking on orders

ALTER TABLE orders
  ADD COLUMN packed_at DATETIME NULL,
  ADD COLUMN packed_by INT UNSIGNED NULL,
  ADD FOREIGN KEY fk_orders_packed_by (packed_by) REFERENCES users(id) ON DELETE SET NULL;
