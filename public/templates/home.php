<?php

declare(strict_types=1);

$engineRoutes = $result ? [
    'Gate' => $result['gate_route'] ?? null,
    'Jump' => $result['jump_route'] ?? null,
    'Hybrid' => $result['hybrid_route'] ?? null,
] : [];

$effectiveRange = $result['effective_range_ly'] ?? null;
$hasResult = $result && empty($result['error']);
$best = $result['best'] ?? null;

$shipLabel = '';
if (!empty($_POST['mode']) && $_POST['mode'] === 'capital') {
    $shipLabel = (string) ($_POST['jump_ship_type'] ?? 'capital');
} else {
    $shipLabel = (string) ($_POST['ship_class'] ?? 'subcap');
}
$jumpSkillLevel = (string) ($_POST['jump_skill_level'] ?? '5');

$formatLy = static function (?float $value): string {
    if ($value === null) {
        return 'n/a';
    }
    return number_format($value, 2);
};

$formatSecurity = static function (array $system): string {
    $raw = $system['security_raw'] ?? null;
    $security = is_numeric($raw) ? round((float) $raw, 1) : round((float) ($system['security'] ?? 0.0), 1);
    return number_format($security, 1);
};

$buildRouteKey = static function (array $segments): string {
    return implode('|', array_map(
        static fn (array $segment) => sprintf(
            '%s-%s-%s',
            $segment['from_id'] ?? $segment['from'] ?? 'from',
            $segment['to_id'] ?? $segment['to'] ?? 'to',
            $segment['type'] ?? 'gate'
        ),
        $segments
    ));
};

$buildJumpStats = static function (array $segments): array {
    $jumpSegments = [];
    $total = 0.0;
    $max = 0.0;
    foreach ($segments as $segment) {
        if (($segment['type'] ?? 'gate') !== 'jump') {
            continue;
        }
        $distance = (float) ($segment['distance_ly'] ?? 0.0);
        $jumpSegments[] = [
            'from' => $segment['from'] ?? 'Unknown',
            'to' => $segment['to'] ?? 'Unknown',
            'distance' => $distance,
        ];
        $total += $distance;
        $max = max($max, $distance);
    }

    $hops = count($jumpSegments);

    return [
        'segments' => $jumpSegments,
        'total' => $total,
        'max' => $max,
        'hops' => $hops,
        'avg' => $hops > 0 ? $total / $hops : 0.0,
    ];
};

$buildRouteSteps = static function (array $route): array {
    $systems = $route['systems'] ?? [];
    $segments = $route['segments'] ?? [];
    $steps = [];
    foreach ($systems as $index => $system) {
        $segment = $segments[$index - 1] ?? null;
        $steps[] = [
            'step' => $index + 1,
            'system' => $system['name'] ?? 'Unknown',
            'security' => $system,
            'type' => $segment['type'] ?? ($index === 0 ? 'start' : 'gate'),
            'hop_ly' => $segment['distance_ly'] ?? null,
            'npc' => $system['has_npc_station'] ?? null,
        ];
    }

    return $steps;
};

