<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/jwt.php';

$pdo = null;
$dbUnavailableReason = null;
try {
    $pdo = db_connect($config['db']);
} catch (Throwable $e) {
    $dbUnavailableReason = $e->getMessage();
}
$uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0775, true);
}

function respond(int $status, $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function str_clean($value, int $max = 255): string
{
    if (!is_string($value)) {
        return '';
    }
    return mb_substr(trim(strip_tags($value)), 0, $max);
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function cors(array $allowedOrigins): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        if (!in_array($origin, $allowedOrigins, true)) {
            respond(403, ['message' => 'Origin is not allowed by CORS policy']);
        }
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
}

function ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      limit_key VARCHAR(190) NOT NULL UNIQUE,
      request_count INT UNSIGNED NOT NULL DEFAULT 0,
      reset_at DATETIME NOT NULL,
      INDEX idx_reset_at (reset_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS app_logs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      level VARCHAR(16) NOT NULL,
      event_type VARCHAR(64) NOT NULL,
      message TEXT NOT NULL,
      context_json JSON NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS email_outbox (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      type VARCHAR(64) NOT NULL,
      recipient_email VARCHAR(255) NOT NULL,
      payload_json JSON NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT "pending",
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      sent_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1');
    $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(50) NULL');
    $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
}

function log_event(PDO $pdo, string $level, string $eventType, string $message, ?array $context = null): void
{
    $stmt = $pdo->prepare('INSERT INTO app_logs (level, event_type, message, context_json) VALUES (:l,:e,:m,:c)');
    $stmt->execute([
        'l' => $level,
        'e' => $eventType,
        'm' => $message,
        'c' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function queue_email(PDO $pdo, string $type, string $email, array $payload): void
{
    $stmt = $pdo->prepare('INSERT INTO email_outbox (type, recipient_email, payload_json, status) VALUES (:t,:e,:p,"pending")');
    $stmt->execute([
        't' => $type,
        'e' => $email,
        'p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function auth_payload(string $secret): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
    }
    if (!preg_match('/Bearer\s+(.+)$/i', $header, $m)) {
        return null;
    }
    $payload = jwt_verify($m[1], $secret);
    return is_array($payload) ? $payload : null;
}

function auth_user(PDO $pdo, string $secret): ?array
{
    $payload = auth_payload($secret);
    if (!$payload || !isset($payload['id'])) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, name, email, is_admin, is_active, phone, created_at, updated_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $payload['id']]);
    $user = $stmt->fetch();
    if (!$user || (int) ($user['is_active'] ?? 1) !== 1) {
        return null;
    }
    return $user;
}

function admin_required(PDO $pdo, string $secret): array
{
    $user = auth_user($pdo, $secret);
    if (!$user) {
        respond(401, ['message' => 'Unauthorized']);
    }
    if ((int) ($user['is_admin'] ?? 0) !== 1) {
        respond(403, ['message' => 'Admin access required']);
    }
    return $user;
}

function admin_csrf_required(string $secret): void
{
    $payload = auth_payload($secret);
    if (!$payload || !isset($payload['csrf'])) {
        respond(403, ['message' => 'CSRF token is required']);
    }
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($headerToken) || $headerToken === '' || !hash_equals((string) $payload['csrf'], $headerToken)) {
        respond(403, ['message' => 'Invalid CSRF token']);
    }
}

function login_lockout(PDO $pdo, string $key, int $limit = 5, int $windowSec = 900): bool
{
    $stmt = $pdo->prepare('SELECT id, request_count, reset_at FROM rate_limits WHERE limit_key = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    $now = time();
    if (!$row) {
        $ins = $pdo->prepare('INSERT INTO rate_limits (limit_key, request_count, reset_at) VALUES (:k, 0, :r)');
        $ins->execute(['k' => $key, 'r' => date('Y-m-d H:i:s', $now + $windowSec)]);
        return false;
    }
    $resetAt = strtotime((string) $row['reset_at']);
    if ($resetAt !== false && $resetAt < $now) {
        $pdo->prepare('UPDATE rate_limits SET request_count = 0, reset_at = :r WHERE id = :id')->execute([
            'r' => date('Y-m-d H:i:s', $now + $windowSec),
            'id' => (int) $row['id'],
        ]);
        return false;
    }
    return (int) $row['request_count'] >= $limit;
}

function login_fail(PDO $pdo, string $key): void
{
    $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = request_count + 1 WHERE limit_key = :k');
    $stmt->execute(['k' => $key]);
}

function login_success(PDO $pdo, string $key): void
{
    $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = 0 WHERE limit_key = :k');
    $stmt->execute(['k' => $key]);
}

function map_product(PDO $pdo, array $row): array
{
    $reviewsStmt = $pdo->prepare('SELECT id, user_id AS userId, user_name AS userName, rating, comment, date FROM reviews WHERE product_id = :id ORDER BY id DESC');
    $reviewsStmt->execute(['id' => (int) $row['id']]);
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'slug' => $row['slug'],
        'price' => (int) $row['price'],
        'description' => $row['description'],
        'material' => $row['material'],
        'color' => $row['color'],
        'style' => $row['style'],
        'category' => $row['category'],
        'images' => json_decode((string) $row['images_json'], true) ?: [],
        'rating' => (float) $row['rating'],
        'reviews' => $reviewsStmt->fetchAll(),
        'inStock' => (bool) ($row['in_stock'] ?? 0),
        'stockQty' => (int) ($row['stock_qty'] ?? 0),
        'dimensions' => $row['dimensions'] ?: null,
    ];
}

function malware_scan(string $path): bool
{
    $content = @file_get_contents($path);
    if ($content === false) {
        return false;
    }
    $needles = ['<?php', 'eval(', 'base64_decode(', 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE', 'powershell -e', 'cmd.exe /c'];
    $lower = strtolower($content);
    foreach ($needles as $needle) {
        if (str_contains($lower, strtolower($needle))) {
            return false;
        }
    }
    return true;
}

function normalize_images($images): array
{
    if (!is_array($images)) {
        return [];
    }
    $result = [];
    foreach ($images as $url) {
        $clean = str_clean($url, 2048);
        if ($clean !== '') {
            $result[] = $clean;
        }
    }
    return array_values(array_unique($result));
}

cors($config['cors_origins']);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($pdo instanceof PDO) {
    ensure_schema($pdo);
}

if (($config['app_env'] ?? 'development') === 'production'
    && ($config['jwt_secret'] ?? '') === 'change-this-jwt-secret-in-production') {
    respond(500, ['message' => 'Server misconfiguration']);
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$apiPos = strpos($uri, '/api/');
$path = $apiPos === false ? '/' : substr($uri, $apiPos + 4);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    if ($path === '/health' && $method === 'GET') {
        respond(200, [
            'ok' => $pdo instanceof PDO,
            'db' => $pdo instanceof PDO ? 'up' : 'down',
            'message' => $pdo instanceof PDO ? 'ready' : 'database unavailable',
        ]);
    }

    if (!$pdo instanceof PDO) {
        respond(503, ['message' => 'Database is unavailable', 'details' => $dbUnavailableReason]);
    }

    if ($path === '/categories' && $method === 'GET') {
        $rows = $pdo->query('SELECT id, name, slug, image, description FROM categories ORDER BY id')->fetchAll();
        respond(200, $rows);
    }

    if ($path === '/products/recommended' && $method === 'GET') {
        $limit = max(1, min(24, (int) ($_GET['limit'] ?? 4)));
        $stmt = $pdo->prepare('SELECT * FROM products ORDER BY rating DESC, id DESC LIMIT :l');
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        respond(200, array_map(fn($r) => map_product($pdo, $r), $rows));
    }

    if ($path === '/products' && $method === 'GET') {
        $filters = [];
        $params = [];
        if (!empty($_GET['category'])) {
            $filters[] = 'category = :category';
            $params['category'] = str_clean($_GET['category'], 80);
        }
        if (isset($_GET['minPrice']) && $_GET['minPrice'] !== '') {
            $filters[] = 'price >= :minPrice';
            $params['minPrice'] = max(0, (int) $_GET['minPrice']);
        }
        if (isset($_GET['maxPrice']) && $_GET['maxPrice'] !== '') {
            $filters[] = 'price <= :maxPrice';
            $params['maxPrice'] = max(0, (int) $_GET['maxPrice']);
        }
        if (!empty($_GET['search'])) {
            $filters[] = '(LOWER(name) LIKE :q OR LOWER(description) LIKE :q OR LOWER(category) LIKE :q)';
            $params['q'] = '%' . mb_strtolower(str_clean($_GET['search'], 120)) . '%';
        }
        $whereSql = $filters ? ('WHERE ' . implode(' AND ', $filters)) : '';
        $sort = $_GET['sort'] ?? 'popular';
        $sortSql = match ($sort) {
            'price_asc' => 'ORDER BY price ASC',
            'price_desc' => 'ORDER BY price DESC',
            'newest' => 'ORDER BY id DESC',
            default => 'ORDER BY rating DESC, id DESC',
        };
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(48, (int) ($_GET['limit'] ?? 12)));
        $offset = ($page - 1) * $limit;

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM products $whereSql");
        foreach ($params as $k => $v) {
            $countStmt->bindValue(':' . $k, $v);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM products $whereSql $sortSql LIMIT :l OFFSET :o");
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        respond(200, ['data' => array_map(fn($r) => map_product($pdo, $r), $rows), 'total' => $total, 'page' => $page]);
    }

    if (preg_match('#^/products/([a-z0-9\-]+)$#', $path, $m) && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $m[1]]);
        $row = $stmt->fetch();
        if (!$row) {
            respond(404, ['message' => 'Product not found']);
        }
        respond(200, map_product($pdo, $row));
    }

    if ($path === '/auth/register' && $method === 'POST') {
        $body = body_json();
        $name = str_clean($body['name'] ?? '', 120);
        $email = mb_strtolower(str_clean($body['email'] ?? '', 255));
        $password = (string) ($body['password'] ?? '');
        if ($name === '' || !valid_email($email) || strlen($password) < 8) {
            respond(400, ['message' => 'Invalid registration payload']);
        }
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $exists->execute(['email' => $email]);
        if ($exists->fetch()) {
            respond(409, ['message' => 'Email already exists']);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('INSERT INTO users (name, email, password_hash, is_admin, is_active) VALUES (:n,:e,:h,0,1)')
            ->execute(['n' => $name, 'e' => $email, 'h' => $hash]);
        $id = (int) $pdo->lastInsertId();
        $csrf = bin2hex(random_bytes(24));
        $token = jwt_sign(['id' => $id, 'email' => $email, 'csrf' => $csrf], $config['jwt_secret']);
        queue_email($pdo, 'user_registered', $email, ['userId' => $id]);
        respond(201, ['token' => $token, 'csrfToken' => $csrf, 'user' => ['id' => $id, 'name' => $name, 'email' => $email, 'is_admin' => 0]]);
    }

    if ($path === '/auth/login' && $method === 'POST') {
        $body = body_json();
        $email = mb_strtolower(str_clean($body['email'] ?? '', 255));
        $password = (string) ($body['password'] ?? '');
        $mfaCode = str_clean($body['mfaCode'] ?? '', 12);
        if (!valid_email($email) || $password === '') {
            respond(400, ['message' => 'Invalid login payload']);
        }
        $lockKey = 'login:' . $email . ':' . $ip;
        if (login_lockout($pdo, $lockKey)) {
            respond(429, ['message' => 'Too many failed login attempts. Try again later.']);
        }
        $stmt = $pdo->prepare('SELECT id, name, email, is_admin, is_active, password_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if (!$user || (int) ($user['is_active'] ?? 1) !== 1 || !password_verify($password, (string) $user['password_hash'])) {
            login_fail($pdo, $lockKey);
            respond(401, ['message' => 'Invalid credentials']);
        }
        $requireAdminMfa = (bool) ($config['admin_require_mfa'] ?? false);
        if ($requireAdminMfa && (int) ($user['is_admin'] ?? 0) === 1 && $mfaCode !== (string) $config['admin_mfa_code']) {
            login_fail($pdo, $lockKey);
            respond(401, ['message' => 'Invalid MFA code']);
        }
        login_success($pdo, $lockKey);
        $csrf = bin2hex(random_bytes(24));
        $token = jwt_sign(['id' => (int) $user['id'], 'email' => $user['email'], 'csrf' => $csrf], $config['jwt_secret']);
        respond(200, [
            'token' => $token,
            'csrfToken' => $csrf,
            'user' => [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'is_admin' => (int) ($user['is_admin'] ?? 0),
            ],
        ]);
    }

    if ($path === '/auth/user' && $method === 'GET') {
        $user = auth_user($pdo, $config['jwt_secret']);
        if (!$user) {
            respond(401, ['message' => 'Unauthorized']);
        }
        respond(200, $user);
    }

    if ($path === '/profile' && in_array($method, ['PATCH', 'POST'], true)) {
        $user = auth_user($pdo, $config['jwt_secret']);
        if (!$user) {
            respond(401, ['message' => 'Unauthorized']);
        }
        $body = body_json();
        $name = str_clean($body['name'] ?? '', 120);
        $phone = str_clean($body['phone'] ?? '', 50);
        if ($name === '') {
            respond(400, ['message' => 'Name is required']);
        }
        $pdo->prepare('UPDATE users SET name = :n, phone = :p WHERE id = :id')->execute([
            'n' => $name,
            'p' => $phone !== '' ? $phone : null,
            'id' => (int) $user['id'],
        ]);
        respond(200, ['id' => (int) $user['id'], 'name' => $name, 'phone' => $phone]);
    }

    if ($path === '/orders' && $method === 'GET') {
        $user = auth_user($pdo, $config['jwt_secret']);
        if (!$user) {
            respond(200, []);
        }
        $ordersStmt = $pdo->prepare('SELECT id, created_at AS date, total, status FROM orders WHERE user_id = :uid ORDER BY id DESC LIMIT 20');
        $ordersStmt->execute(['uid' => (int) $user['id']]);
        $orders = $ordersStmt->fetchAll();
        $itemsStmt = $pdo->prepare('SELECT product_id AS productId, name, quantity, price FROM order_items WHERE order_id = :id ORDER BY id');
        $result = [];
        foreach ($orders as $order) {
            $itemsStmt->execute(['id' => (int) $order['id']]);
            $order['items'] = $itemsStmt->fetchAll();
            $result[] = $order;
        }
        respond(200, $result);
    }

    if ($path === '/orders' && $method === 'POST') {
        $body = body_json();
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $shipping = is_array($body['shipping'] ?? null) ? $body['shipping'] : [];
        $shippingCost = max(0, (int) ($body['shippingCost'] ?? 0));
        $first = str_clean($shipping['firstName'] ?? '', 100);
        $last = str_clean($shipping['lastName'] ?? '', 100);
        $email = mb_strtolower(str_clean($shipping['email'] ?? '', 255));
        if (!$items || $first === '' || $last === '' || !valid_email($email)) {
            respond(400, ['message' => 'Invalid order payload']);
        }
        $auth = auth_user($pdo, $config['jwt_secret']);
        $pdo->beginTransaction();
        try {
            $productStmt = $pdo->prepare('SELECT id, name, price, stock_qty FROM products WHERE id = :id LIMIT 1 FOR UPDATE');
            $prepared = [];
            $subtotal = 0;
            foreach ($items as $item) {
                $pid = (int) ($item['productId'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                if ($pid <= 0 || $qty <= 0) {
                    throw new RuntimeException('Invalid order item payload');
                }
                $productStmt->execute(['id' => $pid]);
                $p = $productStmt->fetch();
                if (!$p) {
                    throw new RuntimeException('Product not found');
                }
                if ((int) $p['stock_qty'] < $qty) {
                    throw new RuntimeException('Insufficient stock for ' . $p['name']);
                }
                $subtotal += (int) $p['price'] * $qty;
                $prepared[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'price' => (int) $p['price'], 'qty' => $qty];
            }
            $total = $subtotal + $shippingCost;
            $pdo->prepare('INSERT INTO orders (user_id, customer_name, email, phone, address_json, total, shipping, status, created_at) VALUES (:uid,:n,:e,:p,:a,:t,:s,"pending",NOW())')
                ->execute([
                    'uid' => $auth ? (int) $auth['id'] : null,
                    'n' => trim($first . ' ' . $last),
                    'e' => $email,
                    'p' => str_clean($shipping['phone'] ?? '-', 50) ?: '-',
                    'a' => json_encode($shipping, JSON_UNESCAPED_UNICODE),
                    't' => $total,
                    's' => $shippingCost,
                ]);
            $orderId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, price, quantity) VALUES (:oid,:pid,:n,:p,:q)');
            $updateStock = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - :q_sub, in_stock = IF(stock_qty - :q_chk > 0, 1, 0) WHERE id = :id');
            foreach ($prepared as $it) {
                $insertItem->execute(['oid' => $orderId, 'pid' => $it['id'], 'n' => $it['name'], 'p' => $it['price'], 'q' => $it['qty']]);
                $updateStock->execute(['q_sub' => $it['qty'], 'q_chk' => $it['qty'], 'id' => $it['id']]);
            }
            $pdo->commit();
            queue_email($pdo, 'order_created', $email, ['orderId' => $orderId, 'total' => $total]);
            respond(201, ['id' => $orderId, 'status' => 'pending']);
        } catch (Throwable $e) {
            $pdo->rollBack();
            respond(400, ['message' => $e->getMessage()]);
        }
    }

    if ($path === '/admin/users' && $method === 'GET') {
        admin_required($pdo, $config['jwt_secret']);
        $rows = $pdo->query('SELECT id, name, email, is_admin AS isAdmin, is_active AS isActive, phone, created_at AS createdAt, updated_at AS updatedAt FROM users ORDER BY id DESC LIMIT 500')->fetchAll();
        respond(200, $rows);
    }

    if ($path === '/admin/users' && $method === 'POST') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $body = body_json();
        $name = str_clean($body['name'] ?? '', 120);
        $email = mb_strtolower(str_clean($body['email'] ?? '', 255));
        $password = (string) ($body['password'] ?? '');
        $isAdmin = (int) (($body['isAdmin'] ?? false) ? 1 : 0);
        if ($name === '' || !valid_email($email) || strlen($password) < 8) {
            respond(400, ['message' => 'Invalid user payload']);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, is_admin, is_active) VALUES (:n,:e,:h,:a,1)');
        $stmt->execute(['n' => $name, 'e' => $email, 'h' => $hash, 'a' => $isAdmin]);
        log_event($pdo, 'info', 'admin_user_created', 'Admin created user', ['email' => $email, 'isAdmin' => $isAdmin]);
        respond(201, ['id' => (int) $pdo->lastInsertId()]);
    }

    if (preg_match('#^/admin/users/(\d+)$#', $path, $m) && in_array($method, ['PATCH', 'POST'], true)) {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $userId = (int) $m[1];
        $body = body_json();
        $stmt = $pdo->prepare('UPDATE users SET name = COALESCE(:n,name), email = COALESCE(:e,email), is_admin = COALESCE(:a,is_admin), is_active = COALESCE(:ac,is_active), phone = COALESCE(:p,phone) WHERE id = :id');
        $stmt->execute([
            'n' => isset($body['name']) ? str_clean($body['name'], 120) : null,
            'e' => isset($body['email']) ? mb_strtolower(str_clean($body['email'], 255)) : null,
            'a' => isset($body['isAdmin']) ? ((bool) $body['isAdmin'] ? 1 : 0) : null,
            'ac' => isset($body['isActive']) ? ((bool) $body['isActive'] ? 1 : 0) : null,
            'p' => isset($body['phone']) ? str_clean($body['phone'], 50) : null,
            'id' => $userId,
        ]);
        respond(200, ['id' => $userId]);
    }

    if ($path === '/admin/users/bulk' && $method === 'POST') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $body = body_json();
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        $action = str_clean($body['action'] ?? '', 40);
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $normalized[] = $id;
            }
        }
        if (!$normalized || !in_array($action, ['deactivate', 'activate', 'grant_admin', 'revoke_admin'], true)) {
            respond(400, ['message' => 'Invalid bulk payload']);
        }
        $in = implode(',', $normalized);
        if ($action === 'deactivate') {
            $pdo->exec("UPDATE users SET is_active = 0 WHERE id IN ($in)");
        } elseif ($action === 'activate') {
            $pdo->exec("UPDATE users SET is_active = 1 WHERE id IN ($in)");
        } elseif ($action === 'grant_admin') {
            $pdo->exec("UPDATE users SET is_admin = 1 WHERE id IN ($in)");
        } else {
            $pdo->exec("UPDATE users SET is_admin = 0 WHERE id IN ($in)");
        }
        log_event($pdo, 'warning', 'admin_users_bulk_action', 'Admin executed bulk action', ['action' => $action, 'count' => count($normalized)]);
        respond(200, ['updated' => count($normalized)]);
    }

    if ($path === '/admin/products' && $method === 'GET') {
        admin_required($pdo, $config['jwt_secret']);
        $rows = $pdo->query('SELECT * FROM products ORDER BY id DESC LIMIT 500')->fetchAll();
        respond(200, array_map(fn($r) => map_product($pdo, $r), $rows));
    }

    if ($path === '/admin/products' && $method === 'POST') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $body = body_json();
        $name = str_clean($body['name'] ?? '', 200);
        $slug = mb_strtolower(str_clean($body['slug'] ?? '', 150));
        $description = str_clean($body['description'] ?? '', 4000);
        $material = str_clean($body['material'] ?? '', 120);
        $color = str_clean($body['color'] ?? '', 120);
        $style = str_clean($body['style'] ?? '', 120);
        $category = str_clean($body['category'] ?? '', 80);
        $images = normalize_images($body['images'] ?? []);
        $price = max(0, (int) ($body['price'] ?? 0));
        $stockQty = max(0, (int) ($body['stockQty'] ?? 0));
        $dimensions = str_clean($body['dimensions'] ?? '', 255);
        if ($name === '' || $slug === '' || $description === '' || $category === '' || !$images) {
            respond(400, ['message' => 'Invalid product payload']);
        }
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            respond(400, ['message' => 'Invalid slug format']);
        }
        $stmt = $pdo->prepare('
            INSERT INTO products (name, slug, price, description, material, color, style, category, images_json, rating, in_stock, stock_qty, dimensions)
            VALUES (:name,:slug,:price,:description,:material,:color,:style,:category,:images_json,0,:in_stock,:stock_qty,:dimensions)
        ');
        try {
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'price' => $price,
                'description' => $description,
                'material' => $material,
                'color' => $color,
                'style' => $style,
                'category' => $category,
                'images_json' => json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'in_stock' => $stockQty > 0 ? 1 : 0,
                'stock_qty' => $stockQty,
                'dimensions' => $dimensions !== '' ? $dimensions : null,
            ]);
        } catch (Throwable $e) {
            respond(409, ['message' => 'Product with this slug already exists']);
        }
        respond(201, ['id' => (int) $pdo->lastInsertId()]);
    }

    if (preg_match('#^/admin/products/(\d+)$#', $path, $m) && in_array($method, ['PATCH', 'POST'], true)) {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $productId = (int) $m[1];
        $body = body_json();

        $fields = [];
        $params = ['id' => $productId];

        if (array_key_exists('name', $body)) {
            $name = str_clean($body['name'], 200);
            if ($name === '') {
                respond(400, ['message' => 'Name is required']);
            }
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
        if (array_key_exists('slug', $body)) {
            $slug = mb_strtolower(str_clean($body['slug'], 150));
            if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
                respond(400, ['message' => 'Invalid slug format']);
            }
            $fields[] = 'slug = :slug';
            $params['slug'] = $slug;
        }
        if (array_key_exists('price', $body)) {
            $fields[] = 'price = :price';
            $params['price'] = max(0, (int) $body['price']);
        }
        if (array_key_exists('description', $body)) {
            $description = str_clean($body['description'], 4000);
            if ($description === '') {
                respond(400, ['message' => 'Description is required']);
            }
            $fields[] = 'description = :description';
            $params['description'] = $description;
        }
        if (array_key_exists('material', $body)) {
            $fields[] = 'material = :material';
            $params['material'] = str_clean($body['material'], 120);
        }
        if (array_key_exists('color', $body)) {
            $fields[] = 'color = :color';
            $params['color'] = str_clean($body['color'], 120);
        }
        if (array_key_exists('style', $body)) {
            $fields[] = 'style = :style';
            $params['style'] = str_clean($body['style'], 120);
        }
        if (array_key_exists('category', $body)) {
            $category = str_clean($body['category'], 80);
            if ($category === '') {
                respond(400, ['message' => 'Category is required']);
            }
            $fields[] = 'category = :category';
            $params['category'] = $category;
        }
        if (array_key_exists('images', $body)) {
            $images = normalize_images($body['images']);
            if (!$images) {
                respond(400, ['message' => 'At least one image is required']);
            }
            $fields[] = 'images_json = :images_json';
            $params['images_json'] = json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('dimensions', $body)) {
            $dimensions = str_clean($body['dimensions'], 255);
            $fields[] = 'dimensions = :dimensions';
            $params['dimensions'] = $dimensions !== '' ? $dimensions : null;
        }
        if (array_key_exists('stockQty', $body)) {
            $stockQty = max(0, (int) $body['stockQty']);
            $fields[] = 'stock_qty = :stock_qty';
            $fields[] = 'in_stock = :in_stock';
            $params['stock_qty'] = $stockQty;
            $params['in_stock'] = $stockQty > 0 ? 1 : 0;
        }

        if (!$fields) {
            respond(400, ['message' => 'No fields to update']);
        }

        $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $e) {
            respond(409, ['message' => 'Unable to update product. Check slug uniqueness.']);
        }
        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare('SELECT id FROM products WHERE id = :id LIMIT 1');
            $existsStmt->execute(['id' => $productId]);
            if (!$existsStmt->fetch()) {
                respond(404, ['message' => 'Product not found']);
            }
        }
        respond(200, ['id' => $productId]);
    }

    if (preg_match('#^/admin/products/(\d+)$#', $path, $m) && $method === 'DELETE') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $productId = (int) $m[1];
        try {
            $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
            $stmt->execute(['id' => $productId]);
        } catch (Throwable $e) {
            respond(409, ['message' => 'Нельзя удалить товар: он используется в заказах']);
        }
        if ($stmt->rowCount() === 0) {
            respond(404, ['message' => 'Product not found']);
        }
        respond(200, ['id' => $productId, 'deleted' => true]);
    }

    if ($path === '/admin/categories' && $method === 'GET') {
        admin_required($pdo, $config['jwt_secret']);
        $rows = $pdo->query('SELECT id, name, slug, image, description FROM categories ORDER BY id')->fetchAll();
        respond(200, $rows);
    }

    if ($path === '/admin/categories' && $method === 'POST') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $body = body_json();
        $name = str_clean($body['name'] ?? '', 120);
        $slug = mb_strtolower(str_clean($body['slug'] ?? '', 80));
        $image = str_clean($body['image'] ?? '', 2048);
        $description = str_clean($body['description'] ?? '', 4000);
        if ($name === '' || $slug === '' || $image === '' || $description === '') {
            respond(400, ['message' => 'Invalid category payload']);
        }
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            respond(400, ['message' => 'Invalid slug format']);
        }
        $stmt = $pdo->prepare('INSERT INTO categories (name, slug, image, description) VALUES (:name,:slug,:image,:description)');
        try {
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'image' => $image,
                'description' => $description,
            ]);
        } catch (Throwable $e) {
            respond(409, ['message' => 'Category with this slug already exists']);
        }
        respond(201, ['id' => (int) $pdo->lastInsertId()]);
    }

    if (preg_match('#^/admin/categories/(\d+)$#', $path, $m) && in_array($method, ['PATCH', 'POST'], true)) {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $categoryId = (int) $m[1];
        $body = body_json();
        $fields = [];
        $params = ['id' => $categoryId];

        if (array_key_exists('name', $body)) {
            $name = str_clean($body['name'], 120);
            if ($name === '') {
                respond(400, ['message' => 'Name is required']);
            }
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
        if (array_key_exists('slug', $body)) {
            $slug = mb_strtolower(str_clean($body['slug'], 80));
            if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) {
                respond(400, ['message' => 'Invalid slug format']);
            }
            $fields[] = 'slug = :slug';
            $params['slug'] = $slug;
        }
        if (array_key_exists('image', $body)) {
            $image = str_clean($body['image'], 2048);
            if ($image === '') {
                respond(400, ['message' => 'Image is required']);
            }
            $fields[] = 'image = :image';
            $params['image'] = $image;
        }
        if (array_key_exists('description', $body)) {
            $description = str_clean($body['description'], 4000);
            if ($description === '') {
                respond(400, ['message' => 'Description is required']);
            }
            $fields[] = 'description = :description';
            $params['description'] = $description;
        }

        if (!$fields) {
            respond(400, ['message' => 'No fields to update']);
        }

        $sql = 'UPDATE categories SET ' . implode(', ', $fields) . ' WHERE id = :id';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable $e) {
            respond(409, ['message' => 'Unable to update category. Check slug uniqueness.']);
        }
        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
            $existsStmt->execute(['id' => $categoryId]);
            if (!$existsStmt->fetch()) {
                respond(404, ['message' => 'Category not found']);
            }
        }
        respond(200, ['id' => $categoryId]);
    }

    if (preg_match('#^/admin/categories/(\d+)$#', $path, $m) && $method === 'DELETE') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $categoryId = (int) $m[1];
        $catStmt = $pdo->prepare('SELECT slug FROM categories WHERE id = :id LIMIT 1');
        $catStmt->execute(['id' => $categoryId]);
        $category = $catStmt->fetch();
        if (!$category) {
            respond(404, ['message' => 'Category not found']);
        }
        $productsCountStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM products WHERE category = :slug');
        $productsCountStmt->execute(['slug' => (string) $category['slug']]);
        $productsCount = (int) ($productsCountStmt->fetch()['c'] ?? 0);
        if ($productsCount > 0) {
            respond(409, ['message' => 'Нельзя удалить категорию: в ней есть товары']);
        }
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $categoryId]);
        respond(200, ['id' => $categoryId, 'deleted' => true]);
    }

    if ($path === '/admin/orders' && $method === 'GET') {
        admin_required($pdo, $config['jwt_secret']);
        $rows = $pdo->query('SELECT id, user_id AS userId, customer_name AS customerName, email, total, shipping, status, created_at AS createdAt FROM orders ORDER BY id DESC LIMIT 200')->fetchAll();
        respond(200, $rows);
    }

    if ($path === '/admin/uploads' && $method === 'POST') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
            respond(400, ['message' => 'Image file is required']);
        }
        $f = $_FILES['image'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            respond(400, ['message' => 'Upload failed']);
        }
        if (($f['size'] ?? 0) > 5 * 1024 * 1024) {
            respond(400, ['message' => 'Image is too large']);
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: 'application/octet-stream';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            respond(400, ['message' => 'Unsupported image format']);
        }
        if (!malware_scan($tmp)) {
            log_event($pdo, 'warning', 'upload_malware_detected', 'Upload blocked by malware scan', ['mime' => $mime]);
            respond(400, ['message' => 'Malware signature detected']);
        }
        $name = 'product_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.jpg';
        $target = $uploadsDir . DIRECTORY_SEPARATOR . $name;
        $raw = @file_get_contents($tmp);
        $saved = false;
        if ($raw !== false && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $img = @imagecreatefromstring($raw);
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                $maxW = 1600;
                if ($w > $maxW) {
                    $newW = $maxW;
                    $newH = (int) round(($h / $w) * $newW);
                    $dst = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    $saved = imagejpeg($dst, $target, 85);
                    imagedestroy($dst);
                } else {
                    $saved = imagejpeg($img, $target, 85);
                }
                imagedestroy($img);
            }
        }
        if (!$saved) {
            $saved = @move_uploaded_file($tmp, $target);
        }
        if (!$saved) {
            respond(500, ['message' => 'Unable to save image']);
        }
        respond(201, ['url' => '/php-api/uploads/' . $name]);
    }

    if ($path === '/admin/products/export' && $method === 'GET') {
        admin_required($pdo, $config['jwt_secret']);
        $format = strtolower((string) ($_GET['format'] ?? 'json'));
        $rows = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();
        $products = array_map(fn($r) => map_product($pdo, $r), $rows);
        if ($format === 'csv') {
            $fp = fopen('php://temp', 'w+');
            fputcsv($fp, ['id', 'name', 'slug', 'price', 'category', 'stockQty', 'inStock']);
            foreach ($products as $p) {
                fputcsv($fp, [$p['id'], $p['name'], $p['slug'], $p['price'], $p['category'], $p['stockQty'] ?? 0, $p['inStock'] ? 1 : 0]);
            }
            rewind($fp);
            header('Content-Type: text/csv; charset=utf-8');
            echo stream_get_contents($fp) ?: '';
            fclose($fp);
            exit;
        }
        respond(200, ['products' => $products]);
    }

    if ($path === '/admin/products/import' && $method === 'POST') {
        admin_required($pdo, $config['jwt_secret']);
        admin_csrf_required($config['jwt_secret']);
        $body = body_json();
        $list = is_array($body['products'] ?? null) ? $body['products'] : [];
        if (!$list) {
            respond(400, ['message' => 'Products payload is empty']);
        }
        $stmt = $pdo->prepare('
            INSERT INTO products (name, slug, price, description, material, color, style, category, images_json, rating, in_stock, stock_qty, dimensions)
            VALUES (:name,:slug,:price,:description,:material,:color,:style,:category,:images_json,:rating,:in_stock,:stock_qty,:dimensions)
            ON DUPLICATE KEY UPDATE
              name = VALUES(name),
              price = VALUES(price),
              description = VALUES(description),
              material = VALUES(material),
              color = VALUES(color),
              style = VALUES(style),
              category = VALUES(category),
              images_json = VALUES(images_json),
              in_stock = VALUES(in_stock),
              stock_qty = VALUES(stock_qty),
              dimensions = VALUES(dimensions)
        ');
        $count = 0;
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = str_clean($item['name'] ?? '', 200);
            $slug = str_clean($item['slug'] ?? '', 150);
            $desc = str_clean($item['description'] ?? '', 4000);
            $images = is_array($item['images'] ?? null) ? array_values($item['images']) : [];
            if ($name === '' || $slug === '' || $desc === '' || !$images) {
                continue;
            }
            $qty = max(0, (int) ($item['stockQty'] ?? 0));
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'price' => max(0, (int) ($item['price'] ?? 0)),
                'description' => $desc,
                'material' => str_clean($item['material'] ?? '', 120),
                'color' => str_clean($item['color'] ?? '', 120),
                'style' => str_clean($item['style'] ?? '', 120),
                'category' => str_clean($item['category'] ?? '', 80),
                'images_json' => json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'rating' => isset($item['rating']) ? (float) $item['rating'] : 0,
                'in_stock' => $qty > 0 ? 1 : 0,
                'stock_qty' => $qty,
                'dimensions' => str_clean($item['dimensions'] ?? '', 255),
            ]);
            $count++;
        }
        respond(200, ['imported' => $count]);
    }

    respond(404, ['message' => 'Not found']);
} catch (Throwable $e) {
    if ($pdo instanceof PDO) {
        log_event($pdo, 'error', 'unhandled_exception', $e->getMessage(), ['path' => $path, 'method' => $method]);
    }
    respond(500, ['message' => 'Internal server error']);
}
