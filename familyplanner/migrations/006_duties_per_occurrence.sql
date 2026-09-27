-- Who brings / picks up can be decided per occurrence of a repeating event ("per keer bepalen").
-- drop_each / pickup_each on the series; the choice per date lives in fp_event_duties (role DROP or PICKUP).
ALTER TABLE fp_events ADD COLUMN drop_each TINYINT(1) NOT NULL DEFAULT 0 AFTER pickup_member_id, ADD COLUMN pickup_each TINYINT(1) NOT NULL DEFAULT 0 AFTER drop_each;

CREATE TABLE IF NOT EXISTS fp_event_duties (
    event_id INT UNSIGNED NOT NULL,
    occurs_on DATE NOT NULL,
    role VARCHAR(6) NOT NULL,
    member_id INT UNSIGNED NULL,
    PRIMARY KEY (event_id, occurs_on, role),
    CONSTRAINT fp_event_duties_event_fk FOREIGN KEY (event_id) REFERENCES fp_events (id) ON DELETE CASCADE,
    CONSTRAINT fp_event_duties_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
