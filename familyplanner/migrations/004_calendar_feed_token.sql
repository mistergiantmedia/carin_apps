-- Secret token per account for the calendar subscription link (ics.php) on phones.
ALTER TABLE fp_users ADD COLUMN ics_token VARCHAR(40) NULL AFTER member_id;
