-- Users and their role. The first ADMIN is created by install.php.
-- IF NOT EXISTS: this table was already created by install.php before migrations existed.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('ADMIN','PARENT','STUDENT','FORMER_STUDENT','VOLUNTEER','ORGANIZER') NOT NULL DEFAULT 'PARENT',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
