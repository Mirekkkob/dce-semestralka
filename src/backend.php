<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($uri === '/healthcheck') {
    http_response_code(200);
    echo "Server is running";
    exit;
}

header("Content-Type: application/json");

$dbDsn = "pgsql:host=postgresqldb;port=5432;dbname=mydatabase;";
$dbUser = "user";
$dbPass = "password";
$jwtSecret = "tajny_klic_pro_podpis_tokenu_2026";

function createJwt(int $userId, string $username, string $secret): string {
    $b64 = fn($data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    $header = $b64(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = $b64(['user_id' => $userId, 'username' => $username, 'exp' => time() + 86400]);
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
    return "$header.$payload.$sig";
}

function getAuthenticatedUser(string $secret): ?array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
        return null;
    }
    $parts = explode('.', $matches[1]);
    if (count($parts) !== 3) return null;

    [$header, $payload, $sig] = $parts;
    $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
    if (!hash_equals($expectedSig, $sig)) return null;

    $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return null;

    return $data;
}

try {
    $pdo = new PDO($dbDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS shopping_lists (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS items (
            id SERIAL PRIMARY KEY,
            list_id INTEGER NOT NULL REFERENCES shopping_lists(id) ON DELETE CASCADE,
            name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Chyba připojení k databázi: " . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if ($uri === '/register' && $method === 'POST') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(["error" => "Chybí jméno nebo heslo"]);
        exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :p)");
        $stmt->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_BCRYPT)]);
        http_response_code(201);
        echo json_encode(["message" => "Účet vytvořen, nyní se přihlaste."]);
    } catch (PDOException $e) {
        http_response_code(400);
        $msg = ($e->getCode() === '23505') ? "Uživatel již existuje" : "Chyba DB: " . $e->getMessage();
        echo json_encode(["error" => $msg]);
    }
    exit;
}

if ($uri === '/login' && $method === 'POST') {
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');

    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        $token = createJwt((int)$user['id'], $user['username'], $jwtSecret);
        echo json_encode(["message" => "Přihlášení úspěšné", "token" => $token]);
    } else {
        http_response_code(401);
        echo json_encode(["error" => "Nesprávné jméno nebo heslo"]);
    }
    exit;
}

$currentUser = getAuthenticatedUser($jwtSecret);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(["error" => "Neplatné nebo vypršelé přihlášení"]);
    exit;
}
$userId = (int)$currentUser['user_id'];

if ($uri === '/lists' && $method === 'GET') {
    $stmt = $pdo->prepare("SELECT id, name FROM shopping_lists WHERE user_id = :uid ORDER BY id DESC");
    $stmt->execute([':uid' => $userId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($uri === '/lists' && $method === 'POST') {
    $name = trim($input['name'] ?? '');
    if (!$name) {
        http_response_code(400);
        echo json_encode(["error" => "Zadejte název seznamu"]);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO shopping_lists (user_id, name) VALUES (:uid, :name) RETURNING id, name");
    $stmt->execute([':uid' => $userId, ':name' => $name]);
    http_response_code(201);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if (preg_match('#^/lists/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $listId = (int)$m[1];
    $stmt = $pdo->prepare("DELETE FROM shopping_lists WHERE id = :lid AND user_id = :uid");
    $stmt->execute([':lid' => $listId, ':uid' => $userId]);
    echo json_encode(["message" => "Seznam odebrán"]);
    exit;
}

if (preg_match('#^/lists/(\d+)/items$#', $uri, $m)) {
    $listId = (int)$m[1];

    $check = $pdo->prepare("SELECT id FROM shopping_lists WHERE id = :lid AND user_id = :uid");
    $check->execute([':lid' => $listId, ':uid' => $userId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(["error" => "Seznam nenalezen"]);
        exit;
    }

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT id, name FROM items WHERE list_id = :lid ORDER BY id ASC");
        $stmt->execute([':lid' => $listId]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($method === 'POST') {
        $itemName = trim($input['name'] ?? '');
        if (!$itemName) {
            http_response_code(400);
            echo json_encode(["error" => "Zadejte název položky"]);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO items (list_id, name) VALUES (:lid, :name)");
        $stmt->execute([':lid' => $listId, ':name' => $itemName]);
        http_response_code(201);
        echo json_encode(["message" => "Položka přidána"]);
        exit;
    }
}

if (preg_match('#^/items/(\d+)$#', $uri, $m) && $method === 'DELETE') {
    $itemId = (int)$m[1];
    $stmt = $pdo->prepare("
        DELETE FROM items USING shopping_lists 
        WHERE items.id = :iid AND items.list_id = shopping_lists.id AND shopping_lists.user_id = :uid
    ");
    $stmt->execute([':iid' => $itemId, ':uid' => $userId]);
    echo json_encode(["message" => "Položka odebrána"]);
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Endpoint nenalezen"]);