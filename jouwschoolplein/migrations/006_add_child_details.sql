-- Extra details per child for the class lists (klassen.php). All optional; the parent fills them in.
ALTER TABLE children
    ADD COLUMN last_name VARCHAR(80) NULL,
    ADD COLUMN address VARCHAR(200) NULL,
    ADD COLUMN birth_date DATE NULL,
    ADD COLUMN class_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_children_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE SET NULL;