$gateKey = null;
if (!empty($engineRoutes['Gate']['segments'])) {
    $gateKey = $buildRouteKey($engineRoutes['Gate']['segments']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Everoute - EVE Navigation Standard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="page">
    <header class="site-header">
        <div>
            <p class="eyebrow">Everoute Navigator</p>
            <h1>Everoute</h1>
            <p class="tagline">Plan safer, smarter EVE routes with explainable risk and jump-aware routing.</p>
        </div>
        <div class="header-meta">
            <span class="pill">Risk-aware routing</span>
            <span class="pill">Capital jump planning</span>
        </div>
    </header>

    <main class="layout">
        <section class="card planner-card">
            <form method="post" data-route-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
                <input type="hidden" name="from_id" value="<?= htmlspecialchars($_POST['from_id'] ?? '', ENT_QUOTES) ?>" data-system-id="from">
                <input type="hidden" name="to_id" value="<?= htmlspecialchars($_POST['to_id'] ?? '', ENT_QUOTES) ?>" data-system-id="to">

                <div class="form-section">
                    <div class="section-title">Route</div>
                    <div class="grid two-col">
                        <label class="autocomplete">
                            From
                            <input name="from" required autocomplete="off" data-autocomplete="from" value="<?= htmlspecialchars($_POST['from'] ?? '', ENT_QUOTES) ?>">
                        </label>
                        <label class="autocomplete">
                            To
                            <input name="to" required autocomplete="off" data-autocomplete="to" value="<?= htmlspecialchars($_POST['to'] ?? '', ENT_QUOTES) ?>">
                        </label>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">Mode</div>
                    <div class="grid two-col">
                        <label>
                            Mode
                            <select name="mode">
                                <option value="hauling" <?= (($_POST['mode'] ?? '') === 'hauling') ? 'selected' : '' ?>>Hauling</option>
                                <option value="subcap" <?= (($_POST['mode'] ?? 'subcap') === 'subcap') ? 'selected' : '' ?>>Subcap</option>
                                <option value="capital" <?= (($_POST['mode'] ?? '') === 'capital') ? 'selected' : '' ?>>Capital / JF</option>
                            </select>
                        </label>
                        <label>
                            Ship Class
                            <select name="ship_class">
                                <?php
                                $classes = ['interceptor', 'subcap', 'dst', 'freighter', 'capital', 'jump_freighter', 'super', 'titan'];
                                foreach ($classes as $class) {
                                    $selected = (($_POST['ship_class'] ?? 'subcap') === $class) ? 'selected' : '';
                                    echo "<option value=\"{$class}\" {$selected}>{$class}</option>";
                                }
                                ?>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="form-section capital-only">
                    <div class="section-title">Capital specifics</div>
                    <div class="grid two-col">
                        <label>
                            Jump Ship Type
                            <select name="jump_ship_type">
                                <?php
                                $jumpTypes = ['carrier', 'dread', 'fax', 'jump_freighter', 'supercarrier', 'titan'];
                                $selectedType = $_POST['jump_ship_type'] ?? '';
                                foreach ($jumpTypes as $type) {
                                    $selected = ($selectedType === $type) ? 'selected' : '';
                                    echo "<option value=\"{$type}\" {$selected}>{$type}</option>";
                                }
                                ?>
                            </select>
                        </label>
                        <label>
                            Jump Range Skill Level
                            <select name="jump_skill_level">
                                <?php
                                $selectedLevel = $_POST['jump_skill_level'] ?? '5';
                                for ($level = 0; $level <= 5; $level++) {
                                    $selected = ((string) $level === (string) $selectedLevel) ? 'selected' : '';
                                    echo "<option value=\"{$level}\" {$selected}>{$level}</option>";
                                }
                                ?>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">Policy</div>
                    <label class="range">
                        Safety vs Speed
                        <div class="range-row">
                            <input type="range" name="safety_vs_speed" min="0" max="100" value="<?= htmlspecialchars((string) ($_POST['safety_vs_speed'] ?? 50), ENT_QUOTES) ?>" data-range>
                            <span class="range-value" data-range-value><?= htmlspecialchars((string) ($_POST['safety_vs_speed'] ?? 50), ENT_QUOTES) ?>%</span>
                        </div>
                    </label>
                    <div class="toggle-row">
                        <label class="toggle"><input type="checkbox" name="avoid_lowsec" <?= !empty($_POST['avoid_lowsec']) ? 'checked' : '' ?>> Avoid lowsec</label>
                        <label class="toggle"><input type="checkbox" name="avoid_nullsec" <?= !empty($_POST['avoid_nullsec']) ? 'checked' : '' ?>> Avoid nullsec</label>
                        <label class="toggle"><input type="checkbox" name="prefer_npc_stations" <?= !empty($_POST['prefer_npc_stations']) ? 'checked' : '' ?>> Prefer NPC stations</label>
                        <label class="toggle"><input type="checkbox" name="debug" <?= !empty($_POST['debug']) ? 'checked' : '' ?>> Debug</label>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">Avoid systems</div>
                    <label>
                        Systems to avoid (comma separated)
                        <input name="avoid_specific_systems" value="<?= htmlspecialchars($_POST['avoid_specific_systems'] ?? '', ENT_QUOTES) ?>">
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary" data-submit>
                        <span class="button-text">Plan Route</span>
                        <span class="button-spinner" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </section>

        <section class="card results-card">
            <div class="results-header">
                <h2>Results</h2>
                <?php if ($hasResult): ?>
                    <div class="meta">CCP ESI system kills (last hour) — updated: <?= htmlspecialchars((string) ($result['risk_updated_at'] ?? 'n/a'), ENT_QUOTES) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($hasResult): ?>
                <?php if ($best && $best !== 'none'): ?>
                    <p class="muted">Best option: <?= htmlspecialchars($best, ENT_QUOTES) ?></p>
                <?php endif; ?>
                <p class="muted">Risk is based on ship/pod kills in the last hour from CCP ESI; systems not listed are treated as zero.</p>

                <div class="route-grid">
                    <?php foreach ($engineRoutes as $label => $route): ?>
                        <?php if ($route === null): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php
                        $segments = $route['segments'] ?? [];
                        $jumpStats = $buildJumpStats($segments);
                        $gateCount = count(array_filter($segments, static fn (array $seg) => ($seg['type'] ?? 'gate') === 'gate'));
                        $jumpCount = $jumpStats['hops'];
                        $isFeasible = !empty($route['feasible']);
                        $fatigue = $route['fatigue'] ?? null;
                        $cooldownTotal = $fatigue['cooldown_total_minutes'] ?? null;
                        $fatigueLabel = $fatigue['fatigue_risk_label'] ?? null;
                        $sameAsGate = $label === 'Hybrid' && $gateKey && $segments && $gateKey === $buildRouteKey($segments);
                        $stepsCount = count($segments);
                        $systems = $route['systems'] ?? [];
                        $fromName = $systems[0]['name'] ?? ($segments[0]['from'] ?? 'Unknown');
                        $toName = $stepsCount > 0
                            ? ($systems[$stepsCount]['name'] ?? ($segments[$stepsCount - 1]['to'] ?? 'Unknown'))
                            : $fromName;
                        ?>
                        <article class="route-card">
                            <div class="route-card-header">
                                <h3><?= htmlspecialchars($label, ENT_QUOTES) ?></h3>
                                <?php if ($isFeasible): ?>
                                    <span class="badge success">Feasible</span>
                                <?php else: ?>
                                    <span class="badge danger">Not feasible</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isFeasible): ?>
                                <div class="kpi-grid">
                                    <div class="kpi">
                                        <span class="kpi-label">Gate count</span>
                                        <span class="kpi-value"><?= htmlspecialchars((string) $gateCount, ENT_QUOTES) ?></span>
                                    </div>
                                    <?php if ($jumpCount > 0): ?>
                                        <div class="kpi">
                                            <span class="kpi-label">Jump hops</span>
                                            <span class="kpi-value"><?= htmlspecialchars((string) $jumpCount, ENT_QUOTES) ?></span>
                                        </div>
                                        <div class="kpi">
                                            <span class="kpi-label">Total LY jumped</span>
                                            <span class="kpi-value"><?= htmlspecialchars($formatLy($jumpStats['total']), ENT_QUOTES) ?></span>
                                        </div>
                                        <div class="kpi">
                                            <span class="kpi-label">Max hop LY</span>
                                            <span class="kpi-value"><?= htmlspecialchars($formatLy($jumpStats['max']), ENT_QUOTES) ?></span>
                                        </div>
                                        <?php if ($effectiveRange !== null): ?>
                                            <div class="kpi">
                                                <span class="kpi-label">Effective range used</span>
                                                <span class="kpi-value"><?= htmlspecialchars(number_format((float) $effectiveRange, 1), ENT_QUOTES) ?> LY <?= htmlspecialchars($shipLabel, ENT_QUOTES) ?> JDC<?= htmlspecialchars($jumpSkillLevel, ENT_QUOTES) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="kpi">
                                            <span class="kpi-label">Cooldown + fatigue</span>
                                            <span class="kpi-value"><?= $cooldownTotal !== null ? htmlspecialchars($formatLy((float) $cooldownTotal) . ' min', ENT_QUOTES) : 'n/a' ?><?= $fatigueLabel ? ' · ' . htmlspecialchars($fatigueLabel, ENT_QUOTES) : '' ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="kpi">
                                        <span class="kpi-label">Fuel estimate</span>
                                        <span class="kpi-value">TBD</span>
                                    </div>
                                </div>

                                <div class="engine-metrics">
                                    <span>Nodes explored: <?= htmlspecialchars((string) ($route['nodes_explored'] ?? 0), ENT_QUOTES) ?></span>
                                    <span>Illegal filtered: <?= htmlspecialchars((string) ($route['illegal_systems_filtered'] ?? 0), ENT_QUOTES) ?></span>
                                </div>

                                <?php if ($sameAsGate): ?>
                                    <p class="muted">Same as Gate route — no additional hybrid hops needed.</p>
                                <?php endif; ?>

                                <?php if (!$sameAsGate): ?>
                                    <div class="route-breadcrumb">
                                        <?= htmlspecialchars($fromName, ENT_QUOTES) ?>
                                        <span class="arrow">→</span>
                                        <span class="muted">(… <?= $stepsCount ?> steps …)</span>
                                        <span class="arrow">→</span>
                                        <?= htmlspecialchars($toName, ENT_QUOTES) ?>
                                    </div>

                                    <details class="route-details">
                                        <summary>View full route</summary>
                                        <div class="table-wrap">
                                            <table>
                                                <thead>
                                                <tr>
                                                    <th>Step</th>
                                                    <th>System</th>
                                                    <th>Sec</th>
                                                    <th>Type</th>
                                                    <th>Hop LY</th>
                                                    <th>NPC</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($buildRouteSteps($route) as $step): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars((string) $step['step'], ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars((string) $step['system'], ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars($formatSecurity($step['security']), ENT_QUOTES) ?></td>
                                                        <td><?= htmlspecialchars(ucfirst((string) $step['type']), ENT_QUOTES) ?></td>
                                                        <td><?= $step['hop_ly'] !== null ? htmlspecialchars($formatLy((float) $step['hop_ly']), ENT_QUOTES) : '—' ?></td>
                                                        <td><?= $step['npc'] ? 'NPC' : '—' ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>

                                    <?php if ($jumpCount > 0): ?>
                                        <div class="jump-segments">
                                            <h4>Jump segments</h4>
                                            <ul>
                                                <?php foreach ($jumpStats['segments'] as $segment): ?>
                                                    <li><?= htmlspecialchars($segment['from'], ENT_QUOTES) ?> → <?= htmlspecialchars($segment['to'], ENT_QUOTES) ?> (<?= htmlspecialchars($formatLy($segment['distance']), ENT_QUOTES) ?> LY)</li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <div class="jump-totals">
                                                <span>Total LY: <?= htmlspecialchars($formatLy($jumpStats['total']), ENT_QUOTES) ?></span>
                                                <span>Hops: <?= htmlspecialchars((string) $jumpStats['hops'], ENT_QUOTES) ?></span>
                                                <span>Max hop: <?= htmlspecialchars($formatLy($jumpStats['max']), ENT_QUOTES) ?></span>
                                                <span>Avg hop: <?= htmlspecialchars($formatLy($jumpStats['avg']), ENT_QUOTES) ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="error">Not feasible: <?= htmlspecialchars((string) ($route['reason'] ?? 'Unknown reason'), ENT_QUOTES) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($result['explanation'])): ?>
                    <div class="explanation">
                        <h3>Why this route?</h3>
                        <ul>
                            <?php foreach ($result['explanation'] as $line): ?>
                                <li><?= htmlspecialchars((string) $line, ENT_QUOTES) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($result['debug'])): ?>
                    <?php
                    $debugDisplay = $result['debug'];
                    unset($debugDisplay['jump_origin']);
                    ?>
                    <details class="debug-details">
                        <summary>Engine diagnostics</summary>
                        <pre><?= htmlspecialchars(json_encode($debugDisplay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></pre>
                    </details>
                <?php endif; ?>
            <?php elseif ($result && !empty($result['error'])): ?>
                <p class="error">Error: <?= htmlspecialchars($result['error'], ENT_QUOTES) ?></p>
                <?php if (!empty($result['reason'])): ?>
                    <p class="error"><?= htmlspecialchars((string) $result['reason'], ENT_QUOTES) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <h3>Plan a route to get started</h3>
                    <p class="muted">Enter origin/destination systems to compare gate, jump, and hybrid options.</p>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="/assets/app.js"></script>
</body>
</html>
