-- Families as friends. Shared tables (not per family): friendships, what each family shares with a
-- friend family, and links between a contact in one family and the real family member in the other.
-- Per family: which events are explicitly shared with which friend family, and synced weekly items.

-- family_a < family_b; requested_by = the family that sent the invitation. status: PENDING | ACCEPTED
CREATE TABLE IF NOT EXISTS fp_family_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_a INT UNSIGNED NOT NULL,
    family_b INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    accepted_at DATETIME NULL,
    UNIQUE KEY fp_family_links_pair (family_a, family_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What owner_family shares with friend_family: PROFILE, BIRTHDAYS, CLUBS, PLAYDATES, EVENTS
CREATE TABLE IF NOT EXISTS fp_family_shares (
    owner_family INT UNSIGNED NOT NULL,
    friend_family INT UNSIGNED NOT NULL,
    what VARCHAR(12) NOT NULL,
    PRIMARY KEY (owner_family, friend_family, what)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Our contact Sonya (family_id / contact_id) is Sonya in her own family (other_family / member_id)"
CREATE TABLE IF NOT EXISTS fp_contact_links (
    family_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    other_family INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (family_id, contact_id),
    KEY fp_contact_links_member (other_family, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Events explicitly shared with a friend family (per family table)
CREATE TABLE IF NOT EXISTS fp_event_shares (
    event_id INT UNSIGNED NOT NULL,
    friend_family INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, friend_family),
    CONSTRAINT fp_event_shares_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weekly items copied from a linked friend's own agenda are marked, so a sync can replace them
ALTER TABLE fp_contact_week ADD COLUMN source VARCHAR(10) NULL AFTER emoji;
