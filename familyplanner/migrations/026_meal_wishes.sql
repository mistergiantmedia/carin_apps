-- Children pick what they'd like to eat (kiezen.php); parents see the wishes at the week menu.
-- A wish disappears when that recipe is put on the menu.
CREATE TABLE IF NOT EXISTS fp_meal_wishes (
    member_id INT UNSIGNED NOT NULL,
    recipe_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id, recipe_id),
    KEY fp_meal_wishes_recipe (recipe_id),
    CONSTRAINT fp_meal_wishes_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE,
    CONSTRAINT fp_meal_wishes_recipe_fk FOREIGN KEY (recipe_id) REFERENCES fp_recipes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
