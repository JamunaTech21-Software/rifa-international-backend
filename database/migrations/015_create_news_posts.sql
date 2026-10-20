-- News & Updates articles managed in the admin panel (P5-03).
-- body is plain text; paragraphs are separated by a blank line. A post is public when
-- is_published = 1 and published_on <= today (a future date schedules it).
CREATE TABLE news_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    title VARCHAR(150) NOT NULL,
    excerpt VARCHAR(300) NOT NULL,
    body TEXT NOT NULL,
    image_public_id VARCHAR(255) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    published_on DATE NOT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_news_slug (slug),
    KEY idx_news_public (is_published, published_on),
    CONSTRAINT fk_news_created_by FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL,
    CONSTRAINT fk_news_updated_by FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
