<?php
// Shared by the APP API and the open management API (deploy one copy to each).
function rcvm_prepare($conn, $sql)
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('评论数据库查询准备失败');
    return $stmt;
}

function rcvm_execute($stmt)
{
    if (!$stmt->execute()) throw new RuntimeException('评论数据库操作失败');
}

function rcvm_message_id($value, $venueId)
{
    $value = trim((string)$value);
    return preg_match('/^voice:' . intval($venueId) . ':[A-Za-z0-9_.-]{1,120}$/D', $value) ? $value : '';
}

function rcvm_is_admin($conn, $uid)
{
    if (intval($uid) <= 0) return false;
    $stmt = rcvm_prepare($conn, 'SELECT id FROM admin_users WHERE uid = ? AND role_id IN (1, 2) LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('i', $uid);
    rcvm_execute($stmt);
    $allowed = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $allowed;
}

function rcvm_states($conn, $venueId, $messageIds, $canModerate = false)
{
    $ids = [];
    foreach ($messageIds as $id) {
        $id = rcvm_message_id($id, $venueId);
        if ($id !== '') $ids[$id] = $id;
    }
    if (!$ids) return [];
    if (count($ids) > 60) throw new InvalidArgumentException('最多查询60条评论');
    $params = array_merge([intval($venueId)], array_values($ids));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = rcvm_prepare($conn, "SELECT message_id, state, original_content, author_uid, updated_at
        FROM voice_comment_moderation WHERE venue_id = ? AND message_id IN ($marks)");
    if (!$stmt) throw new RuntimeException('评论管理表不可用，请先执行数据库升级');
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    rcvm_execute($stmt);
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $state = $row['state'];
        $rows[$row['message_id']] = [
            'message_id' => $row['message_id'],
            'state' => $state,
            // Hidden text never leaves this API for an ordinary viewer.
            'text' => ($state === 'normal' || $canModerate) ? $row['original_content'] : '',
            'updated_at' => $row['updated_at'],
        ];
    }
    $stmt->close();
    return $rows;
}

// Caller owns the transaction so report acceptance/muting and folding commit together.
function rcvm_set_state($conn, $venueId, $messageId, $authorUid, $content, $state,
                        $operatorUid, $source = 'app', $reportId = null, $preserveDeleted = false)
{
    $venueId = intval($venueId);
    $authorUid = intval($authorUid);
    $messageId = rcvm_message_id($messageId, $venueId);
    if ($venueId <= 0 || $messageId === '' || $authorUid <= 0
        || !in_array($state, ['normal', 'folded', 'deleted'], true)) {
        throw new InvalidArgumentException('评论定位参数错误');
    }
    $content = trim((string)$content);
    if ($content === '' || strlen($content) > 4096) throw new InvalidArgumentException('评论原文无效');
    $stmt = rcvm_prepare($conn, "INSERT IGNORE INTO voice_comment_moderation
        (message_id, venue_id, author_uid, original_content, state, updated_by)
        VALUES (?, ?, ?, ?, 'normal', ?)");
    $stmt->bind_param('siisi', $messageId, $venueId, $authorUid, $content, $operatorUid);
    rcvm_execute($stmt);
    $stmt->close();
    $stmt = rcvm_prepare($conn, 'SELECT venue_id, author_uid, state FROM voice_comment_moderation WHERE message_id = ? FOR UPDATE');
    $stmt->bind_param('s', $messageId);
    rcvm_execute($stmt);
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$old || intval($old['venue_id']) !== $venueId || intval($old['author_uid']) !== $authorUid) {
        throw new InvalidArgumentException('评论与房间或发送者不一致');
    }
    if ($preserveDeleted && $old['state'] === 'deleted') return 'already_deleted';
    if ($old['state'] === $state) return $state;
    $stmt = rcvm_prepare($conn, 'UPDATE voice_comment_moderation SET state = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP(6) WHERE message_id = ?');
    $stmt->bind_param('sis', $state, $operatorUid, $messageId);
    rcvm_execute($stmt);
    $stmt->close();
    $stmt = rcvm_prepare($conn, 'INSERT INTO voice_comment_actions
        (message_id, venue_id, previous_state, new_state, operator_uid, source, report_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sissisi', $messageId, $venueId, $old['state'], $state, $operatorUid, $source, $reportId);
    rcvm_execute($stmt);
    $stmt->close();
    return $state;
}

function rcvm_fold_report($conn, $reportId, $operatorUid)
{
    $stmt = rcvm_prepare($conn, 'SELECT handler_uid, report_type, message_id, comment_venue_id, comment_text
        FROM voice_reports WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $reportId);
    rcvm_execute($stmt);
    $report = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$report || intval($report['report_type']) !== 1) return 'not_comment';
    $messageId = rcvm_message_id($report['message_id'] ?? '', $report['comment_venue_id'] ?? 0);
    if ($messageId === '' || trim((string)($report['comment_text'] ?? '')) === '') {
        return 'unlocatable'; // Old reports still complete successfully; never match by text.
    }
    return rcvm_set_state($conn, $report['comment_venue_id'], $messageId,
        $report['handler_uid'], $report['comment_text'], 'folded', $operatorUid,
        'valid_report', $reportId, true);
}
