-- Job listings managed in the admin panel and shown on Home / Malaysia Jobs (P5-02).
-- Salaries are monthly, in Malaysian Ringgit. image_public_id is a public Cloudinary image (NULL = frontend fallback).
CREATE TABLE jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(80) NOT NULL,
    title VARCHAR(100) NOT NULL,
    employer_type VARCHAR(100) NOT NULL,
    job_category_id INT UNSIGNED NOT NULL,
    location VARCHAR(100) NOT NULL,
    salary_min INT UNSIGNED NOT NULL,
    salary_max INT UNSIGNED NOT NULL,
    description TEXT NULL,
    image_public_id VARCHAR(255) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_jobs_slug (slug),
    KEY idx_jobs_published_sort (is_published, sort_order),
    CONSTRAINT fk_jobs_category FOREIGN KEY (job_category_id) REFERENCES job_categories (id),
    CONSTRAINT fk_jobs_created_by FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL,
    CONSTRAINT fk_jobs_updated_by FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
