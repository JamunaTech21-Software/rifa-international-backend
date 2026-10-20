-- Messages sent by admins to applicants from the application page (P5-01). Email only for now;
-- `channel` leaves room for SMS later. Failed sends are kept too (delivery_status = 'failed').
CREATE TABLE application_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NULL,
    channel VARCHAR(10) NOT NULL DEFAULT 'email',
    template VARCHAR(30) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    sent_to VARCHAR(150) NOT NULL,
    delivery_status ENUM('sent', 'failed') NOT NULL,
    error VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_application_messages_app (application_id, created_at),
    CONSTRAINT fk_application_messages_app FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE CASCADE,
    CONSTRAINT fk_application_messages_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
