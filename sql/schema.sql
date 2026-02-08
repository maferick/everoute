CREATE TABLE IF NOT EXISTS systems (
    id BIGINT PRIMARY KEY,
    name VARCHAR(128) NOT NULL UNIQUE,
    security DECIMAL(4,2) NOT NULL,
    region_id BIGINT NULL,
    constellation_id BIGINT NULL,
    x DOUBLE NOT NULL DEFAULT 0,
    y DOUBLE NOT NULL DEFAULT 0,
    z DOUBLE NOT NULL DEFAULT 0,
    system_size_au DOUBLE NOT NULL DEFAULT 1.0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stargates (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    from_system_id BIGINT NOT NULL,
    to_system_id BIGINT NOT NULL,
    INDEX idx_from_system (from_system_id),
    INDEX idx_to_system (to_system_id),
    CONSTRAINT fk_stargate_from FOREIGN KEY (from_system_id) REFERENCES systems (id) ON DELETE CASCADE,
    CONSTRAINT fk_stargate_to FOREIGN KEY (to_system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stations (
    station_id BIGINT PRIMARY KEY,
    system_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(128) NOT NULL,
    is_npc TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_station_system (system_id),
    CONSTRAINT fk_station_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_risk (
    system_id BIGINT PRIMARY KEY,
    kills_last_1h INT NOT NULL DEFAULT 0,
    kills_last_24h INT NOT NULL DEFAULT 0,
    pod_kills_last_1h INT NOT NULL DEFAULT 0,
    pod_kills_last_24h INT NOT NULL DEFAULT 0,
    last_updated_at DATETIME NOT NULL,
    CONSTRAINT fk_risk_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chokepoints (
    system_id BIGINT PRIMARY KEY,
    reason VARCHAR(255) NULL,
    category VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_chokepoint_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS route_cache (
    cache_key VARCHAR(128) PRIMARY KEY,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_import_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
