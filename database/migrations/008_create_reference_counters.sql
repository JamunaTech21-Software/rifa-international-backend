-- Daily sequence for RIFA-YYYYMMDD-NNNNN. Increment atomically with:
-- INSERT INTO reference_counters (counter_date, last_seq) VALUES (?, LAST_INSERT_ID(1))
--   ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1); then SELECT LAST_INSERT_ID().
CREATE TABLE reference_counters (
    counter_date DATE NOT NULL PRIMARY KEY,
    last_seq INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
