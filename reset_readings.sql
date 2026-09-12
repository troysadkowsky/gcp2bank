-- Drops and recreates `readings` and `load_progress` (the two tables that got
-- contaminated with timezone-shifted data / are just resumability state).
-- `clusters` is intentionally left untouched — its metadata is already correct.

DROP TABLE IF EXISTS load_progress;
DROP TABLE IF EXISTS readings;

CREATE TABLE readings (
  cluster_id TINYINT UNSIGNED NOT NULL,
  ts DATETIME NOT NULL,
  network_coherence FLOAT NOT NULL,
  active_devices SMALLINT UNSIGNED NOT NULL,
  running_total DOUBLE NOT NULL,
  PRIMARY KEY (cluster_id, ts)
) ENGINE=InnoDB;

CREATE TABLE load_progress (
  cluster_id TINYINT UNSIGNED PRIMARY KEY,
  lines_done BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
