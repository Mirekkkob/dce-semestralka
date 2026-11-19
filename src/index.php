<?php
$message = "";
$token = $_COOKIE['auth_token'] ?? null;

function callApi(string $method, string $endpoint, ?array $data = null, ?string $token = null): array {
    $ch = curl_init("http://backend:5000" . $endpoint);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['code' => 0, 'data' => ['error' => 'cURL chyba: ' . $curlError]];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['code' => $httpCode, 'data' => ['error' => "Neplatná odpověď ($httpCode): " . strip_tags($response)]];
    }

    return ['code' => $httpCode, 'data' => $decoded];
}

if (isset($_GET['logout'])) {
    setcookie('auth_token', '', time() - 3600, '/');
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login' || $action === 'register') {
        $res = callApi('POST', '/' . $action, [
            'username' => $_POST['username'] ?? '',
            'password' => $_POST['password'] ?? ''
        ]);

        if ($action === 'login' && $res['code'] === 200 && isset($res['data']['token'])) {
            setcookie('auth_token', $res['data']['token'], ['httponly' => true, 'path' => '/']);
            header("Location: index.php");
            exit;
        }
        $message = $res['data']['message'] ?? $res['data']['error'] ?? 'Chyba komunikace';
    }

    if ($token && $action === 'create_list') {
        $res = callApi('POST', '/lists', ['name' => $_POST['list_name'] ?? ''], $token);
        if ($res['code'] === 201 && isset($res['data']['id'])) {
            header("Location: index.php?list_id=" . $res['data']['id']);
            exit;
        }
        $message = $res['data']['error'] ?? 'Nepodařilo se vytvořit seznam';
    }

    if ($token && $action === 'delete_list') {
        $listId = (int)($_POST['list_id'] ?? 0);
        callApi('DELETE', "/lists/$listId", null, $token);
        header("Location: index.php");
        exit;
    }

    if ($token && $action === 'add_item') {
        $listId = (int)($_POST['list_id'] ?? 0);
        callApi('POST', "/lists/$listId/items", ['name' => $_POST['item_name'] ?? ''], $token);
        header("Location: index.php?list_id=" . $listId);
        exit;
    }

    if ($token && $action === 'delete_item') {
        $listId = (int)($_POST['list_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        callApi('DELETE', "/items/$itemId", null, $token);
        header("Location: index.php?list_id=" . $listId);
        exit;
    }
}

$lists = [];
$selectedList = null;
$items = [];

if ($token) {
    $listsRes = callApi('GET', '/lists', null, $token);
    if ($listsRes['code'] === 401) {
        setcookie('auth_token', '', time() - 3600, '/');
        header("Location: index.php");
        exit;
    }
    $lists = $listsRes['data'];

    $selectedListId = isset($_GET['list_id']) ? (int)$_GET['list_id'] : null;
    if ($selectedListId) {
        foreach ($lists as $l) {
            if ((int)$l['id'] === $selectedListId) {
                $selectedList = $l;
                break;
            }
        }
        if ($selectedList) {
            $itemsRes = callApi('GET', "/lists/$selectedListId/items", null, $token);
            $items = $itemsRes['data'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Nákupní seznamy</title>
</head>
<body>

<?php if ($message): ?>
    <p><strong><?php echo htmlspecialchars($message); ?></strong></p>
<?php endif; ?>

<?php if (!$token): ?>
    <h2>Přihlášení / Založení účtu</h2>
    <form method="POST">
        <input type="text" name="username" placeholder="Jméno" required><br><br>
        <input type="password" name="password" placeholder="Heslo" required><br><br>
        <button type="submit" name="action" value="login">Přihlásit</button>
        <button type="submit" name="action" value="register">Založit účet</button>
    </form>

<?php else: ?>
    <p><a href="?logout=1">Odhlásit se</a></p>
    <hr>

    <h3>Vytvořit nový nákupní seznam</h3>
    <form method="POST">
        <input type="text" name="list_name" placeholder="Název seznamu" required>
        <button type="submit" name="action" value="create_list">Vytvořit</button>
    </form>

    <h3>Editovat stávající seznam</h3>
    <?php if (empty($lists)): ?>
        <p>Zatím nemáte žádný seznam.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($lists as $list): ?>
                <li>
                    <a href="?list_id=<?php echo $list['id']; ?>">
                        <?php echo htmlspecialchars($list['name']); ?>
                    </a>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="list_id" value="<?php echo $list['id']; ?>">
                        <button type="submit" name="action" value="delete_list">Odebrat</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($selectedList): ?>
        <hr>
        <h3>Seznam: <?php echo htmlspecialchars($selectedList['name']); ?></h3>

        <form method="POST">
            <input type="hidden" name="list_id" value="<?php echo $selectedList['id']; ?>">
            <input type="text" name="item_name" placeholder="Nová položka" required>
            <button type="submit" name="action" value="add_item">Přidat položku</button>
        </form>

        <ul>
            <?php foreach ($items as $item): ?>
                <li>
                    <?php echo htmlspecialchars($item['name']); ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="list_id" value="<?php echo $selectedList['id']; ?>">
                        <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                        <button type="submit" name="action" value="delete_item">Odebrat</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

<?php endif; ?>

</body>
</html>