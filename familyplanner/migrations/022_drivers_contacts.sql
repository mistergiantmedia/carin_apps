-- Who brings / picks up can also be someone from the address book (a friend's parent, the babysitter…),
-- not only one of us. drop_contact_id / pickup_contact_id are used instead of drop_member_id / pickup_member_id;
-- per occurrence ("per keer bepalen") fp_event_duties.contact_id is used instead of member_id.
ALTER TABLE fp_events
    ADD COLUMN drop_contact_id INT UNSIGNED NULL AFTER pickup_each,
    ADD COLUMN pickup_contact_id INT UNSIGNED NULL AFTER drop_contact_id,
    ADD CONSTRAINT fp_events_drop_contact_fk FOREIGN KEY (drop_contact_id) REFERENCES fp_contacts (id) ON DELETE SET NULL,
    ADD CONSTRAINT fp_events_pickup_contact_fk FOREIGN KEY (pickup_contact_id) REFERENCES fp_contacts (id) ON DELETE SET NULL;

ALTER TABLE fp_event_duties
    ADD COLUMN contact_id INT UNSIGNED NULL AFTER member_id,
    ADD CONSTRAINT fp_event_duties_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE;
