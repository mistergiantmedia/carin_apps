-- Google Agenda: also remember the text we sent (title, place, description), so a change made in Google can be
-- told apart from our own and taken over into the planner. NULL = not known yet: the next push sends it again.
ALTER TABLE fp_gcal_events
    ADD COLUMN sent_summary VARCHAR(255) NULL,
    ADD COLUMN sent_location VARCHAR(255) NULL,
    ADD COLUMN sent_description TEXT NULL;
