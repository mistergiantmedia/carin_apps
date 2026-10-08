-- Google Calendar koppeling (lib/gcal.php). Each account can connect its own Google account: the planner
-- creates a "Familie Planner" calendar there and keeps it up to date (push), and moves / deletions made in
-- Google come back (pull, via the stored sync token).

-- App-wide settings shared by all families (the Google OAuth client, set by Carin/René in beheer.php)
CREATE TABLE IF NOT EXISTS fp_app_settings (
    name VARCHAR(40) PRIMARY KEY,
    value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per account: tokens, the calendar we made, whose events go in (NULL = the whole family), sync state
ALTER TABLE fp_users
    ADD COLUMN google_email VARCHAR(190) NULL,
    ADD COLUMN google_refresh_token TEXT NULL,
    ADD COLUMN google_access_token TEXT NULL,
    ADD COLUMN google_token_expires_at DATETIME NULL,
    ADD COLUMN google_calendar_id VARCHAR(255) NULL,
    ADD COLUMN google_member_id INT UNSIGNED NULL,
    ADD COLUMN google_sync_token TEXT NULL,
    ADD COLUMN google_synced_at DATETIME NULL,
    ADD COLUMN google_pending INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN google_error VARCHAR(255) NULL;

-- Per family: which planner item (e12 = event 12, e12-2026-10-08 = one occurrence of repeating event 12,
-- bm3-2026-05-01 / bc7-… = a birthday) is which Google event in an account's calendar, with what we sent
-- (hash of the payload; times to recognise a move made in Google).
-- No foreign key to fp_users: a statement naming a shared table isn't repeated for the other families.
CREATE TABLE IF NOT EXISTS fp_gcal_events (
    user_id INT UNSIGNED NOT NULL,
    item VARCHAR(60) NOT NULL,
    google_id VARCHAR(190) NOT NULL,
    hash CHAR(32) NOT NULL DEFAULT '',
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, item),
    KEY fp_gcal_events_google (user_id, google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
