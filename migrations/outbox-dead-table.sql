-- Dead letters: entries the relay gave up on after `maxAttempts` failures.
--
-- A separate table rather than a flag on `outbox`. Two reasons, both
-- about the hot table: `reserve()` claims with one indexed UPDATE and every
-- extra predicate on it is paid on every pass by every relay, forever; and an
-- entry nobody can deliver is exactly the one an operator wants to sit and
-- query without touching the queue that is still running.
--
-- The columns mirror `outbox` so a rescued entry can be moved back with an
-- INSERT ... SELECT once whatever rejected it has been fixed. `id` is not
-- AUTO_INCREMENT here: it carries the id the entry had while it was pending,
-- so a log line naming an outbox id still finds it.
CREATE TABLE IF NOT EXISTS outbox_dead (
    id BIGINT UNSIGNED PRIMARY KEY,
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aggregate_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_class VARCHAR(255) NOT NULL,
    version INT NOT NULL,
    occurred_on DATETIME(6) NOT NULL,
    payload JSON NOT NULL,
    metadata JSON NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    abandoned_at DATETIME(6) NOT NULL,
    INDEX idx_abandoned_at (abandoned_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
