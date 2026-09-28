-- App settings (key/value), e.g. the school holiday region and the home town for outing tips.
CREATE TABLE IF NOT EXISTS fp_settings (
    name VARCHAR(40) PRIMARY KEY,
    value VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fp_settings (name, value) VALUES ('school_region', 'midden'), ('city', 'Utrecht');
