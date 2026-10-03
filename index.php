<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

define('AUDIT_WEBHOOK_URL', getenv('AUDIT_WEBHOOK_URL') ?: "https://discord.com/api/webhooks/1544605127950864466/T-FsN7Aa3fySEuMcXuYU31PU8rm3tefe71LdwcSRTJHQxNaWmprM-UaOKt2CtrMoCS-n");
define('ACCOUNTING_WEBHOOK_URL', getenv('ACCOUNTING_WEBHOOK_URL') ?: AUDIT_WEBHOOK_URL);
define('PNP_PROOF_WEBHOOK_URL', getenv('PNP_PROOF_WEBHOOK_URL') ?: AUDIT_WEBHOOK_URL);
define('GOV_PROOF_WEBHOOK_URL', getenv('GOV_PROOF_WEBHOOK_URL') ?: AUDIT_WEBHOOK_URL);
define('CITIZEN_REGISTRY_WEBHOOK_URL', getenv('CITIZEN_REGISTRY_WEBHOOK_URL') ?: AUDIT_WEBHOOK_URL);

define('MINIMUM_SHIFTS_REQUIRED', 3);

function getDBConnection(): PDO {
    $host = getenv('DB_HOST') ?: 'mysql-3272a288-carlomiguelking93-a176.f.aivencloud.com';
    $db   = getenv('DB_NAME') ?: 'defaultdb';
    $user = getenv('DB_USER') ?: 'avnadmin';
    $pass = getenv('DB_PASS') ?: 'AVNS_KG-eoi0GF2BkwXvY6wM';
    $port = getenv('DB_PORT') ?: '17577';

    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        sendJsonResponse(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()], 500);
        exit();
    }
}

