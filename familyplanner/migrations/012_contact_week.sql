-- A friend's regular week: fixed activities per weekday (BSO, sport, club…) and on which days they can play.
-- weekday: 1 = Monday … 7 = Sunday (ISO). status: YES (kan afspreken), NO (kan niet).
CREATE TABLE IF NOT EXISTS fp_contact_week (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'CLUB',
    title VARCHAR(80) NOT NULL,
    emoji VARCHAR(16) NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fp_contact_week_contact (contact_id, weekday),
    CONSTRAINT fp_contact_week_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_contact_days (
    contact_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    status VARCHAR(5) NOT NULL,
    PRIMARY KEY (contact_id, weekday),
    CONSTRAINT fp_contact_days_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
