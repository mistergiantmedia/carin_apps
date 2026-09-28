-- Own emoji per group (NULL = the emoji of its type).
ALTER TABLE fp_groups ADD COLUMN emoji VARCHAR(16) NULL AFTER type;
