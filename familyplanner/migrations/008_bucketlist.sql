-- Bucketlist: things the children (and parents) want to do together.
-- fp_bucket_votes: who wants it ("Ik wil ook!"). event_id: planned in the agenda. done_on: done.
CREATE TABLE IF NOT EXISTS fp_bucket (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    emoji VARCHAR(16) NULL,
    notes TEXT NULL,
    event_id INT UNSIGNED NULL,
    done_on DATE NULL,
    created_by INT UNSIGNED NULL,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fp_bucket_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fp_bucket_votes (
    bucket_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket_id, member_id),
    CONSTRAINT fp_bucket_votes_bucket_fk FOREIGN KEY (bucket_id) REFERENCES fp_bucket (id) ON DELETE CASCADE,
    CONSTRAINT fp_bucket_votes_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
