-- Own emoji per event (NULL = the emoji of its type).
ALTER TABLE fp_events ADD COLUMN emoji VARCHAR(16) NULL AFTER type;
