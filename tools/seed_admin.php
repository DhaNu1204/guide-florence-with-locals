<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; } // CLI-only maintenance script (step 0.3): never reachable over HTTP
/**
 * Create or update an application user with a bcrypt password. No defaults, no passwords on the command line.
 *
 *   printf '%s' "$PASSWORD" | php tools/seed_admin.php --username dhanu --role admin --password-stdin
 *
 * --username <name>   required; matched against users.username (created if missing)
 * --role admin|viewer required
 * --password-stdin    required; the password is read from stdin (trailing newline stripped), min 8 chars
 */
$opts = getopt('', ['username:', 'role:', 'password-stdin']);
$username = trim($opts['username'] ?? '');
$role = $opts['role'] ?? '';
if ($username === '' || !in_array($role, ['admin', 'viewer'], true) || !isset($opts['password-stdin'])) {
    fwrite(STDERR, "usage: php tools/seed_admin.php --username <name> --role admin|viewer --password-stdin\n");
    exit(2);
}
$password = rtrim((string) stream_get_contents(STDIN), "\r\n");
if (strlen($password) < 8) { fwrite(STDERR, "password must be at least 8 characters (read from stdin)\n"); exit(2); }

$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../public_html/api/config.php';

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
if ($existing) {
    $stmt = $conn->prepare("UPDATE users SET password = ?, role = ? WHERE id = ?");
    $stmt->bind_param('ssi', $hash, $role, $existing['id']);
    $stmt->execute();
    echo "updated user #{$existing['id']} ({$username}, {$role})\n";
} else {
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $username, $hash, $role);
    $stmt->execute();
    echo "created user #{$conn->insert_id} ({$username}, {$role})\n";
}
