-- Migration 008: Post-show result fields on shows table

-- Extend status enum to include DONE
ALTER TABLE shows
  MODIFY COLUMN status ENUM('SCHEDULED','LIVE','COMPLETED','DONE','CANCELLED') NOT NULL DEFAULT 'SCHEDULED';

-- Add post-show result columns
ALTER TABLE shows
  ADD COLUMN boxes_sold          INT UNSIGNED DEFAULT 0,
  ADD COLUMN revenue_usd         DECIMAL(10,2) DEFAULT 0,
  ADD COLUMN avg_units_per_box   DECIMAL(5,2) DEFAULT 5.00,
  ADD COLUMN units_depleted      INT UNSIGNED DEFAULT 0,
  ADD COLUMN community_boost_usd DECIMAL(10,2) DEFAULT 0,
  ADD COLUMN format              ENUM('pulls','fragrance_bombs','mixed','individual') DEFAULT 'mixed',
  ADD COLUMN notes_post          TEXT;
