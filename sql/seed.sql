INSERT INTO chokepoints (system_id, reason, is_active) VALUES
    (30000142, 'Jita trade hub', 1),
    (30002187, 'Amarr trade hub', 1),
    (30002510, 'Dodixie trade hub', 1),
    (30002659, 'Rens trade hub', 1)
ON DUPLICATE KEY UPDATE reason=VALUES(reason), is_active=VALUES(is_active);
