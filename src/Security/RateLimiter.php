<?php

declare(strict_types=1);

namespace Everoute\Security;

final class RateLimiter
{
    private int $rps;
    private int $burst;
    private string $storageDir;

    public function __construct(int $rps, int $burst, ?string $storageDir = null)
    {
        $this->rps = max(1, $rps);
        $this->burst = max(1, $burst);
        $this->storageDir = $storageDir ?? sys_get_temp_dir() . '/everoute-rate-limit';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
    }

    public function allow(string $key): bool
    {
        $file = $this->storageDir . '/' . sha1($key) . '.json';
        $now = microtime(true);
        $state = [
            'tokens' => $this->burst,
            'last' => $now,
        ];

        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $state = array_merge($state, $data);
            }
        }

        $elapsed = $now - (float) $state['last'];
        $state['tokens'] = min($this->burst, (float) $state['tokens'] + $elapsed * $this->rps);
        $state['last'] = $now;

        if ($state['tokens'] < 1) {
            file_put_contents($file, json_encode($state));
            return false;
        }

        $state['tokens'] -= 1;
        file_put_contents($file, json_encode($state));
        return true;
    }
}
