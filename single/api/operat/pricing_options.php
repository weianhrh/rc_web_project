<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/venue_pricing_policy.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// RC 本地定价规则，独立于星河的接收与同步规则。
const RC_PRICING_RATE_MIN = 0.8;
const RC_PRICING_RATE_MAX = 3;

function pricingResponse($code, $message, $data = []) {
    echo json_encode([
        'code' => $code, 'msg' => $message, 'data' => $data,
        'rate_min' => RC_PRICING_RATE_MIN, 'rate_max' => RC_PRICING_RATE_MAX,
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

function rcInsertPricingOption($db, $venue, $minutes, $battery, $notes, $title, $manualId) {
    // 与 RC 旧接口相同：费率校验使用提交的小数，落库沿用电池数的整数绑定。
    $type = '按次计费';
    $columns = 'Minutes, Battery, Notes, Title, BindLocation, PricingType';
    if ($manualId === null) {
        $stmt = $db->prepare("INSERT INTO PricingOptions ($columns) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissss', $minutes, $battery, $notes, $title, $venue, $type);
    } else {
        $stmt = $db->prepare("INSERT INTO PricingOptions ($columns, ID) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iissssi', $minutes, $battery, $notes, $title, $venue, $type, $manualId);
    }
    $stmt->execute();
    $stmt->close();
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
    $lock = $db->prepare('SELECT id FROM venues WHERE id = ? FOR UPDATE');
    $lock->bind_param('i', $venue);
    $lock->execute();
    if (!$lock->get_result()->fetch_row()) {
        throw new DomainException('场地不存在', 2);
    }

    if ($action === 'insert_default' || $action === 'add') {
        $manualId = venuePricingNextManualId($db);
        $stmt = $db->prepare('SELECT COUNT(*) FROM PricingOptions WHERE BindLocation = ?');
        $stmt->bind_param('s', $venue);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_row()[0];
        if ($action === 'add' && $count >= 4) {
            throw new DomainException('套餐数量已达上限（4个），不能继续添加', 4);
        }
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

    if ($action === 'insert_default') {
        if ($count === 0) {
            rcInsertPricingOption($db, $venue, 4, 5, '不要结束订单，遇到问题，换车驾驶', '4分钟5电池(福利)', $manualId);
            $secondId = $manualId === null ? null : $manualId + 1;
            rcInsertPricingOption($db, $venue, 10, 10, '推荐用于常规使用', '10分钟10电池(推荐)', $secondId);
        }
        $message = $count === 0 ? '默认套餐生成成功' : '场地已有套餐';
    } elseif ($action === 'delete') {
        $stmt = $db->prepare('DELETE FROM PricingOptions WHERE ID = ? AND BindLocation = ?');
        $stmt->bind_param('is', $id, $venue);
        $stmt->execute();
        $message = '套餐删除成功';
    } else {
        $rawMinutes = $input['minutes'] ?? null;
        if (!is_scalar($rawMinutes) || !preg_match('/^\d+$/D', (string)$rawMinutes)) {
            throw new DomainException('可驾驶时长必须为纯整数', 7);
        }
        $minutes = (int)$rawMinutes;
        if ($minutes < 2 || $minutes > 120) {
            throw new DomainException('可驾驶时长必须在 2 至 120 分钟之间', $minutes > 120 ? 6 : 7);
        }

        $battery = pricingDecimalText($input['battery'] ?? null);
        if ($battery === null || $battery[0] === '-') {
            throw new DomainException('电池费用必须为纯数字，可为小数', 8);
        }
        if ((float)$battery < 1) {
            throw new DomainException('套餐不能低于 1 元钱', 1);
        }
        $rate = round((float)$battery / $minutes, 2);
        if ((int)$user['role_id'] !== 1 && ($rate < RC_PRICING_RATE_MIN || $rate > RC_PRICING_RATE_MAX)) {
            throw new DomainException('费率不在允许范围（' . RC_PRICING_RATE_MIN . '～' . RC_PRICING_RATE_MAX . " 元/分钟），当前费率 {$rate}，提交无效", 5);
        }

        $title = $input['packageName'] ?? '';
        $notes = $input['notes'] ?? '';
        if (!is_string($title) || trim($title) === '' || !is_string($notes)) {
            throw new DomainException('请输入有效的套餐名称和备注', 2);
        }
        if ($action === 'add') {
            rcInsertPricingOption($db, $venue, $minutes, $battery, $notes, $title, $manualId);
            $message = '套餐添加成功';
        } else {
            if ((isset($input['original_minutes']) && pricingDecimalText($input['original_minutes']) !== pricingDecimalText($existing['Minutes']))
                || (isset($input['original_battery']) && pricingDecimalText($input['original_battery']) !== pricingDecimalText($existing['Battery']))) {
                throw new DomainException('套餐价格或时长已变动，请刷新后再保存', 9);
            }
            $stmt = $db->prepare('UPDATE PricingOptions SET Minutes = ?, Battery = ?, Notes = ?, Title = ? WHERE ID = ? AND BindLocation = ?');
            $stmt->bind_param('iissis', $minutes, $battery, $notes, $title, $id, $venue);
            $stmt->execute();
            $message = '更新成功';
        }
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
