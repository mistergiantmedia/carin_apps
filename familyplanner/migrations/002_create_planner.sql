-- The whole planner: family members, address book, classes, calendar, tasks, ideas,
-- friend book, photos and birthday checklist.
-- is_demo marks example data that can be removed in one go from the settings page.

CREATE TABLE IF NOT EXISTS fp_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    role VARCHAR(10) NOT NULL DEFAULT 'CHILD',
    birth_day TINYINT UNSIGNED NULL,
    birth_month TINYINT UNSIGNED NULL,
    birth_year SMALLINT UNSIGNED NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6C5CE7',
    emoji VARCHAR(16) NOT NULL DEFAULT '🙂',
    photo VARCHAR(80) NULL,
    sort INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO fp_members (name, role, color, emoji, sort) VALUES
    ('Carin', 'PARENT', '#E0568A', '👩', 1),
    ('Rene', 'PARENT', '#3B7DD8', '👨', 2),
    ('Kaila', 'CHILD', '#2BA879', '👧', 3),
    ('Bodi', 'CHILD', '#F08C2E', '👦', 4);

ALTER TABLE fp_users ADD COLUMN member_id INT UNSIGNED NULL AFTER password_hash;

UPDATE fp_users u JOIN fp_members m ON m.name = u.name SET u.member_id = m.id WHERE u.member_id IS NULL;

