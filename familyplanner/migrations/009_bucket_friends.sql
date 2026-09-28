-- Friends who join a bucketlist wish ("kamperen met Noor en Lotte").
CREATE TABLE IF NOT EXISTS fp_bucket_contacts (
    bucket_id INT UNSIGNED NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_id, contact_id),
    KEY fp_bucket_contacts_contact (contact_id),
    CONSTRAINT fp_bucket_contacts_bucket_fk FOREIGN KEY (bucket_id) REFERENCES fp_bucket (id) ON DELETE CASCADE,
    CONSTRAINT fp_bucket_contacts_contact_fk FOREIGN KEY (contact_id) REFERENCES fp_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
