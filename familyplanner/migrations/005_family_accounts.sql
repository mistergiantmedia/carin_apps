-- Accounts for Carin and Rene. Temporary password; must_change_password sends them to
-- wachtwoord.php on first login. INSERT IGNORE: an account with the same e-mail is left alone.
ALTER TABLE fp_users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER ics_token;

INSERT IGNORE INTO fp_users (name, email, password_hash, must_change_password, member_id)
SELECT 'Carin', 'carin@mistergiant.com', '$2y$10$aVh5rCMF6f3HeYuAZzuEH.ojmSTjbd./IdZQ1ZJFg2.3wG1mciguy', 1, (SELECT id FROM fp_members WHERE name = 'Carin' LIMIT 1);

INSERT IGNORE INTO fp_users (name, email, password_hash, must_change_password, member_id)
SELECT 'Rene', 'rene@mistergiant.com', '$2y$10$FZzAfIiiuQAbnumc.UwsY.U5IgD7irJVcYX85klvL48B1BuGSFCGe', 1, (SELECT id FROM fp_members WHERE name = 'Rene' LIMIT 1);
