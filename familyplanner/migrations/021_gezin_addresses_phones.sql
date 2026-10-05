-- A gezin can have several addresses (e.g. "Bij mama", "Bij papa", "Vakantiehuis") and several phone numbers
-- ("Mama", "Papa", "Thuis"). The first address / number is also kept in fp_households (street… / phone) so
-- everything that shows one address keeps working. Gezinnen without rows here use those columns.
CREATE TABLE IF NOT EXISTS fp_household_addresses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    label VARCHAR(60) NULL,
    street VARCHAR(160) NULL,
    postal_code VARCHAR(12) NULL,
    city VARCHAR(80) NULL,
    country VARCHAR(60) NULL,
    sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    KEY fp_household_addresses_household (household_id, sort),
    CONSTRAINT fp_household_addresses_household_fk FOREIGN KEY (household_id) REFERENCES fp_households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_household_phones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NOT NULL,
    label VARCHAR(60) NULL,
    phone VARCHAR(40) NOT NULL,
    sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    KEY fp_household_phones_household (household_id, sort),
    CONSTRAINT fp_household_phones_household_fk FOREIGN KEY (household_id) REFERENCES fp_households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
