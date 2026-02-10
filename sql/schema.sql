CREATE TABLE IF NOT EXISTS systems (
    id BIGINT PRIMARY KEY,
    name VARCHAR(128) NOT NULL UNIQUE,
    security DECIMAL(4,2) NOT NULL,
    security_raw DECIMAL(4,2) NOT NULL,
    security_nav DECIMAL(4,2) NOT NULL,
    sec_class VARCHAR(16) NOT NULL DEFAULT 'null',
    near_constellation_boundary TINYINT(1) NOT NULL DEFAULT 0,
    near_region_boundary TINYINT(1) NOT NULL DEFAULT 0,
    legal_mask BIGINT UNSIGNED NOT NULL DEFAULT 0,
    region_id BIGINT NULL,
    constellation_id BIGINT NULL,
    is_wormhole TINYINT(1) NOT NULL DEFAULT 0,
    is_normal_universe TINYINT(1) NOT NULL DEFAULT 0,
    has_npc_station TINYINT(1) NOT NULL DEFAULT 0,
    npc_station_count INT NOT NULL DEFAULT 0,
    x DOUBLE NOT NULL DEFAULT 0,
    y DOUBLE NOT NULL DEFAULT 0,
    z DOUBLE NOT NULL DEFAULT 0,
    system_size_au DOUBLE NOT NULL DEFAULT 1.0,
    updated_at DATETIME NOT NULL,
    INDEX idx_systems_sec_class (sec_class),
    INDEX idx_systems_near_constellation_boundary (near_constellation_boundary),
    INDEX idx_systems_near_region_boundary (near_region_boundary),
    INDEX idx_systems_legal_mask (legal_mask)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stargates (
    id BIGINT PRIMARY KEY,
    from_system_id BIGINT NOT NULL,
    to_system_id BIGINT NOT NULL,
    is_regional_gate TINYINT(1) NOT NULL DEFAULT 0,
    is_constellation_boundary TINYINT(1) NOT NULL DEFAULT 0,
    is_region_boundary TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    INDEX idx_from_system (from_system_id),
    INDEX idx_to_system (to_system_id),
    INDEX idx_regional_gate (is_regional_gate),
    INDEX idx_constellation_boundary (is_constellation_boundary),
    INDEX idx_region_boundary (is_region_boundary),
    CONSTRAINT fk_stargate_from FOREIGN KEY (from_system_id) REFERENCES systems (id) ON DELETE CASCADE,
    CONSTRAINT fk_stargate_to FOREIGN KEY (to_system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gate_distances (
    from_system_id BIGINT NOT NULL,
    to_system_id BIGINT NOT NULL,
    hops SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (from_system_id, to_system_id),
    INDEX idx_gate_dist_to (to_system_id),
    INDEX idx_gate_dist_hops (from_system_id, hops),
    CONSTRAINT fk_gate_dist_from FOREIGN KEY (from_system_id) REFERENCES systems (id) ON DELETE CASCADE,
    CONSTRAINT fk_gate_dist_to FOREIGN KEY (to_system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jump_neighbors (
    system_id BIGINT NOT NULL,
    range_ly SMALLINT UNSIGNED NOT NULL,
    neighbor_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    neighbor_ids_blob MEDIUMBLOB NOT NULL,
    encoding_version TINYINT NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (system_id, range_ly),
    INDEX idx_jump_neighbors_range (range_ly),
    CONSTRAINT fk_jump_neighbors_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS region_hierarchy (
    system_id BIGINT NOT NULL PRIMARY KEY,
    region_id BIGINT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_region_hierarchy_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS constellation_hierarchy (
    system_id BIGINT NOT NULL PRIMARY KEY,
    constellation_id BIGINT NULL,
    region_id BIGINT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_constellation_hierarchy_constellation (constellation_id),
    INDEX idx_constellation_hierarchy_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS constellation_portals (
    constellation_id BIGINT NOT NULL,
    system_id BIGINT NOT NULL,
    has_region_boundary TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (constellation_id, system_id),
    INDEX idx_constellation_portals_system (system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS constellation_edges (
    from_constellation_id BIGINT NOT NULL,
    to_constellation_id BIGINT NOT NULL,
    from_system_id BIGINT NOT NULL,
    to_system_id BIGINT NOT NULL,
    is_region_boundary TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (from_constellation_id, to_constellation_id, from_system_id, to_system_id),
    INDEX idx_constellation_edges_from (from_constellation_id),
    INDEX idx_constellation_edges_to (to_constellation_id),
    INDEX idx_constellation_edges_from_system (from_system_id),
    INDEX idx_constellation_edges_to_system (to_system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS constellation_dist (
    constellation_id BIGINT NOT NULL,
    portal_system_id BIGINT NOT NULL,
    system_id BIGINT NOT NULL,
    gate_dist SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (constellation_id, portal_system_id, system_id),
    INDEX idx_constellation_dist_system (constellation_id, system_id),
    INDEX idx_constellation_dist_portal (constellation_id, portal_system_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stations (
    station_id BIGINT PRIMARY KEY,
    system_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(128) NOT NULL DEFAULT 'npc',
    is_npc TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    INDEX idx_station_system (system_id),
    CONSTRAINT fk_station_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS system_risk (
    system_id BIGINT PRIMARY KEY,
    ship_kills_1h INT NOT NULL DEFAULT 0,
    pod_kills_1h INT NOT NULL DEFAULT 0,
    npc_kills_1h INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    risk_updated_at DATETIME NULL,
    kills_last_1h INT NOT NULL DEFAULT 0,
    kills_last_24h INT NOT NULL DEFAULT 0,
    pod_kills_last_1h INT NOT NULL DEFAULT 0,
    pod_kills_last_24h INT NOT NULL DEFAULT 0,
    last_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_risk_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kill_events (
    killmail_id BIGINT PRIMARY KEY,
    system_id BIGINT NOT NULL,
    happened_at DATETIME NOT NULL,
    victim_ship_type_id BIGINT NULL,
    is_pod_kill TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_kill_events_system_time (system_id, happened_at),
    INDEX idx_kill_events_time (happened_at),
    CONSTRAINT fk_kill_events_system FOREIGN KEY (system_id) REFERENCES systems (id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS precompute_checkpoints (
    job_key VARCHAR(64) PRIMARY KEY,
    `cursor` BIGINT NULL,
    `meta` JSON NULL,
    started_at DATETIME NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_import_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_meta (
    provider VARCHAR(64) PRIMARY KEY,
    etag VARCHAR(255) NULL,
    last_modified DATETIME NULL,
    checked_at DATETIME NULL,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sde_meta (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    build_number BIGINT NOT NULL,
    variant VARCHAR(64) NOT NULL,
    installed_at DATETIME NOT NULL,
    source_url VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    INDEX idx_sde_meta_build (build_number),
    INDEX idx_sde_meta_installed (installed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS static_meta (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    active_sde_build_number BIGINT NULL,
    precompute_version INT NOT NULL,
    built_at DATETIME NOT NULL,
    active_build_id VARCHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO static_meta (id, active_sde_build_number, precompute_version, built_at, active_build_id)
VALUES (1, NULL, 1, NOW(), NULL)
ON DUPLICATE KEY UPDATE precompute_version = VALUES(precompute_version);
