-- Pieterskerkhof (Openbare Dalton Basisschool) schooljaar 2026-2027, from
-- https://www.pieterskerkhof.com/praktisch/schooltijden-en-vakanties : holidays, study days and 12.00 afternoons,
-- for all children in the family. Each event is followed by linking the children (LAST_INSERT_ID = that event).
INSERT INTO fp_settings (name, value) VALUES ('school', 'Pieterskerkhof'), ('school_url', 'https://www.pieterskerkhof.com/praktisch/schooltijden-en-vakanties') ON DUPLICATE KEY UPDATE value = VALUES(value);

INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Herfstvakantie', 'SCHOOLHOLIDAY', '2026-10-17 00:00:00', '2026-10-25 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Kerstvakantie', 'SCHOOLHOLIDAY', '2026-12-19 00:00:00', '2027-01-03 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Voorjaarsvakantie', 'SCHOOLHOLIDAY', '2027-02-20 00:00:00', '2027-02-28 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Meivakantie', 'SCHOOLHOLIDAY', '2027-04-24 00:00:00', '2027-05-09 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Zomervakantie', 'SCHOOLHOLIDAY', '2027-07-17 00:00:00', '2027-08-29 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiedag', 'STUDYDAY', '2026-09-21 00:00:00', '2026-09-21 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiedag', 'STUDYDAY', '2026-11-03 00:00:00', '2026-11-03 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiedag', 'STUDYDAY', '2027-01-27 00:00:00', '2027-01-27 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiedag', 'STUDYDAY', '2027-03-30 00:00:00', '2027-03-30 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiedag', 'STUDYDAY', '2027-05-18 00:00:00', '2027-05-18 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiedag', 'STUDYDAY', '2027-06-09 00:00:00', '2027-06-09 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiedag', 'STUDYDAY', '2027-07-02 00:00:00', '2027-07-02 23:59:00', 1);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiemiddag', 'STUDYPM', '2026-10-07 12:00:00', '2026-10-07 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiemiddag', 'STUDYPM', '2026-11-18 12:00:00', '2026-11-18 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiemiddag', 'STUDYPM', '2027-02-01 12:00:00', '2027-02-01 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiemiddag', 'STUDYPM', '2027-03-18 12:00:00', '2027-03-18 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Studiemiddag', 'STUDYPM', '2027-06-24 12:00:00', '2027-06-24 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Sinterklaas (12.00 uur uit)', 'STUDYPM', '2026-12-04 12:00:00', '2026-12-04 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Start kerstvakantie (12.00 uur uit)', 'STUDYPM', '2026-12-18 12:00:00', '2026-12-18 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Vossenjacht (12.00 uur uit)', 'STUDYPM', '2027-03-26 12:00:00', '2027-03-26 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
INSERT INTO fp_events (title, type, start_at, end_at, all_day) VALUES ('Start zomervakantie (12.00 uur uit)', 'STUDYPM', '2027-07-16 12:00:00', '2027-07-16 18:00:00', 0);
INSERT INTO fp_event_members (event_id, member_id) SELECT LAST_INSERT_ID(), id FROM fp_members WHERE role = 'CHILD';
