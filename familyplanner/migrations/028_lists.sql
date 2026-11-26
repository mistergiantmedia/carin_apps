-- Lijstjes: one list per "thing to sort out" (energie overstappen, dekbedden kopen…).
-- fp_list_items: per list to-dos (TODO), options to compare with link/price/pros/cons (OPTION) and inspiration (INSPIRATION).
CREATE TABLE IF NOT EXISTS fp_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    emoji VARCHAR(16) NULL,
    notes TEXT NULL,
    done_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_list_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id INT UNSIGNED NOT NULL,
    kind VARCHAR(12) NOT NULL DEFAULT 'TODO',
    title VARCHAR(190) NOT NULL,
    url VARCHAR(500) NULL,
    price DECIMAL(10,2) NULL,
    price_note VARCHAR(60) NULL,
    pros TEXT NULL,
    cons TEXT NULL,
    notes TEXT NULL,
    member_id INT UNSIGNED NULL,
    chosen TINYINT(1) NOT NULL DEFAULT 0,
    done_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fp_list_items_list (list_id, kind),
    CONSTRAINT fp_list_items_list_fk FOREIGN KEY (list_id) REFERENCES fp_lists (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
