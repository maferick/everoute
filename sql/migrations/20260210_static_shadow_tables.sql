-- Static artifact shadow-table migration

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
