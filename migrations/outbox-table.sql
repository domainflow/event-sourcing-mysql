CREATE TABLE IF NOT EXISTS outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_class VARCHAR(255) NOT NULL,
    version INT NOT NULL,
    occurred_on DATETIME(6) NOT NULL,
    payload JSON NOT NULL,
    metadata JSON NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    reserved_at DATETIME(6) NULL,
    reserved_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    INDEX idx_claimable (reserved_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
