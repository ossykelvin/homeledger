<?php

declare(strict_types=1);

function clip_activity_summary(string $summary): string
{
    $summary = trim($summary);
    if ($summary === '') {
        return 'Household update';
    }
    if (text_length($summary) <= 255) {
        return $summary;
    }

    return function_exists('mb_substr') ? (string) mb_substr($summary, 0, 255) : substr($summary, 0, 255);
}

function current_actor_user_id(): ?int
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $id = (int) $user['id'];

    return $id > 0 ? $id : null;
}

function log_household_activity(
    int $householdId,
    ?int $actorUserId,
    string $eventKey,
    string $summary,
    ?PDO $pdo = null
): void {
    if ($householdId < 1 || $eventKey === '' || text_length($eventKey) > 40) {
        return;
    }

    $pdo ??= db();
    $stmt = $pdo->prepare(
        'INSERT INTO household_activity (household_id, actor_user_id, event_key, summary)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $householdId,
        $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
        $eventKey,
        clip_activity_summary($summary),
    ]);
}

/** @return list<array<string, mixed>> */
function household_activity_for_current(int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(
        'SELECT id, actor_user_id, event_key, summary, created_at
         FROM household_activity
         WHERE household_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([current_household_id()]);

    return $stmt->fetchAll() ?: [];
}

function activity_event_label(string $eventKey): string
{
    $labels = [
        'invited' => 'Invited',
        'joined' => 'Joined',
        'member_removed' => 'Member removed',
        'category_added' => 'Category added',
        'category_updated' => 'Category updated',
        'category_deleted' => 'Category deleted',
        'budget_set' => 'Budget',
        'household_renamed' => 'Household renamed',
        'csv_imported' => 'CSV imported',
        'backup_restored' => 'Backup restored',
    ];

    return $labels[$eventKey] ?? 'Update';
}