function createTablesIfNotExists(PDO $pdo): void {
    $tables = [
        "CREATE TABLE IF NOT EXISTS staff_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, badge_id VARCHAR(50) UNIQUE NOT NULL, department VARCHAR(20) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'MEMBER', status VARCHAR(20) NOT NULL DEFAULT 'PENDING', token VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS officers (id INT AUTO_INCREMENT PRIMARY KEY, badge_no VARCHAR(50) UNIQUE NOT NULL, name VARCHAR(255) NOT NULL, rank VARCHAR(100) NOT NULL, points INT DEFAULT 0, discord_id VARCHAR(100) DEFAULT NULL)",
        "CREATE TABLE IF NOT EXISTS government_members (id INT AUTO_INCREMENT PRIMARY KEY, id_no VARCHAR(50) UNIQUE NOT NULL, name VARCHAR(255) NOT NULL, position VARCHAR(255) NOT NULL, points INT DEFAULT 0, discord_id VARCHAR(100) DEFAULT NULL)",
        "CREATE TABLE IF NOT EXISTS activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, action_type VARCHAR(255) NOT NULL, user_details VARCHAR(255) NOT NULL, department VARCHAR(10) NOT NULL DEFAULT 'PNP', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS salaries (id INT AUTO_INCREMENT PRIMARY KEY, department VARCHAR(20) NOT NULL, title VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, badge_id VARCHAR(50) NOT NULL UNIQUE, base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00, incentives DECIMAL(10,2) NOT NULL DEFAULT 0.00, status VARCHAR(20) NOT NULL DEFAULT 'unpaid', days_attended INT NOT NULL DEFAULT 0, daily_proof_count INT NOT NULL DEFAULT 0, last_duty_date DATE DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS treasury (id INT PRIMARY KEY DEFAULT 1, amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS certificates (id INT AUTO_INCREMENT PRIMARY KEY, ref_code VARCHAR(100) UNIQUE NOT NULL, personnel_name VARCHAR(255) NOT NULL, id_or_badge VARCHAR(50) NOT NULL, template_type VARCHAR(100) NOT NULL, issued_date VARCHAR(50) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS registered_citizens (id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(255) NOT NULL, discord_tag VARCHAR(100) NOT NULL, category ENUM('civ', 'government', 'pnp') NOT NULL, rank_or_position VARCHAR(100) DEFAULT 'Citizen', status VARCHAR(50) DEFAULT 'Active', registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("INSERT IGNORE INTO treasury (id, amount) VALUES (1, 0.00)");
}

function sendJsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

function getJsonInput(): array {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
}

function calculateWeeklySalary(string $department, float $baseSalary, float $incentives, int $daysAttended): float {
    if ($daysAttended < MINIMUM_SHIFTS_REQUIRED) {
        return $incentives;
    }
    $earnedWeeklyBase = ($baseSalary / 4.0) * (min($daysAttended, 7) / 7.0);
    return $earnedWeeklyBase + $incentives;
}

function sendDiscordWebhook(string $webhookUrl, $payload) {
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

$pdo = getDBConnection();
createTablesIfNotExists($pdo);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/' || $path === '/api') {
    sendJsonResponse(["status" => "online", "message" => "Region IX API Server is Running"]);
}

if ($path === '/api/stats' && $method === 'GET') {
    $off = $pdo->query("SELECT COUNT(*) FROM officers")->fetchColumn();
    $gov = $pdo->query("SELECT COUNT(*) FROM government_members")->fetchColumn();
    $cit = $pdo->query("SELECT COUNT(*) FROM registered_citizens")->fetchColumn();
    sendJsonResponse(['total_officers' => (int)$off, 'total_gov_members' => (int)$gov, 'total_citizens' => (int)$cit]);
}

if ($path === '/api/activity' && $method === 'GET') {
    $stmt = $pdo->query("SELECT id, action_type AS action, user_details AS user, department FROM activity_logs ORDER BY created_at DESC LIMIT 10");
    sendJsonResponse($stmt->fetchAll());
}

if ($path === '/api/officers' && $method === 'GET') {
    $stmt = $pdo->query("SELECT badge_no AS badge, name, rank, points FROM officers ORDER BY points DESC");
    sendJsonResponse($stmt->fetchAll());
}

if ($path === '/api/government' && $method === 'GET') {
    $stmt = $pdo->query("SELECT id_no AS id, name, position, points FROM government_members ORDER BY points DESC");
    sendJsonResponse($stmt->fetchAll());
}

if ($path === '/api/citizens' && $method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM registered_citizens ORDER BY registered_at DESC");
    sendJsonResponse(['status' => 'success', 'data' => $stmt->fetchAll()]);
}

if ($path === '/api/citizens' && $method === 'POST') {
    $input = getJsonInput();
    $fullName = trim($input['full_name'] ?? '');
    $discordTag = trim($input['discord_tag'] ?? '');
    $category = trim($input['category'] ?? 'civ');
    $position = trim($input['rank_or_position'] ?? 'Citizen');

    if (!$fullName || !$discordTag) {
        sendJsonResponse(['status' => 'error', 'message' => 'Full Name and Discord Tag are required.'], 400);
    }

    $stmt = $pdo->prepare("INSERT INTO registered_citizens (full_name, discord_tag, category, rank_or_position) VALUES (?, ?, ?, ?)");
    $stmt->execute([$fullName, $discordTag, $category, $position]);

    $colorMap = ['pnp' => 3447003, 'government' => 15844367, 'civ' => 5763719];
    $embedColor = $colorMap[$category] ?? 5763719;

    $webhookPayload = [
        "username" => "Region IX Citizen Registry",
        "embeds" => [[
            "title" => "📋 New Official Registration: " . strtoupper($category),
            "color" => $embedColor,
            "fields" => [
                ["name" => "Name", "value" => $fullName, "inline" => true],
                ["name" => "Discord", "value" => $discordTag, "inline" => true],
                ["name" => "Category / Rank", "value" => "$category — $position", "inline" => false]
            ],
            "timestamp" => date('c'),
            "footer" => ["text" => "Region IX Transparency & Directory Board"]
        ]]
    ];
    sendDiscordWebhook(CITIZEN_REGISTRY_WEBHOOK_URL, $webhookPayload);

    sendJsonResponse(['status' => 'success', 'message' => 'Successfully registered citizen and dispatched notification!']);
}

if ($path === '/api/salary' && $method === 'GET') {
    $stmt = $pdo->query("SELECT id, department, title, name, badge_id, base_salary, incentives, status, days_attended FROM salaries ORDER BY id ASC");
    $rows = $stmt->fetchAll();

    $result = array_map(function($r) {
        $dept = $r['department'];
        $base = (float)$r['base_salary'];
        $inc = (float)$r['incentives'];
        $days = (int)$r['days_attended'];
        $totalWk = calculateWeeklySalary($dept, $base, $inc, $days);
        $earnedWeeklyBase = ($days >= MINIMUM_SHIFTS_REQUIRED) ? ($base / 4.0) * (min($days, 7) / 7.0) : 0.0;

        return [
            'id' => (int)$r['id'],
            'department' => $dept,
            'title' => $r['title'],
            'name' => $r['name'],
            'badge_id' => $r['badge_id'],
            'salary' => $base,
            'daysAttended' => $days,
            'weekly' => $earnedWeeklyBase,
            'incentives' => $inc,
            'totalWk' => $totalWk,
            'status' => $r['status']
        ];
    }, $rows);

    sendJsonResponse($result);
}

sendJsonResponse(['status' => 'error', 'message' => '404 Endpoint Not Found: ' . $path], 404);