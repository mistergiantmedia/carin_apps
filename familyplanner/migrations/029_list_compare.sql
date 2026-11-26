-- Lijstjes: compare options like a spec table. image_url = picture of the product (from the link),
-- specs = own properties, one "Label: value" per line, rating = 1-5 stars.
ALTER TABLE fp_list_items
    ADD COLUMN image_url VARCHAR(500) NULL AFTER url,
    ADD COLUMN specs TEXT NULL AFTER cons,
    ADD COLUMN rating TINYINT UNSIGNED NULL AFTER specs;
