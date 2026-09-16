CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS cms_content (
    id SERIAL PRIMARY KEY,
    page VARCHAR(50) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'article',
    title VARCHAR(255) NOT NULL,
    details TEXT,
    image_url TEXT,
    location VARCHAR(255),
    event_time TIMESTAMPTZ,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    removed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_cms_content_page ON cms_content (page) WHERE removed_at IS NULL;

-- Seed the initial admin user manually with a strong random password, e.g.:
--   php -r 'require "vendor/autoload.php"; use MainzWorld\Config\Database;
--     $hash = password_hash("<STRONG_RANDOM_PASSWORD>", PASSWORD_BCRYPT);
--     $pdo = Database::connection();
--     $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (:u,:h,:r)")
--         ->execute(["u" => "youradminname", "h" => $hash, "r" => "admin"]);'
-- Never hardcode a default password (the legacy CMS used 'aaa' — do not repeat that).
-- NOTE: column was originally `email`; migration 008 renamed it to `username` (plain, no @domain required).
