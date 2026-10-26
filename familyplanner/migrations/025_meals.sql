-- Eten & boodschappen: recipes (gerechten) with ingredients and per-person likes, a week menu, and a shared
-- shopping list. Tags on a recipe (comma list): VEG, FISH, LEGUMES, REDMEAT, QUICK, WHOLEGRAIN.

CREATE TABLE IF NOT EXISTS fp_recipes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    emoji VARCHAR(16) NULL,
    prep_minutes SMALLINT UNSIGNED NULL,
    tags VARCHAR(120) NOT NULL DEFAULT '',
    method TEXT NULL,
    url VARCHAR(255) NULL,
    photo VARCHAR(80) NULL,
    is_starter TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- category: GROENTE, ZUIVEL, VLEES, BROOD, DROOG, DIEPVRIES, OVERIG (the order of the supermarket)
CREATE TABLE IF NOT EXISTS fp_recipe_ingredients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT UNSIGNED NOT NULL,
    amount VARCHAR(40) NULL,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(10) NOT NULL DEFAULT 'OVERIG',
    sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    KEY fp_recipe_ingredients_recipe (recipe_id, sort),
    CONSTRAINT fp_recipe_ingredients_recipe_fk FOREIGN KEY (recipe_id) REFERENCES fp_recipes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who likes it: verdict LIKE or DISLIKE per family member
CREATE TABLE IF NOT EXISTS fp_recipe_likes (
    recipe_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    verdict VARCHAR(8) NOT NULL,
    PRIMARY KEY (recipe_id, member_id),
    CONSTRAINT fp_recipe_likes_recipe_fk FOREIGN KEY (recipe_id) REFERENCES fp_recipes (id) ON DELETE CASCADE,
    CONSTRAINT fp_recipe_likes_member_fk FOREIGN KEY (member_id) REFERENCES fp_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The menu: one dinner per day (a recipe, or just a title like "Uit eten" / "Restjes")
CREATE TABLE IF NOT EXISTS fp_meals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    day DATE NOT NULL,
    recipe_id INT UNSIGNED NULL,
    title VARCHAR(160) NULL,
    cook_member_id INT UNSIGNED NULL,
    on_list TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY fp_meals_day (day),
    CONSTRAINT fp_meals_recipe_fk FOREIGN KEY (recipe_id) REFERENCES fp_recipes (id) ON DELETE SET NULL,
    CONSTRAINT fp_meals_cook_fk FOREIGN KEY (cook_member_id) REFERENCES fp_members (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shopping list, shared by the whole family
CREATE TABLE IF NOT EXISTS fp_shopping (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    amount VARCHAR(80) NULL,
    category VARCHAR(10) NOT NULL DEFAULT 'OVERIG',
    for_meals VARCHAR(255) NULL,
    done_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fp_shopping_done (done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
