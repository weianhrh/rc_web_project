<?php
// 套餐电池数与分钟数均为正整数，折合每分钟的费率至多一位小数。后台自定义定价与历史套餐拉齐分别处理。
const VENUE_PRICING_MIN_RATE = 5;
const VENUE_PRICING_MAX_RATE = 10;
const VENUE_PRICING_DISCOUNT_THRESHOLD = 50;
const VENUE_PRICING_DISCOUNT_MIN_RATE = 3;

/** 兼容 DECIMAL 返回的整数，例如 "48.00"，拒绝取整、负数和科学计数法。 */
function venuePricingIsWholeQuantity($value): bool
{
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        return false;
    }
    if (is_float($value) && (!is_finite($value) || $value !== floor($value))) {
        return false;
    }
    $text = (string)$value;
    return preg_match('/^([0-9]{1,18})(?:\.0+)?$/D', $text, $parts) === 1
        && trim($parts[1], '0') !== '';
}

function venuePricingHasWholeQuantities(array $row): bool
{
    return venuePricingIsWholeQuantity($row['Minutes'] ?? null)
        && venuePricingIsWholeQuantity($row['Battery'] ?? null);
}

/** RC 8 号场地 2026-11-26 的四档，键为分钟数，值为套餐总电池数。 */
function venuePricingTemplateFloors(): array
{
    return [2 => 10, 4 => 20, 10 => 50, 20 => 90];
}

function venuePricingMinimumRate(int $battery): int
{
    return $battery > VENUE_PRICING_DISCOUNT_THRESHOLD ? VENUE_PRICING_DISCOUNT_MIN_RATE : VENUE_PRICING_MIN_RATE;
}

/** 以总电池数选择下限，并要求实际费率精确至最多一位小数。 */
function venuePricingRateIsAllowed(int $minutes, int $battery): bool
{
    if ($minutes <= 0 || $battery <= 0) {
        return false;
    }
    $wholePart = intdiv($battery, $minutes);
    if ($wholePart < venuePricingMinimumRate($battery)) {
        return false;
    }
    if ($wholePart > VENUE_PRICING_MAX_RATE
        || ($wholePart === VENUE_PRICING_MAX_RATE && $battery % $minutes !== 0)) {
        return false;
    }
    // 用整数整除判断小数位，避免浮点四舍五入。步长 = 分钟数 / gcd(分钟数, 10)。
    $divisor = ($minutes % 2 === 0 ? 2 : 1) * ($minutes % 5 === 0 ? 5 : 1);
    return $battery % intdiv($minutes, $divisor) === 0;
}

/** 存量对齐只按相同时长补齐电池。高于模板的价格以及其他时长保留。 */
function venuePricingAlignRow(array $row): array
{
    if (($row['PricingType'] ?? '') !== '按次计费' || !venuePricingHasWholeQuantities($row)) {
        return $row;
    }
    $minutes = (int)$row['Minutes'];
    $battery = (int)$row['Battery'];
    $target = venuePricingTemplateFloors()[$minutes] ?? null;
    if ($target === null || $battery >= $target) {
        return $row;
    }
    $row['Battery'] = $target;
    $title = $row['Title'] ?? null;
    $pattern = '/^(0*' . $minutes . '(?:[.]0+)?分钟)0*' . $battery . '(?:[.]0+)?电池/u';
    if (is_string($title) && preg_match($pattern, $title, $parts)) {
        $row['Title'] = $parts[1] . $target . '电池' . substr($title, strlen($parts[0]));
    }
    return $row;
}

/** 非自增的历史表在调用方事务内分配编号；自增表交由数据库分配。 */
function venuePricingNextManualId(mysqli $db): ?int
{
    $idColumn = $db->query("SHOW COLUMNS FROM PricingOptions WHERE Field = 'ID'");
    if (!$idColumn || !($idInfo = $idColumn->fetch_assoc())) {
        throw new RuntimeException('无法读取套餐 ID 字段：' . $db->error);
    }
    if (stripos($idInfo['Extra'], 'auto_increment') !== false) {
        return null;
    }
    // 非自增表使用同一条场地记录串行分配套餐 ID，避免不同场地并发读到同一个尾号。
    $allocator = $db->query('SELECT id FROM venues ORDER BY id LIMIT 1 FOR UPDATE');
    if (!$allocator || !$allocator->num_rows) {
        throw new RuntimeException('无法锁定套餐编号分配记录：' . $db->error);
    }
    $last = $db->query('SELECT ID FROM PricingOptions ORDER BY ID DESC LIMIT 1 FOR UPDATE');
    if (!$last) {
        throw new RuntimeException('无法读取套餐编号：' . $db->error);
    }
    $lastRow = $last->fetch_row();
    return $lastRow ? ((int)$lastRow[0] + 1) : 1;
}

/**
 * 调用方须在普通快照读之前锁定或新建目标场地，或者将本函数作为事务的首个读取操作。
 * 同场地的新增/生成共用场地行锁，读取套餐时避免锁定其他空场地所在的索引间隙。
 * 使用 RC 8 号场地四档快照，确保其他库没有 8 号场地时也能生成相同套餐。
 */
function venuePricingSeed(mysqli $db, int $venueId): int
{
    if ($venueId <= 0) {
        throw new InvalidArgumentException('无效的套餐场地 ID');
    }
    // 此处 ID 已限定为正整数。BindLocation 在部分旧库中是字符串。
    $venue = $db->query("SELECT id FROM venues WHERE id = $venueId FOR UPDATE");
    if (!$venue || !$venue->num_rows) {
        throw new RuntimeException('无法锁定套餐所属场地：' . $db->error);
    }
    $nextId = venuePricingNextManualId($db);
    $existing = $db->query("SELECT ID FROM PricingOptions WHERE BindLocation = '$venueId' LIMIT 1");
    if (!$existing) {
        throw new RuntimeException('套餐查询失败：' . $db->error);
    }
    if ($existing->num_rows) {
        return 0;
    }

    $packages = venuePricingTemplateFloors();
    $type = '按次计费';
    $columns = 'Minutes, Battery, Notes, Title, BindLocation, PricingType';
    $placeholder = '?, ?, ?, ?, ?, ?';
    $manualId = $nextId !== null;
    if ($manualId) {
        $columns .= ', ID';
        $placeholder .= ', ?';
    }
    $stmt = $db->prepare("INSERT INTO PricingOptions ($columns) VALUES ($placeholder)");
    if (!$stmt) {
        throw new RuntimeException('无法准备模板套餐写入：' . $db->error);
    }
    try {
        if ($manualId) {
            $bound = $stmt->bind_param('iissisi', $minutes, $battery, $notes, $title, $venueId, $type, $nextId);
        } else {
            $bound = $stmt->bind_param('iissis', $minutes, $battery, $notes, $title, $venueId, $type);
        }
        if (!$bound) {
            throw new RuntimeException('无法绑定模板套餐字段：' . $stmt->error);
        }
        foreach ($packages as $minutes => $battery) {
            $title = "{$minutes}分钟{$battery}电池(福利)";
            $notes = $minutes === 20 ? '无' : '';
            if (!$stmt->execute()) {
                throw new RuntimeException('无法写入模板套餐：' . $stmt->error);
            }
            if ($manualId) {
                ++$nextId;
            }
        }
    } finally {
        $stmt->close();
    }
    return count($packages);
}
