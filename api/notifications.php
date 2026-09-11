<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

$user = app_require_auth();
$pdo = app_pdo();
$userId = (int)$user['id'];
$organizationId = (int)$user['organization_id'];

function notification_rows(PDO $pdo, int $organizationId, int $userId, int $limit = 80): array
{
    $limit = max(1, min(100, $limit));
    $statement = $pdo->prepare(
        "SELECT id, notification_type, title, message, action_url, read_at, created_at
         FROM notifications
         WHERE organization_id = :organization_id AND user_id = :user_id
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $statement->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
    return array_map(static fn(array $row): array => [
        'id' => (string)$row['id'],
        'type' => (string)$row['notification_type'],
        'title' => (string)$row['title'],
        'message' => (string)$row['message'],
        'actionUrl' => $row['action_url'] ?: null,
        'readAt' => $row['read_at'] ?: null,
        'createdAt' => (string)$row['created_at'],
    ], $statement->fetchAll());
}

function ensure_welcome_notification(PDO $pdo, int $organizationId, int $userId): void
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE organization_id = ? AND user_id = ?');
    $statement->execute([$organizationId, $userId]);
    if ((int)$statement->fetchColumn() > 0) {
        return;
    }
    $insert = $pdo->prepare(
        "INSERT INTO notifications
         (organization_id, user_id, notification_type, title, message, action_url)
         VALUES (?, ?, 'account.welcome', 'Welcome to the training workspace',
                 'Recent training, hiring, account, and administrative activity relevant to you will appear here.',
                 'index.php#dashboard')"
    );
    $insert->execute([$organizationId, $userId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ensure_welcome_notification($pdo, $organizationId, $userId);
    $limit = (int)($_GET['limit'] ?? 80);
    $notifications = notification_rows($pdo, $organizationId, $userId, $limit);
    $unread = count(array_filter($notifications, static fn(array $item): bool => !$item['readAt']));
    app_json_response(['ok' => true, 'notifications' => $notifications, 'unreadCount' => $unread]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    app_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$input = app_json_input();
app_verify_request_csrf($input);
$action = (string)($input['action'] ?? '');

if ($action === 'record_activity') {
    $eventType = trim((string)($input['eventType'] ?? ''));
    $allowedTypes = [
        'quiz.completed',
        'daily_training.completed',
        'kitchen_verification.completed',
        'flashcard_session.completed',
        'certification.issued',
    ];
    if (!in_array($eventType, $allowedTypes, true)) {
        app_json_response(['ok' => false, 'message' => 'Unsupported training activity type.'], 422);
    }
    $sourceId = trim((string)($input['sourceId'] ?? ''));
    if ($sourceId === '' || mb_strlen($sourceId) > 180) {
        app_json_response(['ok' => false, 'message' => 'A valid activity source identifier is required.'], 422);
    }
    $summary = is_array($input['summary'] ?? null) ? $input['summary'] : [];
    $occurredAt = trim((string)($input['occurredAt'] ?? ''));
    try {
        $occurred = $occurredAt !== '' ? new DateTimeImmutable($occurredAt) : new DateTimeImmutable('now');
    } catch (Throwable) {
        $occurred = new DateTimeImmutable('now');
    }
    $occurredSql = $occurred->format('Y-m-d H:i:s.u');

    $existing = $pdo->prepare(
        "SELECT id FROM training_events
         WHERE organization_id = ? AND user_id = ? AND event_type = ? AND source_type = 'browser_training' AND source_id = ?
         LIMIT 1"
    );
    $existing->execute([$organizationId, $userId, $eventType, $sourceId]);
    if (!$existing->fetchColumn()) {
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                "INSERT INTO training_events
                 (organization_id, user_id, event_type, source_type, source_id, event_data_json, occurred_at)
                 VALUES (?, ?, ?, 'browser_training', ?, ?, ?)"
            );
            $insert->execute([
                $organizationId,
                $userId,
                $eventType,
                $sourceId,
                $summary ? json_encode($summary, JSON_THROW_ON_ERROR) : null,
                $occurredSql,
            ]);
            app_audit($pdo, $organizationId, $userId, $eventType, 'training_event', $sourceId, null, $summary);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_json_response(['ok' => false, 'message' => 'The training activity could not be recorded.'], 500);
        }
    }
} elseif ($action === 'mark_all_read') {
    $statement = $pdo->prepare(
        'UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP(6)) WHERE organization_id = ? AND user_id = ? AND read_at IS NULL'
    );
    $statement->execute([$organizationId, $userId]);
} elseif ($action === 'mark_read') {
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(mixed $value): int => max(0, (int)$value),
        (array)($input['ids'] ?? [])
    ))));
    if (!$ids) {
        app_json_response(['ok' => false, 'message' => 'Choose at least one notification.'], 422);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare(
        "UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP(6))
         WHERE organization_id = ? AND user_id = ? AND id IN ({$placeholders})"
    );
    $statement->execute([$organizationId, $userId, ...$ids]);
} else {
    app_json_response(['ok' => false, 'message' => 'Unsupported notification action.'], 422);
}

$notifications = notification_rows($pdo, $organizationId, $userId, 80);
$unread = count(array_filter($notifications, static fn(array $item): bool => !$item['readAt']));
app_json_response(['ok' => true, 'notifications' => $notifications, 'unreadCount' => $unread]);
