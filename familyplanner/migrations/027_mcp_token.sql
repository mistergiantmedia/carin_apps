-- Secret token per account for the Claude connector (mcp.php): lets someone talk to Claude to change the planner.
ALTER TABLE fp_users ADD COLUMN mcp_token VARCHAR(40) NULL AFTER ics_token;
