<?php

declare(strict_types=1);

$routes = $result['routes'] ?? [];
$tabs = [
    'fast' => 'Fast',
    'balanced' => 'Balanced',
    'safe' => 'Safe',
];
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
<div class="container">
    <header>
        <h1>Everoute</h1>
        <p class="tagline">Explainable risk-aware routing for EVE Online.</p>
    </header>

    <section class="card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <div class="grid">
                <label>
                    From
                    <input name="from" list="systems" required value="<?= htmlspecialchars($_POST['from'] ?? '', ENT_QUOTES) ?>">
                </label>
                <label>
                    To
                    <input name="to" list="systems" required value="<?= htmlspecialchars($_POST['to'] ?? '', ENT_QUOTES) ?>">
                </label>
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
                        $classes = ['interceptor', 'subcap', 'dst', 'freighter', 'capital', 'jump_freighter'];
                        foreach ($classes as $class) {
                            $selected = (($_POST['ship_class'] ?? 'subcap') === $class) ? 'selected' : '';
                            echo "<option value=\"{$class}\" {$selected}>{$class}</option>";
                        }
                        ?>
                    </select>
                </label>
                <label>
                    Safety vs Speed
                    <input type="range" name="safety_vs_speed" min="0" max="100" value="<?= htmlspecialchars((string) ($_POST['safety_vs_speed'] ?? 50), ENT_QUOTES) ?>">
                </label>
            </div>
            <div class="toggles">
                <label><input type="checkbox" name="avoid_lowsec" <?= !empty($_POST['avoid_lowsec']) ? 'checked' : '' ?>> Avoid lowsec</label>
                <label><input type="checkbox" name="avoid_nullsec" <?= !empty($_POST['avoid_nullsec']) ? 'checked' : '' ?>> Avoid nullsec</label>
                <label><input type="checkbox" name="prefer_npc_stations" <?= !empty($_POST['prefer_npc_stations']) ? 'checked' : '' ?>> Prefer NPC stations</label>
            </div>
            <label>
                Avoid specific systems (comma separated)
                <input name="avoid_specific_systems" value="<?= htmlspecialchars($_POST['avoid_specific_systems'] ?? '', ENT_QUOTES) ?>">
            </label>
            <button type="submit">Plan Route</button>
        </form>
    </section>

    <section class="card">
        <h2>Results</h2>
        <?php if ($result && empty($result['error'])): ?>
            <p class="muted">Risk data updated at: <?= htmlspecialchars((string) ($result['risk_updated_at'] ?? 'n/a'), ENT_QUOTES) ?></p>
            <div class="tabs" data-tabs>
                <div class="tab-buttons">
                    <?php foreach ($tabs as $key => $label): ?>
                        <button type="button" data-tab="<?= $key ?>" class="tab-button<?= $key === 'balanced' ? ' active' : '' ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($tabs as $key => $label): ?>
                    <?php $route = $routes[$key] ?? null; ?>
                    <div class="tab-panel<?= $key === 'balanced' ? ' active' : '' ?>" data-tab-panel="<?= $key ?>">
                        <?php if ($route && empty($route['error'])): ?>
                            <div class="stats">
                                <div>Total jumps: <?= $route['total_jumps'] ?></div>
                                <div>Exposure score: <?= $route['exposure_score'] ?></div>
                                <div>Risk score: <?= $route['risk_score'] ?></div>
                                <div>Travel proxy: <?= $route['travel_time_proxy'] ?>s</div>
                            </div>
                            <h3>Why this route?</h3>
                            <ul>
                                <li>Top risk systems: <?= htmlspecialchars(implode(', ', array_map(static fn ($r) => $r['name'], $route['why']['top_risk_systems'] ?? [])), ENT_QUOTES) ?></li>
                                <li>Avoided hotspots: <?= htmlspecialchars(implode(', ', $route['why']['avoided_hotspots'] ?? []), ENT_QUOTES) ?></li>
                                <li>Tradeoffs: <?= htmlspecialchars(json_encode($route['why']['key_tradeoffs'] ?? []), ENT_QUOTES) ?></li>
                            </ul>
                            <h3>Route</h3>
                            <ol>
                                <?php foreach ($route['systems'] as $system): ?>
                                    <li>
                                        <?= htmlspecialchars($system['name'], ENT_QUOTES) ?>
                                        <span class="badge">Sec <?= $system['security'] ?></span>
                                        <?php if ($system['chokepoint']): ?><span class="badge danger">Chokepoint</span><?php endif; ?>
                                        <?php if ($system['npc_station']): ?><span class="badge">NPC station</span><?php endif; ?>
                                        <span class="badge">Risk <?= round($system['risk'], 1) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <?php if (!empty($route['midpoints'])): ?>
                                <h3>Midpoint Suggestions</h3>
                                <ul>
                                    <?php foreach ($route['midpoints'] as $mid): ?>
                                        <li><?= htmlspecialchars($mid['system_name'], ENT_QUOTES) ?> (risk <?= $mid['risk_score'] ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>No route data available.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif ($result && !empty($result['error'])): ?>
            <p class="error">Error: <?= htmlspecialchars($result['error'], ENT_QUOTES) ?></p>
        <?php else: ?>
            <p class="muted">Submit a route request to see results.</p>
        <?php endif; ?>
    </section>
</div>
<datalist id="systems">
    <?php foreach ($systemOptions as $system): ?>
        <option value="<?= htmlspecialchars($system['name'], ENT_QUOTES) ?>"></option>
    <?php endforeach; ?>
</datalist>
<script src="/assets/app.js"></script>
</body>
</html>
