<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class ConstellationGraphRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array<int, array<int, array{to_constellation_id:int,from_system_id:int,to_system_id:int,is_region_boundary:bool}>>
     */
    public function edgeMap(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT from_constellation_id, to_constellation_id, from_system_id, to_system_id, is_region_boundary
             FROM constellation_edges'
        );

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $fromConstellationId = (int) $row['from_constellation_id'];
            $map[$fromConstellationId][] = [
                'to_constellation_id' => (int) $row['to_constellation_id'],
                'from_system_id' => (int) $row['from_system_id'],
                'to_system_id' => (int) $row['to_system_id'],
                'is_region_boundary' => !empty($row['is_region_boundary']),
            ];
        }

        return $map;
    }

    /** @return array<int, array<int, array{system_id:int,has_region_boundary:bool}>> */
    public function portalsByConstellation(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT constellation_id, system_id, has_region_boundary FROM constellation_portals'
        );

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $constellationId = (int) $row['constellation_id'];
            $map[$constellationId][] = [
                'system_id' => (int) $row['system_id'],
                'has_region_boundary' => !empty($row['has_region_boundary']),
            ];
        }

        return $map;
    }

    /** @return array<int, array<int, int>> */
    public function portalDistancesForConstellation(int $constellationId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT portal_system_id, system_id, gate_dist FROM constellation_dist WHERE constellation_id = :constellation_id'
        );
        $stmt->execute(['constellation_id' => $constellationId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $portalId = (int) $row['portal_system_id'];
            $systemId = (int) $row['system_id'];
            $map[$portalId][$systemId] = (int) $row['gate_dist'];
        }

        return $map;
    }


    /**
     * @return array<int, array<int, array{to_constellation_id:int,example_from_system_id:int,example_to_system_id:int,min_hop_ly:float}>>
     */
    public function jumpEdgeMap(int $rangeLy): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT from_constellation_id, to_constellation_id, example_from_system_id, example_to_system_id, min_hop_ly
             FROM jump_constellation_edges
             WHERE range_ly = :range_ly'
        );
        $stmt->execute(['range_ly' => $rangeLy]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $fromConstellationId = (int) $row['from_constellation_id'];
            $map[$fromConstellationId][] = [
                'to_constellation_id' => (int) $row['to_constellation_id'],
                'example_from_system_id' => (int) $row['example_from_system_id'],
                'example_to_system_id' => (int) $row['example_to_system_id'],
                'min_hop_ly' => (float) $row['min_hop_ly'],
            ];
        }

        return $map;
    }

    /** @return array<int, int[]> */
    public function jumpPortalsByConstellation(int $rangeLy): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT constellation_id, system_id
             FROM jump_constellation_portals
             WHERE range_ly = :range_ly
             ORDER BY constellation_id, outbound_constellations_count DESC, system_id'
        );
        $stmt->execute(['range_ly' => $rangeLy]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['constellation_id']][] = (int) $row['system_id'];
        }

        return $map;
    }

    /** @return array<int, int[]> */
    public function jumpMidpointsByConstellation(int $rangeLy): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT constellation_id, system_id
             FROM jump_midpoint_candidates
             WHERE range_ly = :range_ly
             ORDER BY constellation_id, score DESC, system_id'
        );
        $stmt->execute(['range_ly' => $rangeLy]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['constellation_id']][] = (int) $row['system_id'];
        }

        return $map;
    }
}
