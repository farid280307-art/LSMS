-- ==============================================================
-- LSMS - Update Garansi Sparepart
-- Jalankan script ini pada setiap database CABANG yang memiliki tabel service_items
-- Contoh: lsms_jakarta, lsms_bandung, lsms_surabaya, dst.
-- ==============================================================

ALTER TABLE service_items
    ADD COLUMN warranty_duration INT NOT NULL DEFAULT 0 AFTER price,
    ADD COLUMN warranty_start DATE NULL AFTER warranty_duration,
    ADD COLUMN warranty_end DATE NULL AFTER warranty_start,
    ADD COLUMN warranty_status ENUM('active','expired') NOT NULL DEFAULT 'expired' AFTER warranty_end,
    ADD COLUMN is_warranty_claim TINYINT(1) NOT NULL DEFAULT 0 AFTER warranty_status;

-- Optional cleanup data lama:
UPDATE service_items
SET
    warranty_duration = COALESCE(warranty_duration, 0),
    warranty_status = CASE
        WHEN warranty_end IS NOT NULL AND CURDATE() <= warranty_end THEN 'active'
        ELSE 'expired'
    END,
    is_warranty_claim = COALESCE(is_warranty_claim, 0)
WHERE item_type = 'sparepart';
