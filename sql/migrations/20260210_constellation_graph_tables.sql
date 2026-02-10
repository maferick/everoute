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
