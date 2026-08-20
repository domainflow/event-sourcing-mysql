CREATE TABLE IF NOT EXISTS process_manager_states (
   process_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
   status VARCHAR(32) NOT NULL,
   data JSON NOT NULL,
   timeout DATETIME(6) NULL,
   version INT UNSIGNED NOT NULL DEFAULT 0,
   -- The timeout worker's poll: overdue first, bounded by a LIMIT. Without
   -- this it is a filesort over every saga ever started, on every pass.
   KEY idx_timeout (timeout)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
