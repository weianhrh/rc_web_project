<?php
// /api/venue/reviewVoiceRoomBg.php
// 管理员审核加盟商提交的语音房背景图。

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/_voice_room_bg_review.php';

function jsonOut($code, $msg, $data = [])
{
    echo json_encode([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonOut(1000, '仅支持 POST 请求');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonOut(1003, '请求参数格式错误');
}

$sessionToken = $_COOKIE['session_token'] ?? '';
if ($sessionToken === '') {
    jsonOut(1001, '请先登录');
}

$database = null;
$store = null;

try {
    $database = new Database();
    $userRows = $database->query(
        'SELECT uid, role_id FROM admin_users WHERE session_token = ? LIMIT 1',
        [$sessionToken]
    );

    if (!$userRows || !in_array((int)$userRows[0]['role_id'], [1, 2], true)) {
        jsonOut(1002, '权限不足，仅管理员可审核');
    }

    $venueId = isset($input['venue_id']) ? (int)$input['venue_id'] : 0;
    $reviewId = trim((string)($input['review_id'] ?? ''));
    $action = trim((string)($input['action'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    $reviewerUid = (int)$userRows[0]['uid'];

    if ($venueId <= 0 || $reviewId === '') {
        jsonOut(1003, '缺少场地ID或审核记录ID');
    }
    if (!in_array($action, ['approve', 'reject'], true)) {
        jsonOut(1003, '审核动作无效');
    }
    if ($action === 'reject' && $reason === '') {
        jsonOut(1003, '请输入拒绝原因');
    }

    $store = new VoiceRoomBgReviewStore();
    $review = $store->getReview($venueId);

    if (!is_array($review)) {
        jsonOut(1004, '审核记录不存在或已过期');
    }
    if (($review['review_type'] ?? '') !== 'voice_room_background') {
        jsonOut(1004, '审核记录类型不正确');
    }
    if (!hash_equals((string)($review['review_id'] ?? ''), $reviewId)) {
        jsonOut(1004, '审核记录已更新，请刷新页面后重试');
    }

    $currentStatus = (string)($review['status'] ?? '');
    if ($currentStatus !== 'pending') {
        if (
            ($action === 'approve' && $currentStatus === 'approved') ||
            ($action === 'reject' && $currentStatus === 'rejected')
        ) {
            jsonOut(0, $currentStatus === 'approved' ? '该背景图已审核通过' : '该背景图已拒绝', [
                'status' => $currentStatus,
                'image_url' => $review['image_url'] ?? '',
            ]);
        }
        jsonOut(1004, '该审核记录已经处理，请刷新页面');
    }

    $reviewedAt = date('Y-m-d H:i:s');
    // 审核结束后保留 7 天结果供提交人查看；独立的一周上传冷却锁不会被续期。
    $statusTtl = VoiceRoomBgReviewStore::TTL_SECONDS;
    $pendingPath = ltrim((string)($review['pending_path'] ?? $review['image_url'] ?? ''), '/');
    $sourceName = basename($pendingPath);
    if (
        !preg_match(
            '#^pending_voice_room_bg/voice_bg_\d+_\d{14}_[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#i',
            $pendingPath
        ) ||
        pathinfo($sourceName, PATHINFO_FILENAME) !== $reviewId
    ) {
        jsonOut(1005, '待审核图片路径无效');
    }
    $sourceFile = __DIR__ . '/' . $pendingPath;

    if ($action === 'reject') {
        if (is_file($sourceFile)) {
            @unlink($sourceFile);
        }

        $review['status'] = 'rejected';
        $review['reason'] = $reason;
        $review['reviewer_uid'] = $reviewerUid;
        $review['reviewed_at'] = $reviewedAt;

        $store->saveReview($venueId, $review, $statusTtl);
        $store->removeFromPool($venueId);

        jsonOut(0, '语音房背景图已拒绝', [
            'status' => 'rejected',
            'reason' => $reason,
        ]);
    }

    if (!is_file($sourceFile)) {
        jsonOut(1005, '待审核图片文件不存在，请让提交人重新上传');
    }

    $finalDirectory = __DIR__ . '/voice_room_bg/';
    if (!is_dir($finalDirectory) && !@mkdir($finalDirectory, 0755, true)) {
        jsonOut(1005, '语音房背景图目录创建失败');
    }

    $destinationFile = $finalDirectory . $sourceName;
    if (file_exists($destinationFile)) {
        $destinationFile = $finalDirectory
            . 'voice_bg_' . $venueId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4))
            . '.' . strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
    }

    $moved = @rename($sourceFile, $destinationFile);
    if (!$moved) {
        $moved = @copy($sourceFile, $destinationFile);
        if ($moved) {
            @unlink($sourceFile);
        }
    }
    if (!$moved) {
        jsonOut(1005, '语音房背景图转存失败，请检查目录权限');
    }

    $publicUrl = 'https://open.rcwulian.cn/api/venue/voice_room_bg/' . basename($destinationFile);
    $venueRows = $database->query('SELECT id FROM venues WHERE id = ? LIMIT 1', [$venueId]);
    if (!$venueRows) {
        @rename($destinationFile, $sourceFile);
        jsonOut(1004, '场地不存在');
    }

    $updateResult = $database->query(
        'UPDATE venues SET voice_room_bg_url = ? WHERE id = ?',
        [$publicUrl, $venueId],
        true
    );
    if ($updateResult === false) {
        @rename($destinationFile, $sourceFile);
        jsonOut(1005, '语音房背景图地址更新失败');
    }

    $review['status'] = 'approved';
    $review['reason'] = '';
    $review['reviewer_uid'] = $reviewerUid;
    $review['reviewed_at'] = $reviewedAt;
    $review['image_url'] = $publicUrl;
    $review['approved_url'] = $publicUrl;

    $store->saveReview($venueId, $review, $statusTtl);
    $store->removeFromPool($venueId);

    jsonOut(0, '语音房背景图已审核通过', [
        'status' => 'approved',
        'image_url' => $publicUrl,
    ]);
} catch (Throwable $e) {
    error_log('reviewVoiceRoomBg failed: ' . $e->getMessage());
    jsonOut(1005, '审核处理失败，请检查服务器日志');
}