-- Address book: a household (Familie Jansen) groups people at one address
CREATE TABLE IF NOT EXISTS fp_households (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    street VARCHAR(160) NULL,
    postal_code VARCHAR(12) NULL,
    city VARCHAR(80) NULL,
    country VARCHAR(60) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    notes TEXT NULL,
    photo VARCHAR(80) NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    household_id INT UNSIGNED NULL,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NULL,
    nickname VARCHAR(60) NULL,
    is_child TINYINT(1) NOT NULL DEFAULT 0,
    relation VARCHAR(20) NOT NULL DEFAULT 'FRIEND',
    birth_day TINYINT UNSIGNED NULL,
    birth_month TINYINT UNSIGNED NULL,
    birth_year SMALLINT UNSIGNED NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    street VARCHAR(160) NULL,
    postal_code VARCHAR(12) NULL,
    city VARCHAR(80) NULL,
    photo VARCHAR(80) NULL,
    allergies VARCHAR(255) NULL,
    hourly_rate DECIMAL(6,2) NULL,
    notes TEXT NULL,
    is_favorite TINYINT(1) NOT NULL DEFAULT 0,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY contacts_household (household_id),
    KEY contacts_birthday (birth_month, birth_day),
    CONSTRAINT fp_contacts_household_fk FOREIGN KEY (household_id) REFERENCES fp_households (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Friend of": which family member a contact belongs to (Kaila's friend, Carin's friend)
CREATE TABLE IF NOT EXISTS fp_contact_members (
    contact_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, member_id),
    CONSTRAINT fp_contact_members_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE,
    CONSTRAINT fp_contact_members_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- School classes for the smoelenboek
CREATE TABLE IF NOT EXISTS fp_classes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    school VARCHAR(120) NULL,
    school_year VARCHAR(9) NULL,
    teacher VARCHAR(160) NULL,
    notes TEXT NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fp_classes_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_class_contacts (
    class_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (class_id, contact_id),
    CONSTRAINT fp_class_contacts_class_fk FOREIGN KEY (class_id) REFERENCES fp_classes (id) ON DELETE CASCADE,
    CONSTRAINT fp_class_contacts_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Calendar. recurrence: '', DAILY, WEEKLY, BIWEEKLY, MONTHLY, YEARLY (until recur_until).
-- host: HOME = bij ons, AWAY = bij hen (used for playdates and the vriendjesbingo).
CREATE TABLE IF NOT EXISTS fp_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'OTHER',
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    location VARCHAR(190) NULL,
    host VARCHAR(5) NOT NULL DEFAULT '',
    description TEXT NULL,
    color VARCHAR(7) NULL,
    recurrence VARCHAR(10) NOT NULL DEFAULT '',
    recur_until DATE NULL,
    drop_member_id INT UNSIGNED NULL,
    pickup_member_id INT UNSIGNED NULL,
    cost DECIMAL(8,2) NULL,
    paid TINYINT(1) NOT NULL DEFAULT 0,
    done TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY events_start (start_at),
    KEY events_end (end_at),
    KEY events_type (type),
    CONSTRAINT fp_events_drop_fk FOREIGN KEY (drop_member_id) REFERENCES fp_members (id) ON DELETE SET NULL,
    CONSTRAINT fp_events_pickup_fk FOREIGN KEY (pickup_member_id) REFERENCES fp_members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_event_members (
    event_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, member_id),
    CONSTRAINT fp_event_members_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE CASCADE,
    CONSTRAINT fp_event_members_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guests / friends at an event. rsvp: '', YES, NO, MAYBE
CREATE TABLE IF NOT EXISTS fp_event_contacts (
    event_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    rsvp VARCHAR(5) NOT NULL DEFAULT '',
    PRIMARY KEY (event_id, contact_id),
    KEY event_contacts_contact (contact_id),
    CONSTRAINT fp_event_contacts_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE CASCADE,
    CONSTRAINT fp_event_contacts_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Occurrences of a repeating event that were removed or moved on their own
CREATE TABLE IF NOT EXISTS fp_event_exceptions (
    event_id INT UNSIGNED NOT NULL,
    occurs_on DATE NOT NULL,
    PRIMARY KEY (event_id, occurs_on),
    CONSTRAINT fp_event_exceptions_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ticked-off occurrences of a repeating event (a single event uses events.done)
CREATE TABLE IF NOT EXISTS fp_event_done (
    event_id INT UNSIGNED NOT NULL,
    occurs_on DATE NOT NULL,
    PRIMARY KEY (event_id, occurs_on),
    CONSTRAINT fp_event_done_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who does what when: to-dos, chores, packing lists (event_id) and "wie neemt wat mee" (contact_id)
CREATE TABLE IF NOT EXISTS fp_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    notes TEXT NULL,
    member_id INT UNSIGNED NULL,
    contact_id INT UNSIGNED NULL,
    event_id INT UNSIGNED NULL,
    due_date DATE NULL,
    recurrence VARCHAR(10) NOT NULL DEFAULT '',
    done_at DATETIME NULL,
    sort INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY tasks_due (due_date),
    CONSTRAINT fp_tasks_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE SET NULL,
    CONSTRAINT fp_tasks_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE SET NULL,
    CONSTRAINT fp_tasks_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idea bank: outings, school engagement, date nights, gifts, being attentive
CREATE TABLE IF NOT EXISTS fp_ideas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    category VARCHAR(20) NOT NULL DEFAULT 'OUTING',
    member_id INT UNSIGNED NULL,
    contact_id INT UNSIGNED NULL,
    url VARCHAR(255) NULL,
    cost VARCHAR(40) NULL,
    season VARCHAR(20) NULL,
    event_id INT UNSIGNED NULL,
    done_at DATETIME NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fp_ideas_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE SET NULL,
    CONSTRAINT fp_ideas_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE,
    CONSTRAINT fp_ideas_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vriendjesboek: where is the book? direction OUT = our child's book is with a friend,
-- IN = a friend's book is at our house to fill in.
CREATE TABLE IF NOT EXISTS fp_friendbook (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NULL,
    direction VARCHAR(3) NOT NULL DEFAULT 'OUT',
    given_on DATE NOT NULL,
    returned_on DATE NULL,
    notes VARCHAR(255) NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fp_friendbook_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE,
    CONSTRAINT fp_friendbook_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- School photos and other photos
CREATE TABLE IF NOT EXISTS fp_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NULL,
    class_id INT UNSIGNED NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'SCHOOL',
    school_year VARCHAR(9) NULL,
    title VARCHAR(160) NULL,
    file VARCHAR(80) NOT NULL,
    taken_on DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fp_photos_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE,
    CONSTRAINT fp_photos_class_fk FOREIGN KEY (class_id) REFERENCES fp_classes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Birthday checklist per year: subject 'c12' (contact) or 'm3' (member); item CARD, GIFT, CALL, PARTY
CREATE TABLE IF NOT EXISTS fp_birthday_checks (
    subject VARCHAR(12) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    item VARCHAR(10) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subject, year, item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
