-- Core tables for the working prototype: squares (schools), members, children, activities,
-- who joins with which children, and the conversation per activity.

-- A "schoolplein". registration_mode: OPEN = anyone can register, CODE = only with join_code.
-- activity_mode: EVERYONE = members post directly, APPROVAL = admin approves first, ADMINS = only admins post.
CREATE TABLE schools (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    registration_mode ENUM('OPEN','CODE') NOT NULL DEFAULT 'OPEN',
    join_code VARCHAR(40) NULL UNIQUE,
    activity_mode ENUM('EVERYONE','APPROVAL','ADMINS') NOT NULL DEFAULT 'EVERYONE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membership of a square. role ADMIN = beheerder of that square.
-- (users.role = 'ADMIN' is the site-wide beheerder and may manage every square.)
CREATE TABLE school_memberships (
    school_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('MEMBER','ADMIN') NOT NULL DEFAULT 'MEMBER',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (school_id, user_id),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Children are managed by their parent/guardian.
CREATE TABLE children (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    first_name VARCHAR(80) NOT NULL,
    group_name VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- category decides the card colour: ACTIVITY = coral, OUTING = blue, COMMUNITY = orange.
CREATE TABLE activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    location VARCHAR(150) NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NULL,
    category ENUM('ACTIVITY','OUTING','COMMUNITY') NOT NULL DEFAULT 'ACTIVITY',
    needs_volunteers TINYINT(1) NOT NULL DEFAULT 0,
    price_description VARCHAR(150) NULL,
    max_children INT UNSIGNED NULL,
    status ENUM('PENDING','PUBLISHED') NOT NULL DEFAULT 'PUBLISHED',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (school_id, status, start_at),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A family (user) joining an activity.
CREATE TABLE activity_participants (
    activity_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id, user_id),
    FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which children come along.
CREATE TABLE participant_children (
    activity_id INT UNSIGNED NOT NULL,
    child_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (activity_id, child_id),
    FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The conversation belongs to the activity (no WhatsApp groups, no DMs).
CREATE TABLE activity_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (activity_id, created_at),
    FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The first square, with every existing user as member (site admins as square admin).
INSERT INTO schools (name) VALUES ('Dalton Pieterskerkhof');

INSERT INTO school_memberships (school_id, user_id, role)
SELECT s.id, u.id, IF(u.role = 'ADMIN', 'ADMIN', 'MEMBER') FROM users u JOIN schools s ON s.name = 'Dalton Pieterskerkhof';

-- The three example activities from the prototype, so the square isn't empty.
INSERT INTO activities (school_id, created_by, title, description, location, start_at, category, price_description)
SELECT s.id, (SELECT MIN(id) FROM users WHERE role = 'ADMIN'), '🏃 Maliebaanloop',
       'Een gezellige ochtend samen hardlopen. Iedereen regelt zelf de inschrijving.',
       'Maliebaan', '2026-10-04 10:00:00', 'ACTIVITY', '€5 per kind'
FROM schools s WHERE s.name = 'Dalton Pieterskerkhof';

INSERT INTO activities (school_id, created_by, title, description, location, start_at, category, price_description)
SELECT s.id, (SELECT MIN(id) FROM users WHERE role = 'ADMIN'), '🦖 Naar Naturalis',
       'Museumuitje met ruimte om samen te reizen. Vragen en vervoer regelen we hier. Iedereen koopt een eigen ticket.',
       'Leiden', '2026-10-17 11:00:00', 'OUTING', '€18 kind · €22 volw.'
FROM schools s WHERE s.name = 'Dalton Pieterskerkhof';

INSERT INTO activities (school_id, created_by, title, description, location, start_at, category, needs_volunteers, price_description)
SELECT s.id, (SELECT MIN(id) FROM users WHERE role = 'ADMIN'), '🧡 Oud-leerlingen helpen op het plein',
       'Oud-leerlingen die nog kind zijn kunnen eenvoudig meedoen en helpen tijdens een sport- en spelmiddag.',
       'Schoolplein', '2026-10-28 15:00:00', 'COMMUNITY', 1, 'Vrijwilligers welkom'
FROM schools s WHERE s.name = 'Dalton Pieterskerkhof';
