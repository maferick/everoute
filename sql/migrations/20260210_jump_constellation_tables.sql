
CREATE TABLE IF NOT EXISTS jump_constellation_portals (
    constellation_id BIGINT NOT NULL,
    range_ly SMALLINT UNSIGNED NOT NULL,
    system_id BIGINT NOT NULL,
    outbound_constellations_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (constellation_id, range_ly, system_id),
    INDEX idx_jump_constellation_portals_range (range_ly, constellation_id),
    INDEX idx_jump_constellation_portals_system (system_id, range_ly)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jump_constellation_edges (
    range_ly SMALLINT UNSIGNED NOT NULL,
    from_constellation_id BIGINT NOT NULL,
    to_constellation_id BIGINT NOT NULL,
    example_from_system_id BIGINT NOT NULL,
    example_to_system_id BIGINT NOT NULL,
    min_hop_ly DECIMAL(8,3) NOT NULL,
    PRIMARY KEY (range_ly, from_constellation_id, to_constellation_id),
    INDEX idx_jump_constellation_edges_from (from_constellation_id, range_ly),
    INDEX idx_jump_constellation_edges_to (to_constellation_id, range_ly)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jump_midpoint_candidates (
    constellation_id BIGINT NOT NULL,
    range_ly SMALLINT UNSIGNED NOT NULL,
    system_id BIGINT NOT NULL,
    score DECIMAL(10,3) NOT NULL,
    PRIMARY KEY (constellation_id, range_ly, system_id),
    INDEX idx_jump_midpoint_candidates_range (range_ly, constellation_id),
    INDEX idx_jump_midpoint_candidates_score (range_ly, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

