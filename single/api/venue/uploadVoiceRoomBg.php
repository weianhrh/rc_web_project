<?php
// role_id=3 提交语音房背景图审核；同一场地每 7 天只能提交一次。
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/_voice_room_bg_review.php';

function voiceBgJson($code, $msg, $data = [], $httpStatus = 200)
{
    http_response_code($httpStatus);
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    voiceBgJson(1000, '仅支持 POST 请求', [], 405);
}

$database = null;
$store = null;
$savedPath = null;
$cooldownAcquired = false;
$submissionCommitted = false;
$venueId = 0;

try {
    $database = new Database();
    $sessionToken = isset($_COOKIE['session_token'])
        ? trim((string)$_COOKIE['session_token'])
        : '';

    if ($sessionToken === '') {
        voiceBgJson(1001, '用户未登录或会话已过期');
    }

    $rows = $database->query(
        'SELECT uid, role_id, venue_id FROM admin_users WHERE session_token = ? LIMIT 1',
        [$sessionToken]
    );
    if (!$rows) {
        voiceBgJson(1001, '用户未登录或会话已过期');
    }

    $user = $rows[0];
    $roleId = intval($user['role_id']);
    $operatorUid = intval($user['uid']);
    if (!in_array($roleId, [1, 2, 3], true)) {
        voiceBgJson(1002, '当前账号无权上传语音房背景图');
    }

    $requestedVenueId = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($roleId === 3) {
        $venueId = intval($user['venue_id']);
        if ($venueId <= 0) {
            voiceBgJson(1003, '当前账号未绑定场地');
        }
        if ($requestedVenueId > 0 && $requestedVenueId !== $venueId) {
            voiceBgJson(1003, '无权修改其他场地');
        }
    } else {
        $venueId = $requestedVenueId;
        if ($venueId <= 0) {
            voiceBgJson(1003, '缺少有效的场地ID');
        }
    }

    $venueRows = $database->query(
        'SELECT id, voice_room_bg_url FROM venues WHERE id = ? LIMIT 1',
        [$venueId]
    );
    if (!$venueRows) {
        voiceBgJson(1004, '场地不存在');
    }

    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        voiceBgJson(1005, '请选择需要上传的图片');
    }

    $file = $_FILES['image'];
    $uploadError = isset($file['error']) ? intval($file['error']) : UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '图片超过服务器允许的大小',
            UPLOAD_ERR_FORM_SIZE => '图片超过页面允许的大小',
            UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE => '请选择需要上传的图片',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件',
            UPLOAD_ERR_EXTENSION => '上传被服务器扩展中止'
        ];
        voiceBgJson(
            1006,
            isset($errorMessages[$uploadError]) ? $errorMessages[$uploadError] : '图片上传失败'
        );
    }

    $fileSize = isset($file['size']) ? intval($file['size']) : 0;
    if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
        voiceBgJson(1007, '图片大小必须大于0且不能超过5MB');
    }

    $tmpName = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        voiceBgJson(1008, '无效的上传文件');
    }

    $imageInfo = @getimagesize($tmpName);
    if (!$imageInfo || empty($imageInfo['mime'])) {
        voiceBgJson(1009, '上传文件不是有效图片');
    }

    $mimeToExtension = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];
    $mime = strtolower((string)$imageInfo['mime']);
    if (!isset($mimeToExtension[$mime])) {
        voiceBgJson(1010, '仅支持 JPG、PNG、WEBP 格式');
    }

    $width = isset($imageInfo[0]) ? intval($imageInfo[0]) : 0;
    $height = isset($imageInfo[1]) ? intval($imageInfo[1]) : 0;
    if ($width <= 0 || $height <= 0 || $width > 8192 || $height > 8192 ||
        ($width * $height) > 40000000) {
        voiceBgJson(1011, '图片尺寸无效或分辨率过大');
    }

    $store = new VoiceRoomBgReviewStore();
    $existingReview = $store->getReview($venueId);
    if (is_array($existingReview) &&
        isset($existingReview['status']) &&
        $existingReview['status'] === 'pending') {
        voiceBgJson(1023, '已有语音房背景图正在审核，请勿重复提交', [
            'review' => $existingReview,
            'cooldown' => $store->getCooldown($venueId)
        ]);
    }

    $cooldown = $store->getCooldown($venueId);
    if (!empty($cooldown['locked'])) {
        voiceBgJson(1022, '语音房背景图每7天只能上传一次，下次可上传时间：' .
            ($cooldown['until_iso'] ?? ''), [
            'cooldown' => $cooldown,
            'review' => $existingReview
        ]);
    }

    $uploadDir = __DIR__ . '/pending_voice_room_bg/';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
        voiceBgJson(1012, '待审核图片目录创建失败，请检查目录权限');
    }
    if (!is_writable($uploadDir)) {
        voiceBgJson(1012, '待审核图片目录不可写，请检查目录权限');
    }

    try {
        $random = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $random = substr(md5(uniqid((string)$venueId, true)), 0, 8);
    }

    $timestamp = date('YmdHis');
    $reviewId = 'voice_bg_' . $venueId . '_' . $timestamp . '_' . $random;
    $filename = $reviewId . '.' . $mimeToExtension[$mime];
    $savedPath = $uploadDir . $filename;
    $publicPath = 'pending_voice_room_bg/' . $filename;

    if (!move_uploaded_file($tmpName, $savedPath)) {
        voiceBgJson(1013, '图片保存失败，请检查目录权限');
    }

    $now = time();
    $cooldownPayload = [
        'locked' => true,
        'venue_id' => $venueId,
        'set_ts' => $now,
        'until_ts' => $now + VoiceRoomBgReviewStore::TTL_SECONDS,
        'until_iso' => date('Y-m-d H:i:s', $now + VoiceRoomBgReviewStore::TTL_SECONDS),
        'by_uid' => $operatorUid,
        'reason' => '提交语音房背景图审核'
    ];

    if (!$store->acquireCooldown($venueId, $cooldownPayload)) {
        @unlink($savedPath);
        $savedPath = null;
        voiceBgJson(1022, '语音房背景图每7天只能上传一次', [
            'cooldown' => $store->getCooldown($venueId),
            'review' => $store->getReview($venueId)
        ]);
    }
    $cooldownAcquired = true;

    $review = [
        'review_id' => $reviewId,
        'venue_id' => $venueId,
        'review_type' => 'voice_room_background',
        'pending_path' => $publicPath,
        'image_url' => $publicPath,
        'status' => 'pending',
        'reason' => '',
        'uploaded_at' => date('Y-m-d H:i:s', $now),
        'timestamp' => $now,
        'uploader_uid' => $operatorUid,
        'reviewer_uid' => 0,
        'reviewed_at' => null,
        'current_url' => (string)($venueRows[0]['voice_room_bg_url'] ?? '')
    ];
    $store->saveReview($venueId, $review);
    $submissionCommitted = true;

    $responseCooldown = $cooldownPayload;
    $responseCooldown['ttl'] = VoiceRoomBgReviewStore::TTL_SECONDS;
    if ($database) {
        $database->close();
    }
    if ($store) {
        $store->close();
    }

    voiceBgJson(0, '图片上传成功，等待审核', [
        'review' => $review,
        'cooldown' => $responseCooldown
    ]);
} catch (Throwable $e) {
    if (!$submissionCommitted && $savedPath && is_file($savedPath)) {
        @unlink($savedPath);
    }
    if (!$submissionCommitted && $cooldownAcquired && $store) {
        try {
            $store->releaseCooldown($venueId);
        } catch (Throwable $ignore) {
        }
    }
    error_log('uploadVoiceRoomBg failed: ' . $e->getMessage());
    voiceBgJson(1500, '服务器处理失败，请稍后重试');
}
