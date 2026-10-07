<?php

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

function sendJson($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

$host = getenv('MYSQLHOST') ?: "db";
$port = getenv('MYSQLPORT') ?: "3306";
$dbName = getenv('MYSQLDATABASE') ?: "unity_game_db";
$dbUser = getenv('MYSQLUSER') ?: "root";
$dbPass = getenv('MYSQLPASSWORD') ?: "secretpassword";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    sendJson(500, [
        "status" => "error",
        "message" => "Database connection failed",
        "details" => $e->getMessage()
    ]);
}

$method = $_SERVER["REQUEST_METHOD"];
$rawUri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) ?: "/";
$scriptName = $_SERVER["SCRIPT_NAME"] ?? $_SERVER["PHP_SELF"] ?? "";

if ($scriptName !== "") {
    $scriptName = rtrim($scriptName, "/");
    if (strpos($rawUri, $scriptName) === 0) {
        $rawUri = substr($rawUri, strlen($scriptName));
    }
}

$uri = "/" . trim($rawUri, "/");
$parts = explode("/", trim($uri, "/"));
$endpoint = $parts[0] ?? "";

$rawBody = file_get_contents("php://input");
$input = [];

if (!empty($rawBody)) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

// ----------------------
// /health
// ----------------------
if ($endpoint === "health" && $method === "GET") {
    sendJson(200, ["status" => "ok"]);
}

// ----------------------
// /register_user
// ----------------------
if ($endpoint === "register_user" && $method === "POST") {
    $playerName = trim($input["player_name"] ?? "");

    if ($playerName === "") {
        sendJson(400, ["status" => "error", "message" => "player_name is required"]);
    }

    $publicKey = "pub_" . time() . "_" . bin2hex(random_bytes(4));
    $privateKey = "priv_" . time() . "_" . bin2hex(random_bytes(4));

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, public_key, private_key) VALUES (?, ?, ?)");
        $stmt->execute([$playerName, $publicKey, $privateKey]);

        sendJson(200, [
            "status" => "ok",
            "pubk" => $publicKey,
            "privk" => $privateKey
        ]);
    } catch (Exception $e) {
        sendJson(500, [
            "status" => "error",
            "message" => "User insert failed",
            "details" => $e->getMessage()
        ]);
    }
}

// ----------------------
// /upload_level
// ----------------------
if ($endpoint === "upload_level" && $method === "POST") {
    $pubk = $input["pubk"] ?? null;
    $privk = $input["privk"] ?? null;
    $level = $input["level"] ?? null;

    if (!$pubk || !$privk || !$level) {
        sendJson(400, ["status" => "error", "message" => "Missing data"]);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE public_key = ? AND private_key = ?");
        $stmt->execute([$pubk, $privk]);
        $user = $stmt->fetch();

        if (!$user) {
            sendJson(401, ["status" => "error", "message" => "Invalid keys"]);
        }

        $tilesJson = json_encode($level["data"] ?? []);

        $stmt = $pdo->prepare("SELECT id FROM levels WHERE pubk = ? AND tiles_json = ?");
        $stmt->execute([$pubk, $tilesJson]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            sendJson(200, [
                "status" => "ignored",
                "message" => "You already uploaded this level",
                "level_id" => $existingId
            ]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO levels (name, creator, pubk, width, height, tiles_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $level["name"] ?? "",
            $user["username"],
            $pubk,
            $level["width"] ?? 0,
            $level["height"] ?? 0,
            $tilesJson
        ]);

        sendJson(200, [
            "status" => "ok",
            "level_id" => $pdo->lastInsertId()
        ]);
    } catch (Exception $e) {
        sendJson(500, [
            "status" => "error",
            "message" => "Level insert failed",
            "details" => $e->getMessage()
        ]);
    }
}

// ----------------------
// /level/{levelId}
// ----------------------
if ($endpoint === "level" && $method === "GET") {
    $levelId = $parts[1] ?? null;

    if (!$levelId) {
        sendJson(400, ["status" => "error", "message" => "Missing levelId"]);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM levels WHERE id = ?");
        $stmt->execute([$levelId]);
        $level = $stmt->fetch();

        if (!$level) {
            sendJson(404, ["status" => "error", "message" => "level not found"]);
        }

        $tilesData = json_decode($level["tiles_json"], true);

        sendJson(200, [
            "status" => "ok",
            "level_data" => [
                "id" => (int) $level["id"],
                "name" => $level["name"],
                "creator" => $level["creator"],
                "width" => (int) $level["width"],
                "height" => (int) $level["height"],
                "data" => $tilesData ?? []
            ]
        ]);
    } catch (Exception $e) {
        sendJson(500, [
            "status" => "error",
            "message" => "get level failed",
            "details" => $e->getMessage()
        ]);
    }
}

// ----------------------
// /levels
// ----------------------
if ($endpoint === "levels" && $method === "GET") {
    try {
        $stmt = $pdo->query("SELECT * FROM levels ORDER BY created_at DESC LIMIT 50");
        $levels = $stmt->fetchAll();

        sendJson(200, [
            "status" => "ok",
            "levels" => $levels
        ]);
    } catch (Exception $e) {
        sendJson(500, [
            "status" => "error",
            "message" => "DB error",
            "details" => $e->getMessage()
        ]);
    }
}

// ----------------------
// /upload_run
// ----------------------
if ($endpoint === "upload_run" && $method === "POST") {
    $pubk = $input["pubk"] ?? null;
    $privk = $input["privk"] ?? null;
    $levelId = $input["level_id"] ?? null;
    $replay = $input["replay"] ?? null;
    $ticks = $input["ticks"] ?? null;

    if (!$pubk || !$privk || !$levelId || !$replay || $ticks === null) {
        sendJson(400, ["status" => "error", "message" => "Missing run data"]);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE public_key = ? AND private_key = ?");
        $stmt->execute([$pubk, $privk]);
        $user = $stmt->fetch();

        if (!$user) {
            sendJson(401, ["status" => "error", "message" => "Invalid keys"]);
        }

        $stmt = $pdo->prepare("SELECT MIN(ticks) FROM runs WHERE user_id = ? AND level_id = ?");
        $stmt->execute([$user["id"], $levelId]);
        $bestTicks = $stmt->fetchColumn();

        if ($bestTicks !== null && $bestTicks !== false && $ticks >= $bestTicks) {
            sendJson(200, ["status" => "ignored", "message" => "Existing run is better"]);
        }

        $stmt = $pdo->prepare("DELETE FROM runs WHERE user_id = ? AND level_id = ?");
        $stmt->execute([$user["id"], $levelId]);

        $stmt = $pdo->prepare("
            INSERT INTO runs (user_id, level_id, public_key, ticks, replay_json)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $user["id"],
            $levelId,
            $pubk,
            $ticks,
            json_encode($replay)
        ]);

        sendJson(200, ["status" => "ok"]);
    } catch (Exception $e) {
        sendJson(500, [
            "status" => "error",
            "message" => "Run insert failed",
            "details" => $e->getMessage()
        ]);
    }
}

