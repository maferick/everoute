CREATE TABLE IF NOT EXISTS route_jobs (
    id CHAR(36) PRIMARY KEY,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    status ENUM('queued','running','done','failed','canceled') NOT NULL,
    request_json LONGTEXT NOT NULL,
    result_json LONGTEXT NULL,
    progress_json TEXT NULL,
    error_text TEXT NULL,
    lock_token CHAR(36) NULL,
    expires_at DATETIME NULL,
    INDEX idx_route_jobs_status_created (status, created_at),
    INDEX idx_route_jobs_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
