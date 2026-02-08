<?php

declare(strict_types=1);

use Everoute\Config\Env;
use Everoute\Cache\RedisCache;
use Everoute\DB\Connection;
use Everoute\Http\ApiController;
use Everoute\Http\ErrorHandler;
use Everoute\Http\Request;
use Everoute\Http\Response;
use Everoute\Http\Router;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpPlanner;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\MovementRules;
use Everoute\Routing\RouteService;
use Everoute\Routing\WeightCalculator;
use Everoute\Security\Csrf;
use Everoute\Security\Logger;
use Everoute\Security\RateLimiter;
use Everoute\Security\Validator;
use Everoute\Universe\GateDistanceRepository;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;

require_once __DIR__ . '/../vendor/autoload.php';

Env::load(dirname(__DIR__));

$logger = new Logger();
$handler = new ErrorHandler($logger, Env::bool('APP_DEBUG', false));
$handler->register();
$trustProxy = Env::bool('TRUST_PROXY_HEADERS', false);
$request = Request::fromGlobals($trustProxy);

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    Env::get('DB_HOST', '127.0.0.1'),
    Env::int('DB_PORT', 3306),
    Env::get('DB_NAME', 'everoute')
);
$connection = new Connection($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''));

$riskCache = RedisCache::fromEnv();
$riskCacheTtl = Env::int('RISK_CACHE_TTL_SECONDS', 60);
$heatmapTtl = Env::int('RISK_HEATMAP_TTL_SECONDS', 30);
$routeCacheTtl = Env::int('ROUTE_CACHE_TTL_SECONDS', 600);

$systems = new SystemRepository($connection);
$stargates = new StargateRepository($connection);
$riskRepo = new RiskRepository($connection, $riskCache, $riskCacheTtl, $heatmapTtl);
$gateDistances = new GateDistanceRepository($connection);
$jumpNeighbors = new JumpNeighborRepository($connection);

$weightCalculator = new WeightCalculator();
$movementRules = new MovementRules();
$jumpRanges = new JumpRangeCalculator(__DIR__ . '/../config/jump_ranges.php');
$jumpPlanner = new JumpPlanner($jumpRanges, $weightCalculator, $movementRules, new JumpFatigueModel(), $logger, $jumpNeighbors);

$routeService = new RouteService(
    $systems,
    $stargates,
    $riskRepo,
    $weightCalculator,
    $movementRules,
    $jumpPlanner,
    $logger,
    $riskCache,
    $routeCacheTtl,
    $riskCacheTtl,
    $gateDistances
);

$validator = new Validator();
$rateLimiter = new RateLimiter(Env::int('RATE_LIMIT_RPS', 5), Env::int('RATE_LIMIT_BURST', 20));
$api = new ApiController($routeService, $riskRepo, $systems, $validator, $rateLimiter);

$router = new Router();
$router->add('GET', '/api/v1/health', [$api, 'health']);
$router->add('POST', '/api/v1/route', [$api, 'route']);
$router->add('GET', '/api/v1/system-risk', [$api, 'systemRisk']);
$router->add('GET', '/api/v1/heatmap', [$api, 'heatmap']);

if (str_starts_with($request->path, '/api/')) {
    $response = $router->dispatch($request);
    $response->send();
    return;
}

$csrf = new Csrf();
$token = $csrf->ensureToken();
$systemOptions = $systems->listAll();

if ($request->method === 'POST') {
    if (!$csrf->validate($_POST['csrf_token'] ?? null)) {
        (new Response(400, ['Content-Type' => 'text/plain'], 'Invalid CSRF token'))->send();
        return;
    }

    $payload = [
        'from' => $_POST['from'] ?? '',
        'to' => $_POST['to'] ?? '',
        'mode' => $_POST['mode'] ?? 'subcap',
        'ship_class' => $_POST['ship_class'] ?? 'subcap',
        'jump_ship_type' => $_POST['jump_ship_type'] ?? '',
        'jump_skill_level' => $_POST['jump_skill_level'] ?? '',
        'safety_vs_speed' => (int) ($_POST['safety_vs_speed'] ?? 50),
        'avoid_lowsec' => !empty($_POST['avoid_lowsec']),
        'avoid_nullsec' => !empty($_POST['avoid_nullsec']),
        'avoid_specific_systems' => $_POST['avoid_specific_systems'] ?? '',
        'prefer_npc_stations' => !empty($_POST['prefer_npc_stations']),
    ];
    $apiRequest = new Request('POST', '/api/v1/route', [], $payload, [], $request->ip);
    $apiResponse = $api->route($apiRequest);
    $result = json_decode($apiResponse->body, true);
} else {
    $result = null;
}

include __DIR__ . '/templates/home.php';