// ----------------------
// /run/{levelId}/{pubk}
// ----------------------
if ($endpoint === "run" && $method === "GET") {
    $levelId = $parts[1] ?? null;
    $pubk = $parts[2] ?? null;

    if (!$levelId || !$pubk) {
        sendJson(400, ["status" => "error", "message" => "Missing levelId or pubk"]);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT r.ticks, r.public_key, r.replay_json, u.username
            FROM runs r
            JOIN users u ON r.user_id = u.id
            WHERE r.level_id = ? AND r.public_key = ?
            ORDER BY r.ticks ASC, r.id ASC
            LIMIT 1
        ");

        $stmt->execute([$levelId, $pubk]);
        $row = $stmt->fetch();

        if (!$row) {
            sendJson(404, ["status" => "error", "message" => "run not found"]);
        }

        sendJson(200, [
            "status" => "ok",
            "entry" => [
                "player_name" => $row["username"],
                "pubk" => $row["public_key"],
                "ticks" => (int) $row["ticks"],
                "replay" => $row["replay_json"]
            ]
        ]);
    } catch (Exception $e) {
        sendJson(500, [
            "status" => "error",
            "message" => "Run query failed",
            "details" => $e->getMessage()
        ]);
    }
}

// ----------------------
// /leaderboard/{levelId}
// ----------------------
if ($endpoint === "leaderboard" && $method === "GET") {
    $levelId = $parts[1] ?? null;

    if (!$levelId) {
        sendJson(400, ["status" => "error", "message" => "Missing levelId"]);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT r.ticks, r.public_key, r.replay_json, u.username
            FROM runs r
            JOIN users u ON r.user_id = u.id
            WHERE r.level_id = ?
            ORDER BY r.ticks ASC, r.id ASC
            LIMIT 50
        ");

        $stmt->execute([$levelId]);
        $rows = $stmt->fetchAll();

        $leaderboard = array_map(function ($row) {
            return [
                "player_name" => $row["username"],
                "pubk" => $row["public_key"],
                "ticks" => (int) $row["ticks"],
                "replay" => $row["replay_json"]
            ];
        }, $rows);

        sendJson(200, [
            "status" => "ok",
            "leaderboard" => $leaderboard
        ]);
    } catch (Exception $e) {
        sendJson(500, [
            "status" => "error",
            "message" => "Leaderboard query failed",
            "details" => $e->getMessage()
        ]);
    }
}

sendJson(404, [
    "status" => "error",
    "message" => "Route not found"
]);
