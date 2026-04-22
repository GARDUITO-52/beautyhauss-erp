-- Migration 007: tracking_number + shipped_at on orders, avg_cost_usd on purchase_batches

ALTER TABLE orders
  ADD COLUMN tracking_number VARCHAR(100) NULL AFTER status,
  ADD COLUMN shipped_at      TIMESTAMP NULL AFTER tracking_number;

ALTER TABLE purchase_batches
  ADD COLUMN avg_cost_usd DECIMAL(8,4) NULL COMMENT 'total_usd / SUM(batch_items.qty)';

UPDATE purchase_batches pb
JOIN (
  SELECT batch_id, SUM(qty) AS total_qty
  FROM purchase_batch_items
  GROUP BY batch_id
) pbi ON pbi.batch_id = pb.id
SET pb.avg_cost_usd = ROUND(pb.total_usd / pbi.total_qty, 4);
