-- Several families. fp_families holds each family and whether Carin/René approved it; fp_users gets the
-- family an account belongs to and whether it may approve new families (is_admin).
-- Family 1 (the Reilmans) keeps the original fp_ tables; other families get their own copies
-- (fp7_events…) when they are approved (lib/families.php).
CREATE TABLE IF NOT EXISTS fp_families (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    approved_by INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fp_families (id, name, status, approved_at) VALUES (1, 'Gezin Reilman', 'ACTIVE', NOW());

ALTER TABLE fp_users ADD COLUMN family_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password, ADD COLUMN last_name VARCHAR(100) NULL AFTER name;

ALTER TABLE fp_users ALTER COLUMN family_id SET DEFAULT 0;

UPDATE fp_users SET is_admin = 1 WHERE family_id = 1;
