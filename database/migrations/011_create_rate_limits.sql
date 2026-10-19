CREATE TABLE rate_limits (
    rl_key VARCHAR(191) NOT NULL PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL,
    window_start DATETIME NOT NULL,
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
