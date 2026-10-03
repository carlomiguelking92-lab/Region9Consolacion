<?php
// Set timezone explicitly to Philippine Time
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

// -----------------------------------------------------------------------------
// ENVIRONMENT & WEBHOOK CONFIGURATION
// -----------------------------------------------------------------------------

define('AUDIT_WEBHOOK_URL', getenv('AUDIT_WEBHOOK_URL') ?: "https://discord.com/api/webhooks/1544605127950864466/T-FsN7Aa3fySEuMcXuYU31PU8rm3tefe71LdwcSRTJHQxNaWmprM-UaOKt2CtrMoCS-n");
define('ACCOUNTING_WEBHOOK_URL', getenv('ACCOUNTING_WEBHOOK_URL') ?: AUDIT_WEBHOOK_URL);
define('PNP_PROOF_WEBHOOK_URL', getenv('PNP_PROOF_WEBHOOK_URL') ?: AUDIT_WEBHOOK_URL);
define('GOV_PROOF_WEBHOOK_URL', getenv('GOV_PROOF_WEBHOOK_URL') ?: AUDIT_WEBHOOK_URL);

define('MINIMUM_SHIFTS_REQUIRED', 3);

// -----------------------------------------------------------------------------
// DATABASE CONNECTION HELPER
// -----------------------------------------------------------------------------

/**
 * @return PDO
 */
