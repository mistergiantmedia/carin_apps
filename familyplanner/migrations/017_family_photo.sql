-- A photo per family (shown to friend families, in invitations and in Beheer)
ALTER TABLE fp_families ADD COLUMN photo VARCHAR(80) NULL AFTER name;
