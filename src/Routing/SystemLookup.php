<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Universe\SystemRepository;

final class SystemLookup
{
    public function __construct(private SystemRepository $systemsRepo)
    {
    }

    public function resolveByNameOrId(string $value): ?array
    {
        if (GraphStore::isLoaded()) {
            return GraphStore::systemByNameOrId($value);
        }

        return $this->systemsRepo->findByNameOrId($value);
    }
}
