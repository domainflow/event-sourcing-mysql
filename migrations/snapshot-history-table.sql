CREATE TABLE IF NOT EXISTS snapshot_history (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      aggregate_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      version INT NOT NULL,
      occurred_on DATETIME(6) NOT NULL,
      state JSON NOT NULL,
      UNIQUE KEY uq_aggregate_version (aggregate_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
