-- Own contact details per family member (can be shared with friend families: share option CONTACT)
ALTER TABLE fp_members ADD COLUMN phone VARCHAR(40) NULL AFTER photo, ADD COLUMN email VARCHAR(190) NULL AFTER phone, ADD COLUMN allergies VARCHAR(255) NULL AFTER email;
