CREATE TABLE message_replies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contact_message_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    sent_to VARCHAR(150) NOT NULL,
    sent_at DATETIME NULL,
    delivery_status ENUM('sent', 'failed') NOT NULL,
    error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_message_replies_message (contact_message_id, created_at),
    CONSTRAINT fk_message_replies_message FOREIGN KEY (contact_message_id) REFERENCES contact_messages (id) ON DELETE CASCADE,
    CONSTRAINT fk_message_replies_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
