-- Files live in Cloudinary (type=authenticated); only references are stored here.
CREATE TABLE application_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    doc_type ENUM('cv', 'passport_copy') NOT NULL,
    cloudinary_public_id VARCHAR(255) NOT NULL,
    resource_type VARCHAR(20) NOT NULL,
    format VARCHAR(10) NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_application_documents_app (application_id),
    CONSTRAINT fk_application_documents_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
