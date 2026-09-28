-- Groups (circles): family, school class, sports team, work, club, neighbourhood, friends.
-- Family members and address book contacts can be in any number of groups, each with an optional role.
-- Replaces fp_classes / fp_class_contacts (copied over as SCHOOL groups with the same ids; the old
-- tables are left in place, unused, until they are removed on purpose).
CREATE TABLE IF NOT EXISTS fp_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(12) NOT NULL DEFAULT 'OTHER',
    name VARCHAR(120) NOT NULL,
    place VARCHAR(160) NULL,
    season VARCHAR(20) NULL,
    leader VARCHAR(160) NULL,
    notes TEXT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fp_groups_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_group_members (
    group_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    role VARCHAR(40) NULL,
    PRIMARY KEY (group_id, member_id),
    CONSTRAINT fp_group_members_group_fk FOREIGN KEY (group_id) REFERENCES fp_groups (id) ON DELETE CASCADE,
    CONSTRAINT fp_group_members_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_group_contacts (
    group_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    role VARCHAR(40) NULL,
    PRIMARY KEY (group_id, contact_id),
    KEY fp_group_contacts_contact (contact_id),
    CONSTRAINT fp_group_contacts_group_fk FOREIGN KEY (group_id) REFERENCES fp_groups (id) ON DELETE CASCADE,
    CONSTRAINT fp_group_contacts_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fp_groups (id, type, name, place, season, leader, notes, is_demo, created_at)
SELECT id, 'SCHOOL', name, school, school_year, teacher, notes, is_demo, created_at FROM fp_classes;

INSERT IGNORE INTO fp_group_members (group_id, member_id, role)
SELECT id, member_id, 'leerling' FROM fp_classes WHERE member_id IS NOT NULL;

INSERT IGNORE INTO fp_group_contacts (group_id, contact_id)
SELECT class_id, contact_id FROM fp_class_contacts;

ALTER TABLE fp_photos ADD COLUMN group_id INT UNSIGNED NULL AFTER class_id, ADD CONSTRAINT fp_photos_group_fk FOREIGN KEY (group_id) REFERENCES fp_groups (id) ON DELETE SET NULL;

UPDATE fp_photos SET group_id = class_id WHERE class_id IS NOT NULL;
