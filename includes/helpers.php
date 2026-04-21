<?php
function newUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function logActivity(PDO $pdo, string $module, string $action, $record_id = null, string $label = '', array $meta = []): void {
    try {
        $user = $_SESSION['bh_user'] ?? [];
        $pdo->prepare("INSERT INTO activity_log (user_id, user_name, module, action, record_id, label, meta, ip, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([
                $user['id']   ?? null,
                $user['name'] ?? 'system',
                $module,
                $action,
                $record_id,
                $label,
                $meta ? json_encode($meta) : null,
                $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
    } catch (\Exception $e) {
        // Never break the app over a log failure
    }
}

function fmtHhMm(float $h): string {
    $m = (int)round($h * 60);
    return sprintf('%d:%02d', intdiv($m, 60), $m % 60);
}

function jsonOk(mixed $data = null): void {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
