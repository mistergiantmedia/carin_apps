-- Settings can hold larger values (e.g. the cached weather forecast)
ALTER TABLE fp_settings MODIFY value TEXT NULL;