function getDBConnection(): PDO {
    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('DB_NAME') ?: (getenv('ROLEPLAY_DB') ?: 'region9_db');
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $port = getenv('DB_PORT') ?: '3306';

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

/**
 * @param PDO $pdo
 * @return void
 */
function createTablesIfNotExists(PDO $pdo): void {
    $tables = [
        "CREATE TABLE IF NOT EXISTS staff_users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100) UNIQUE NOT NULL, password VARCHAR(255) NOT NULL, badge_id VARCHAR(50) UNIQUE NOT NULL, department VARCHAR(20) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'MEMBER', status VARCHAR(20) NOT NULL DEFAULT 'PENDING', token VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS officers (id INT AUTO_INCREMENT PRIMARY KEY, badge_no VARCHAR(50) UNIQUE NOT NULL, name VARCHAR(255) NOT NULL, `rank` VARCHAR(100) NOT NULL, points INT DEFAULT 0, discord_id VARCHAR(100) DEFAULT NULL)",
        "CREATE TABLE IF NOT EXISTS government_members (id INT AUTO_INCREMENT PRIMARY KEY, id_no VARCHAR(50) UNIQUE NOT NULL, name VARCHAR(255) NOT NULL, position VARCHAR(255) NOT NULL, points INT DEFAULT 0, discord_id VARCHAR(100) DEFAULT NULL)",
        "CREATE TABLE IF NOT EXISTS activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, action_type VARCHAR(255) NOT NULL, user_details VARCHAR(255) NOT NULL, department VARCHAR(10) NOT NULL DEFAULT 'PNP', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS salaries (id INT AUTO_INCREMENT PRIMARY KEY, department VARCHAR(20) NOT NULL, title VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, badge_id VARCHAR(50) NOT NULL UNIQUE, base_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00, incentives DECIMAL(10,2) NOT NULL DEFAULT 0.00, status VARCHAR(20) NOT NULL DEFAULT 'unpaid', days_attended INT NOT NULL DEFAULT 0, daily_proof_count INT NOT NULL DEFAULT 0, last_duty_date DATE DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS treasury (id INT PRIMARY KEY DEFAULT 1, amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS certificates (id INT AUTO_INCREMENT PRIMARY KEY, ref_code VARCHAR(100) UNIQUE NOT NULL, personnel_name VARCHAR(255) NOT NULL, id_or_badge VARCHAR(50) NOT NULL, template_type VARCHAR(100) NOT NULL, issued_date VARCHAR(50) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
    $pdo->exec("INSERT IGNORE INTO treasury (id, amount) VALUES (1, 0.00)");
}

/**
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

/**
 * @return array
 */
function getJsonInput(): array {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
}

// Calculations & Rank Logic
/**
 * @param string $department
 * @param float $baseSalary
 * @param float $incentives
 * @param int $daysAttended
 * @return float
 */
function calculateWeeklySalary(string $department, float $baseSalary, float $incentives, int $daysAttended): float {
    if ($daysAttended < MINIMUM_SHIFTS_REQUIRED) {
        return $incentives;
    }
    $earnedWeeklyBase = ($baseSalary / 4.0) * (min($daysAttended, 7) / 7.0);
    return $earnedWeeklyBase + $incentives;
}

/**
 * @param string|null $rank
 * @return float
 */
function getBaseSalaryForRank(?string $rank): float {
    if (!$rank) return 300000.0;
    switch (strtoupper(trim($rank))) {
        case "PATROLMAN": return 300000.0;
        case "POLICE CORPORAL": case "PCPL": return 350000.0;
        case "POLICE STAFF SERGEANT": return 400000.0;
        case "POLICE MASTER SERGEANT": case "PMS": return 450000.0;
        case "POLICE SENIOR MASTER SERGEANT": case "PSMS": return 500000.0;
        case "POLICE CHIEF MASTER SERGEANT": case "PCMS": return 550000.0;
        case "POLICE EXECUTIVE MASTER SERGEANT": case "PEMS": return 600000.0;
        case "POLICE LIEUTENANT": case "PLT": return 700000.0;
        case "POLICE CAPTAIN": case "PCPT": return 750000.0;
        case "POLICE MAJOR": case "PMAJ": return 800000.0;
        case "POLICE LIEUTENANT COLONEL": case "PLTCOL": return 850000.0;
        case "POLICE COLONEL": case "PCOL": return 900000.0;
        case "POLICE BRIGADIER GENERAL": case "PBGEN": return 1000000.0;
        case "POLICE MAJOR GENERAL": case "PMGEN": return 1050000.0;
        case "POLICE LIEUTENANT GENERAL": case "PLTGEN": return 1150000.0;
        case "POLICE GENERAL": case "PGEN": return 1250000.0;
        default: return 300000.0;
    }
}

/**
 * @param string|null $rank
 * @return bool
 */
function isLieutenantOrAbove(?string $rank): bool {
    if (!$rank) return false;
    $r = strtoupper($rank);
    return (strpos($r, 'LT') !== false || strpos($r, 'LIEUTENANT') !== false ||
            strpos($r, 'CAPT') !== false || strpos($r, 'CAPTAIN') !== false ||
            strpos($r, 'MAJ') !== false || strpos($r, 'MAJOR') !== false ||
            strpos($r, 'COL') !== false || strpos($r, 'COLONEL') !== false ||
            strpos($r, 'GEN') !== false || strpos($r, 'GENERAL') !== false);
}

/**
 * @param string|null $badge
 * @return string
 */
function normalizeBadgeNo(?string $badge): string {
    if (!$badge) return "";
    $b = strtoupper(trim($badge));
    if (strpos($b, '009-') === 0) return "O09-" . substr($b, 4);
    if (strpos($b, '09-O') === 0) return "O09-" . substr($b, 4);
    return $b;
}

// -----------------------------------------------------------------------------
// WEBHOOK NOTIFIER FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * @param string $webhookUrl
 * @param mixed $payload
 * @return string|bool
 */
function sendDiscordWebhook(string $webhookUrl, $payload) {
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($payload) ? $payload : json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    return $response;
}

/**
 * @param PDO $pdo
 * @param string $action
 * @param string $user
 * @param string $department
 * @return void
 */
function logActivity(PDO $pdo, string $action, string $user, string $department = "PNP"): void {
    $deptStr = !empty($department) ? strtoupper($department) : "PNP";
    $stmt = $pdo->prepare("INSERT INTO activity_logs (action_type, user_details, department) VALUES (?, ?, ?)");
    $stmt->execute([$action, $user, $deptStr]);

    $embedColor = ($deptStr === 'GOV') ? 0x10b981 : 0x2563eb;
    $payload = [
        "username" => "Region IX Audit Logger",
        "embeds" => [[
            "title" => "🚨 System Audit Log",
            "color" => $embedColor,
            "fields" => [
                ["name" => "Department", "value" => "`$deptStr`", "inline" => true],
                ["name" => "Action", "value" => $action, "inline" => false],
                ["name" => "Target / Personnel", "value" => $user, "inline" => false]
            ],
            "timestamp" => date('c'),
            "footer" => ["text" => "Region IX Dashboard Security Audit"]
        ]]
    ];
    sendDiscordWebhook(AUDIT_WEBHOOK_URL, $payload);
}

/**
 * @param PDO $pdo
 * @param string $action
 * @param string $userDetails
 * @param string $department
 * @return void
 */
function logActivityToDbOnly(PDO $pdo, string $action, string $userDetails, string $department = "PNP"): void {
    $stmt = $pdo->prepare("INSERT INTO activity_logs (action_type, user_details, department) VALUES (?, ?, ?)");
    $stmt->execute([$action, $userDetails, strtoupper($department)]);
}

// Authentication Session
/**
 * @param PDO $pdo
 * @return array|null
 */
function getAuthUser(PDO $pdo): ?array {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (strpos($auth, 'Bearer ') === 0) {
        $token = substr($auth, 7);
        $stmt = $pdo->prepare("SELECT * FROM staff_users WHERE token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
    return null;
}

/**
 * @param array|null $user
 * @param string|null $requiredDept
 * @return bool
 */
function requireAdmin(?array $user, ?string $requiredDept = null): bool {
    if (!$user || $user['status'] !== 'APPROVED') {
        sendJsonResponse(['status' => 'error', 'message' => 'Unauthorized access'], 403);
    }
    if ($user['role'] === 'SUPER_ADMIN') return true;
    if ($user['role'] === 'ADMIN') {
        if ($requiredDept === null || strtoupper($user['department']) === strtoupper($requiredDept)) {
            return true;
        }
    }
    sendJsonResponse(['status' => 'error', 'message' => 'Admin permissions required'], 403);
    return false;
}

// Unpaid Summary Generator
/**
 * @param PDO $pdo
 * @param string|null $filterDept
 * @return array
 */
function getUnpaidSummaryFromDb(PDO $pdo, ?string $filterDept): array {
    $summary = ['count' => 0, 'totalBalance' => 0.0, 'formattedList' => ''];
    $stmt = $pdo->query("SELECT department, title, name, badge_id, base_salary, incentives, days_attended FROM salaries WHERE LOWER(status) = 'unpaid' ORDER BY id ASC");
    
    $listStr = "";
    while ($r = $stmt->fetch()) {
        if ($filterDept && strtoupper($filterDept) !== 'ALL' && strtoupper($filterDept) !== strtoupper($r['department'])) {
            continue;
        }
        $weekly = calculateWeeklySalary($r['department'], (float)$r['base_salary'], (float)$r['incentives'], (int)$r['days_attended']);
        $summary['count']++;
        $summary['totalBalance'] += $weekly;

        $statusTag = $r['days_attended'] >= MINIMUM_SHIFTS_REQUIRED ? "✅ Qualified" : "⚠️ Incomplete Shifts ({$r['days_attended']}/3)";
        $listStr .= sprintf("• `[%s]` **%s** (%s) - ₱%.2f (%s)\n", $r['badge_id'], $r['name'], $r['title'], $weekly, $statusTag);
    }

    if (strlen($listStr) > 0) {
        $summary['formattedList'] = (strlen($listStr) > 950) ? substr($listStr, 0, 920) . "\n... (truncated due to Discord character limits)" : $listStr;
    } else {
        $summary['formattedList'] = "No unpaid personnel.";
    }
    return $summary;
}

// Execute Daily Quota Report and Reset Process
/**
 * @param PDO $pdo
 * @param string $triggeredBy
 * @return void
 */
function processDailyQuotaReportAndReset(PDO $pdo, string $triggeredBy): void {
    $pnpComp = []; $pnpIncomp = []; $pnpNoDuty = [];
    $govComp = []; $govIncomp = []; $govNoDuty = [];
    $pnpAudit = []; $govAudit = [];

    $stmt = $pdo->query("SELECT badge_id, name, title, department, daily_proof_count, days_attended FROM salaries ORDER BY department ASC, badge_id ASC");
    while ($r = $stmt->fetch()) {
        $badge = $r['badge_id'];
        $name = $r['name'];
        $title = $r['title'];
        $dept = $r['department'];
        $proofs = (int)$r['daily_proof_count'];
        $weeklyDays = (int)$r['days_attended'];
        $isGen = (strtolower(trim($title)) === 'police general');

        if ($isGen) {
            $statusBadge = "⭐ Exempt (7/7)";
        } else if ($proofs >= 3 || $weeklyDays >= 7) {
            $statusBadge = sprintf("✅ Completed (%d/7)", min(7, max($proofs, $weeklyDays)));
        } else if ($proofs > 0 || $weeklyDays > 0) {
            $statusBadge = sprintf("⚠️ Incomplete (%d/7)", max($proofs, $weeklyDays));
        } else {
            $statusBadge = "❌ No Duty (0/7)";
        }

        $auditEntry = "• **[$badge] $name**\n  └ *$title* — $statusBadge\n";
        $proofEntry = "• `[$badge]` **$name** ($title) - **$proofs/3 Proofs** | Total Weekly: $weeklyDays/3 shifts\n";

        if (strtoupper($dept) === 'GOV') {
            $govAudit[] = $auditEntry;
            if ($proofs >= 3 || $isGen) $govComp[] = $proofEntry;
            else if ($proofs > 0) $govIncomp[] = $proofEntry;
            else $govNoDuty[] = $proofEntry;
        } else {
            $pnpAudit[] = $auditEntry;
            if ($proofs >= 3 || $isGen) $pnpComp[] = $proofEntry;
            else if ($proofs > 0) $pnpIncomp[] = $proofEntry;
            else $pnpNoDuty[] = $proofEntry;
        }
    }

    $dateStr = date('l, F d, Y');

    // Helper closure for sending department quota report embeds
    $sendDeptQuota = function(string $webhookUrl, string $department, array $comp, array $incomp, array $noDuty) use ($dateStr) {$payload = [
            "username" => "Region IX Duty Quota Logger",
            "embeds" => [[
                "title" => "☀️ 8:00 AM " . strtoupper($department) . " Daily Duty & Quota Report",
                "color" => (strtoupper($department) === 'GOV') ? 0x10b981 : 0x2563eb,
                "description" => "**Daily evaluation of 3-proof duty shift quotas.**\nDate: $dateStr\n\n" .
                                 "📊 **Department Overview:**\n" .
                                 "• ✅ **Completed Quota (3+ Proofs):** " . count($comp) . " Personnel\n" .
                                 "• ⚠️ **Incomplete Quota (1-2 Proofs):** " . count($incomp) . " Personnel\n" .
                                 "• ❌ **No Duty / 0 Proofs:** " . count($noDuty) . " Personnel",
                "fields" => [
                    ["name" => "✅ Quota Completed (>=3 Proofs)", "value" => implode("", $comp) ?: "None.", "inline" => false],
                    ["name" => "⚠️ Incomplete Quota (1-2 Proofs)", "value" => implode("", $incomp) ?: "None.", "inline" => false],
                    ["name" => "❌ No Duty / 0 Proofs Submitted", "value" => implode("", $noDuty) ?: "None.", "inline" => false]
                ],
                "timestamp" => date('c'),
                "footer" => ["text" => "Region IX Daily Reset | Quotas Reset to 0"]
            ]]
        ];
        sendDiscordWebhook($webhookUrl,$payload);
    };

    $sendDeptQuota(PNP_PROOF_WEBHOOK_URL, "PNP", $pnpComp, $pnpIncomp,$pnpNoDuty);
    $sendDeptQuota(GOV_PROOF_WEBHOOK_URL, "GOV", $govComp, $govIncomp,$govNoDuty);

    // Audit summary webhook
    $auditPayload = [
        "username" => "Region IX System Audit",
        "embeds" => [[
            "title" => "🚨 Daily Quota Reset & Executive Audit Summary",
            "color" => 15158332,
            "description" => "**Official 8:00 AM cross-departmental quota reset audit.**\nDate: $dateStr",
            "fields" => [
                ["name" => "👮 PNP Department Status", "value" => implode("", $pnpAudit) ?: "—", "inline" => true],
                ["name" => "🏛️ GOV Department Status", "value" => implode("", $govAudit) ?: "—", "inline" => true]
            ],
            "timestamp" => date('c'),
            "footer" => ["text" => "Region IX System Reset Audit"]
        ]]
    ];
    sendDiscordWebhook(AUDIT_WEBHOOK_URL, $auditPayload);

    // Reset daily proof count
    $pdo->exec("UPDATE salaries SET daily_proof_count = 0");
    logActivityToDbOnly($pdo, "Daily Quota Reset Executed", "Triggered By: " . $triggeredBy, "PNP");
}

// Initialize DB and Tables
$pdo = getDBConnection();
createTablesIfNotExists($pdo);

// Router Setup
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method =$_SERVER['REQUEST_METHOD'];

// -----------------------------------------------------------------------------
// DISCORD PROOF LISTENER ENDPOINT
// -----------------------------------------------------------------------------

if ($path === '/api/discord/proof-listener' && $method === 'POST') {$input = getJsonInput();
    $discordId = trim($input['discord_id'] ?? '');
    $badgeId   = trim($input['badge_id'] ?? '');

    if (!$badgeId &&$discordId) {
        $stmt =$pdo->prepare("SELECT badge_no FROM officers WHERE discord_id = ?");
        $stmt->execute([$discordId]);
        $badgeId =$stmt->fetchColumn();

        if (!$badgeId) {
            $stmt =$pdo->prepare("SELECT id_no FROM government_members WHERE discord_id = ?");
            $stmt->execute([$discordId]);
            $badgeId =$stmt->fetchColumn();
        }
    }

    if (!$badgeId) {
        sendJsonResponse(['status' => 'error', 'message' => 'Personnel not found for the provided Discord ID or Badge ID.'], 404);
    }

    $today = date('Y-m-d');

    $stmt =$pdo->prepare("SELECT daily_proof_count, days_attended, last_duty_date FROM salaries WHERE badge_id = ? OR badge_id = ?");
    $stmt->execute([$badgeId, normalizeBadgeNo($badgeId)]);
    $record =$stmt->fetch();

    if ($record) {
        $newProofCount =$record['daily_proof_count'] + 1;
        $daysAttended =$record['days_attended'];
        $lastDutyDate =$record['last_duty_date'];

        if ($lastDutyDate !== $today) {$daysAttended += 1;
        }

        $updateStmt =$pdo->prepare("UPDATE salaries SET daily_proof_count = ?, days_attended = ?, last_duty_date = ? WHERE badge_id = ? OR badge_id = ?");
        $updateStmt->execute([$newProofCount, $daysAttended,$today, $badgeId, normalizeBadgeNo($badgeId)]);

        sendJsonResponse([
            'status' => 'success',
            'badge_id' => $badgeId,
            'daily_proof_count' => $newProofCount,
            'days_attended' => $daysAttended
        ]);
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'Salary record not found for personnel.'], 404);
    }
}

// -----------------------------------------------------------------------------
// SYSTEM & PUBLIC ROUTES
// -----------------------------------------------------------------------------

if ($path === '/' ||$path === '/api') {
    sendJsonResponse(["status" => "online", "message" => "Region IX API Server is Running"]);
}

if ($path === '/api/stats' &&$method === 'GET') {
    $off =$pdo->query("SELECT COUNT(*) FROM officers")->fetchColumn();
    $gov =$pdo->query("SELECT COUNT(*) FROM government_members")->fetchColumn();
    sendJsonResponse(['total_officers' => (int)$off, 'total_gov_members' => (int)$gov]);
}

if ($path === '/api/activity' &&$method === 'GET') {
    $stmt =$pdo->query("SELECT id, action_type AS action, user_details AS user, department FROM activity_logs ORDER BY created_at DESC LIMIT 10");
    sendJsonResponse($stmt->fetchAll());
}

if ($path === '/api/activity/pnp' &&$method === 'GET') {
    $stmt =$pdo->query("SELECT id, action_type AS action, user_details AS user FROM activity_logs WHERE department = 'PNP' ORDER BY created_at DESC LIMIT 10");
    sendJsonResponse($stmt->fetchAll());
}

if ($path === '/api/activity/gov' &&$method === 'GET') {
    $stmt =$pdo->query("SELECT id, action_type AS action, user_details AS user FROM activity_logs WHERE department = 'GOV' ORDER BY created_at DESC LIMIT 10");
    sendJsonResponse($stmt->fetchAll());
}

// -----------------------------------------------------------------------------
// USER MANAGEMENT ROUTES
// -----------------------------------------------------------------------------

if ($path === '/api/login' && $method === 'POST') {$input = getJsonInput();
    $user = trim($input['username'] ?? '');
    $pass = trim($input['password'] ?? '');

    $stmt =$pdo->prepare("SELECT * FROM staff_users WHERE username = ? AND password = ?");
    $stmt->execute([$user,$pass]);
    $row =$stmt->fetch();

    if ($row) {
        if (strtoupper($row['status']) === 'REJECTED') {
            sendJsonResponse(['status' => 'error', 'message' => 'Your application was rejected.'], 403);
        }

        $token = bin2hex(random_bytes(16));
        $update =$pdo->prepare("UPDATE staff_users SET token = ? WHERE id = ?");
        $update->execute([$token,$row['id']]);

        sendJsonResponse([
            'status' => 'success',
            'token' => $token,
            'username' => $row['username'],
            'badgeId' => $row['badge_id'],
            'department' => $row['department'],
            'role' => $row['role'],
            'accountStatus' => $row['status']
        ]);
    } else {
        sendJsonResponse(['status' => 'error', 'message' => 'Invalid username or password.'], 401);
    }
}

if ($path === '/api/register' &&$method === 'POST') {
    $input = getJsonInput();$user = trim($input['username'] ?? '');$pass = trim($input['password'] ?? '');$rawBadge = strtoupper(trim($input['badgeId'] ?? $input['badge_id'] ?? ''));

    if (!$user || !$pass || !$rawBadge) {
        sendJsonResponse(['status' => 'error', 'message' => 'Username, password, and Badge/ID No are required.'], 400);
    }

    $normalizedBadge = normalizeBadgeNo($rawBadge);
    $dept = (strpos($rawBadge, 'GO') === 0) ? 'GOV' : 'PNP';

    $stmt =$pdo->prepare("SELECT username FROM staff_users WHERE LOWER(username) = LOWER(?)");
    $stmt->execute([$user]);
    if ($stmt->fetch()) {
        sendJsonResponse(['status' => 'error', 'message' => "Username '$user' is already registered."], 400);
    }

    $stmt =$pdo->prepare("SELECT badge_id FROM staff_users WHERE badge_id = ? OR badge_id = ?");
    $stmt->execute([$rawBadge,$normalizedBadge]);
    if ($stmt->fetch()) {
        sendJsonResponse(['status' => 'error', 'message' => "Badge/ID No '$rawBadge' is already registered."], 400);
    }

    $idExists = false;
    if ($dept === 'GOV') {
        $checkStmt =$pdo->prepare("SELECT COUNT(*) FROM government_members WHERE id_no = ?");
        $checkStmt->execute([$rawBadge]);
        $idExists = ($checkStmt->fetchColumn() > 0);
    } else {
        $checkStmt =$pdo->prepare("SELECT COUNT(*) FROM officers WHERE badge_no = ? OR badge_no = ?");
        $checkStmt->execute([$rawBadge,$normalizedBadge]);
        $idExists = ($checkStmt->fetchColumn() > 0);
    }

    if (!$idExists) {
        sendJsonResponse(['status' => 'error', 'message' => 'Registration rejected: Badge/ID No does not exist in the official roster.'], 400);
    }

    $isSuperAdminBadge = ($normalizedBadge === 'O09-01002' ||$rawBadge === 'O09-01002');
    $assignedRole =$isSuperAdminBadge ? 'SUPER_ADMIN' : 'MEMBER';
    $assignedStatus =$isSuperAdminBadge ? 'APPROVED' : 'PENDING';

    $stmt =$pdo->prepare("INSERT INTO staff_users (username, password, badge_id, department, role, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user,$pass, $normalizedBadge,$dept, $assignedRole,$assignedStatus]);

    logActivity($pdo, "Account Registered ($assignedRole)", "$user [$normalizedBadge]", $dept);
    sendJsonResponse(['status' => 'success', 'message' => $isSuperAdminBadge ? 'Super Admin account auto-approved! You can now log in.' : 'Registration submitted! Awaiting Super Admin approval.'], 201);
}

// -----------------------------------------------------------------------------
// ROSTER MANAGEMENT (Officers, Gov Members, Certificates)
// -----------------------------------------------------------------------------

if ($path === '/api/officers' &&$method === 'GET') {
    $stmt =$pdo->query("SELECT badge_no AS badge, name, rank, points FROM officers ORDER BY points DESC");
    sendJsonResponse($stmt->fetchAll());
}

if ($path === '/api/government' &&$method === 'GET') {
    $stmt =$pdo->query("SELECT id_no AS id, name, position, points FROM government_members ORDER BY points DESC");
    sendJsonResponse($stmt->fetchAll());
}

if ($path === '/api/salary' &&$method === 'GET') {
    $stmt =$pdo->query("SELECT id, department, title, name, badge_id, base_salary, incentives, status, days_attended FROM salaries ORDER BY id ASC");
    $rows =$stmt->fetchAll();

    $result = array_map(function($r) {
        $dept =$r['department'];
        $base = (float)$r['base_salary'];
        $inc = (float)$r['incentives'];
        $days = (int)$r['days_attended'];

        $totalWk = calculateWeeklySalary($dept,$base, $inc,$days);
        $earnedWeeklyBase = ($days >= MINIMUM_SHIFTS_REQUIRED) ? ($base / 4.0) * (min($days, 7) / 7.0) : 0.0;

        return [
            'id' => (int)$r['id'],
            'department' => $dept,
            'title' => $r['title'],
            'name' => $r['name'],
            'badge_id' => $r['badge_id'],
            'badgeId' => $r['badge_id'],
            'salary' => $base,
            'baseSalary' => $base,
            'daysAttended' => $days,
            'weekly' => $earnedWeeklyBase,
            'weeklySalary' => $earnedWeeklyBase,
            'incentives' => $inc,
            'totalWk' => $totalWk,
            'totalPerWeek' => $totalWk,
            'status' => $r['status']
        ];
    }, $rows);

    sendJsonResponse($result);
}

// Fallback 404
sendJsonResponse(['status' => 'error', 'message' => '404 Endpoint Not Found: ' . $path], 404);