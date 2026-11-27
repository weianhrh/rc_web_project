<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_pricing_policy.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

function pricingResponse($code, $message, $data = []) {
    echo json_encode([
        'code' => $code, 'msg' => $message, 'data' => $data,
        'rate_min' => VENUE_PRICING_MIN_RATE, 'rate_max' => VENUE_PRICING_MAX_RATE,
        'discount_rate_min' => VENUE_PRICING_DISCOUNT_MIN_RATE, 'discount_threshold' => VENUE_PRICING_DISCOUNT_THRESHOLD,
    ], JSON_UNESCAPED_UNICODE);
}

// 按数值比较数据库返回的旧小数，避免把 9.8 分钟截成 9。
function pricingDecimalText($value) {
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    // 使用 JSON 的浮点精度，避免把 48.00000000000001 误认成 48。
    $text = is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION) : (string)$value;
    if (!is_string($text) || !preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $parts)) {
        return null;
    }
    $whole = ltrim($parts[2], '0');
    $fraction = rtrim($parts[3] ?? '', '0');
    $number = ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    return $number === '0' ? '0' : $parts[1] . $number;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new Database();
$db = $database->getConnection();
$transaction = false;
try {
    $session = $_COOKIE['session_token'] ?? '';
    $user = $session ? $database->getUserBySessionToken($session) : null;
    if (empty($user['role_id'])) {
        throw new DomainException('用户未登录或会话已过期', 1001);
    }

    $input = $_POST;
    if (!$input && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
    }
    $isAdmin = in_array((int)$user['role_id'], [1, 2], true);
    $requestedVenue = $input['venue_id'] ?? $_GET['venue_id'] ?? '';
    $venue = $isAdmin && $requestedVenue !== '' ? (int)$requestedVenue : (int)$user['venue_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $stmt = $db->prepare('SELECT * FROM PricingOptions WHERE BindLocation = ? ORDER BY ID');
        $stmt->bind_param('s', $venue);
        $stmt->execute();
        pricingResponse(0, '', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        return;
    }

    $action = $input['action'] ?? 'edit';
    if (!in_array($action, ['add', 'edit', 'update', 'delete', 'insert_default'], true)) {
        throw new DomainException('未知套餐操作', 2);
    }
    $id = (int)($input['id'] ?? 0);
    $existing = null;
    $editsExisting = in_array($action, ['edit', 'update', 'delete'], true);
    if ($editsExisting && $id <= 0) {
        throw new DomainException('缺少套餐编号', 2);
    }
    // 旧版管理员页面更新、删除时只提交套餐编号。
    if ($editsExisting && $isAdmin && $requestedVenue === '') {
        $stmt = $db->prepare('SELECT BindLocation FROM PricingOptions WHERE ID = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $venue = (int)($stmt->get_result()->fetch_assoc()['BindLocation'] ?? 0);
    }
    if ($venue <= 0) {
        throw new DomainException('请选择有效场地', 2);
    }

    $db->begin_transaction();
    $transaction = true;
    if ($action === 'insert_default') {
        $added = venuePricingSeed($db, $venue);
        $message = $added ? '已生成 8 号场地模板套餐' : '场地已有套餐';
    } else {
        // 同一场地的默认生成、新增和编辑串行处理。
        $lock = $db->prepare('SELECT id FROM venues WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $venue);
        $lock->execute();
        if (!$lock->get_result()->fetch_row()) {
            throw new DomainException('场地不存在', 2);
        }

        if ($editsExisting) {
            $stmt = $db->prepare('SELECT Minutes, Battery FROM PricingOptions WHERE ID = ? AND BindLocation = ? FOR UPDATE');
            $stmt->bind_param('is', $id, $venue);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            if (!$existing) {
                throw new DomainException('当前场地找不到该套餐', 2);
            }
        }

        if ($action === 'delete') {
            $stmt = $db->prepare('DELETE FROM PricingOptions WHERE ID = ? AND BindLocation = ?');
            $stmt->bind_param('is', $id, $venue);
            $message = '套餐删除成功';
        } else {
            $minutesText = pricingDecimalText($input['minutes'] ?? null);
            $batteryText = pricingDecimalText($input['battery'] ?? null);
            if ($minutesText === null || !preg_match('/^[1-9]\d{0,11}$/D', $minutesText)) {
                throw new DomainException('可驾驶时长必须为正整数', 7);
            }
            if ($batteryText === null || !preg_match('/^[1-9]\d{0,11}$/D', $batteryText)) {
                throw new DomainException('套餐总电池数必须为正整数', 8);
            }
            $minutes = (int)$minutesText;
            $battery = (int)$batteryText;
            $title = $input['packageName'] ?? '';
            $notes = $input['notes'] ?? '';
            if (!is_string($title) || trim($title) === '' || !is_string($notes)) {
                throw new DomainException('请输入有效的套餐名称和备注', 2);
            }

            $samePrice = false;
            if ($existing) {
                $currentMinutes = pricingDecimalText($existing['Minutes']);
                $currentBattery = pricingDecimalText($existing['Battery']);
                if ((isset($input['original_minutes']) && pricingDecimalText($input['original_minutes']) !== $currentMinutes)
                    || (isset($input['original_battery']) && pricingDecimalText($input['original_battery']) !== $currentBattery)) {
                    throw new DomainException('套餐价格或时长已变动，请刷新后再保存', 9);
                }
                $samePrice = $minutesText === $currentMinutes && $batteryText === $currentBattery;
            }

            if (!$samePrice) {
                if ($minutes < 2 || $minutes > 120) {
                    throw new DomainException('可驾驶时长必须在 2 至 120 分钟之间', $minutes > 120 ? 6 : 7);
                }
                if (!venuePricingRateIsAllowed($minutes, $battery)) {
                    $minRate = venuePricingMinimumRate($battery);
                    throw new DomainException("每分钟费率须为 {$minRate} 至 " . VENUE_PRICING_MAX_RATE . ' 电池，且最多一位小数。', 5);
                }
            }

            if ($action === 'add') {
                $manualId = venuePricingNextManualId($db);
                $count = $db->prepare('SELECT COUNT(*) FROM PricingOptions WHERE BindLocation = ?');
                $count->bind_param('s', $venue);
                $count->execute();
                if ((int)$count->get_result()->fetch_row()[0] >= 4) {
                    throw new DomainException('套餐数量已达上限（4个），不能继续添加', 4);
                }
                $type = '按次计费';
                $columns = 'Minutes, Battery, Notes, Title, BindLocation, PricingType';
                if ($manualId === null) {
                    $stmt = $db->prepare("INSERT INTO PricingOptions ($columns) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('isssss', $minutes, $battery, $notes, $title, $venue, $type);
                } else {
                    $stmt = $db->prepare("INSERT INTO PricingOptions ($columns, ID) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('isssssi', $minutes, $battery, $notes, $title, $venue, $type, $manualId);
                }
                $message = '套餐添加成功';
            } else {
                $stmt = $db->prepare('UPDATE PricingOptions SET Minutes = ?, Battery = ?, Notes = ?, Title = ? WHERE ID = ? AND BindLocation = ?');
                $stmt->bind_param('isssis', $minutes, $battery, $notes, $title, $id, $venue);
                $message = '更新成功';
            }
        }
        $stmt->execute();
    }
    $db->commit();
    $transaction = false;
    pricingResponse(0, $message);
} catch (Throwable $error) {
    if ($transaction) {
        $db->rollback();
    }
    if ($error instanceof DomainException) {
        pricingResponse($error->getCode(), $error->getMessage());
    } else {
        error_log('[venue-pricing] ' . $error->getMessage());
        pricingResponse(2, '套餐操作失败，请查看后台日志');
    }
} finally {
    $database->close();
}
