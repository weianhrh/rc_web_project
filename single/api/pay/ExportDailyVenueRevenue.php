<?php
/**
 * 每日场地收入一键导出：一次下载 ZIP，每天一个 CSV。
 * 放在现有 GetDailyVenueRevenue.php 同目录，复用上一级 Database.php。
 * GET start_date / end_date（均包含），download=1 直接下载。
 * 仅登录的 role_id=1/2 可用；只读，不修改任何账单或结算状态。
 * PHP 7.2+；不依赖 ZipArchive、Composer 或额外文件。
 */

date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
ob_start();

function dvr_export_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dvr_export_error($message, $status) {
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>场地收入导出</title>';
    echo '<main style="max-width:620px;margin:64px auto;padding:24px;font:16px/1.8 sans-serif">';
    echo '<h2>暂时无法导出</h2><p>' . dvr_export_escape($message) . '</p>';
    if ($status === 401) {
        echo '<p><a href="/#/user/login" target="_blank" rel="noopener">打开后台登录页</a></p>';
        echo '<p>请在同一个浏览器登录后台，再返回这里刷新。</p>';
    }
    echo '<p><a href="./ExportDailyVenueRevenue.php">返回日期选择</a></p></main></html>';
    exit;
}

function dvr_export_date($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : false;
}

function dvr_export_csv_line($cells) {
    $quoted = [];
    foreach ($cells as $cell) {
        $quoted[] = '"' . str_replace('"', '""', (string)$cell) . '"';
    }
    return implode(',', $quoted) . "\r\n";
}

function dvr_export_safe_name($value) {
    $text = (string)$value;
    // 场地名按文本导出；收入列不经过此函数，仍保留可计算的数字。
    if (preg_match('/^[\x00-\x20\x7f\x{00a0}\x{feff}\x{200b}]*[=+\-@]/u', $text)
        || preg_match('/^[\x00-\x1f\x7f]/', $text)) {
        return "'" . $text;
    }
    return $text;
}

function dvr_export_zip($files) {
    // 标准 ZIP STORE 格式，无需服务器开启 zip 扩展。
    // https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT
    // https://www.php.net/manual/en/function.pack.php
    $body = '';
    $central = '';
    $now = getdate();
    $year = max(1980, min(2107, $now['year']));
    $dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | ($now['seconds'] >> 1);
    $dosDate = (($year - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

    foreach ($files as $name => $content) {
        $nameLength = strlen($name);
        $size = strlen($content);
        $offset = strlen($body);
        $crc = crc32($content);
        if ($nameLength > 65535 || $offset + $size > 67108864) {
            throw new RuntimeException('ZIP content too large');
        }
        $body .= pack(
            'VvvvvvVVVvv',
            0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate,
            $crc, $size, $size, $nameLength, 0
        ) . $name . $content;
        $central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate,
            $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset
        ) . $name;
    }
    $count = count($files);
    return $body . $central . pack(
        'VvvvvVVv',
        0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0
    );
}

function dvr_export_form($startDate, $endDate) {
    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $start = dvr_export_escape($startDate);
    $end = dvr_export_escape($endDate);
    echo <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>每日场地收入导出</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f9;color:#192435;font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}
main{max-width:620px;margin:64px auto;padding:36px;background:#fff;border:1px solid #e4e9ef;border-radius:16px}
h1{margin:0 0 10px;font-size:27px;line-height:1.4}
.intro{margin:0 0 28px;color:#536173}
.dates{display:grid;grid-template-columns:1fr 1fr;gap:18px}
label{display:block;font-weight:600}
input[type=date]{display:block;width:100%;margin-top:8px;padding:12px;border:1px solid #b9c5d3;border-radius:8px;background:#fff;color:#192435;font:inherit}
button{width:100%;margin-top:24px;padding:14px;border:0;border-radius:8px;background:#1768c4;color:white;font-family:inherit;font-size:17px;font-weight:600;line-height:1.5;cursor:pointer}
button:hover{background:#11579f}
button:focus-visible,input:focus-visible{outline:3px solid #a2cafa;outline-offset:3px}
.note{margin:22px 0 0;padding-top:18px;border-top:1px solid #e4e9ef;color:#536173;font-size:14px}
@media(max-width:640px){main{margin:20px 12px;padding:24px}.dates{grid-template-columns:1fr}h1{font-size:24px}}
</style>
</head>
<body>
<main>
<h1>每日场地收入导出</h1>
<p class="intro">选择日期，一次下载 ZIP。每天一份 CSV，可用 Excel 或 WPS 打开。</p>
<form method="get">
<input type="hidden" name="download" value="1">
<div class="dates">
<label>开始日期<input type="date" name="start_date" value="$start" required></label>
<label>结束日期<input type="date" name="end_date" value="$end" required></label>
</div>
<button type="submit">按天打包下载</button>
</form>
<p class="note">包含开始和结束日期。导出全部场地的已有账单，含 0 收入。<br>每份表格包含：日期、venue_id、venue_name、收入（元）。</p>
</main>
</body>
</html>
HTML;
}

$database = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        dvr_export_error('请通过页面选择日期后下载。', 405);
    }

    $token = $_COOKIE['session_token'] ?? '';
    if (!is_string($token) || $token === '') {
        dvr_export_error('请先使用管理员或运营账号登录后台。', 401);
    }

    // 让连接错误进入统一处理，避免构造函数直接输出数据库错误。
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../Database.php';
    $database = new Database();
    if (!$database->getConnection()->set_charset('utf8mb4')) {
        throw new RuntimeException('Unable to set database charset');
    }

    $user = $database->getUserBySessionToken($token);
    if (!$user || empty($user['role_id'])) {
        $database->close();
        $database = null;
        dvr_export_error('登录已过期，请重新登录后台。', 401);
    }
    if (!in_array((int)$user['role_id'], [1, 2], true)) {
        $database->close();
        $database = null;
        dvr_export_error('仅管理员或运营账号可以导出全部场地收入。', 403);
    }

    $defaultEnd = new DateTimeImmutable('yesterday');
    $defaultStart = $defaultEnd->modify('-13 days');
    $startValue = $_GET['start_date'] ?? $defaultStart->format('Y-m-d');
    $endValue = $_GET['end_date'] ?? $defaultEnd->format('Y-m-d');
    $start = dvr_export_date($startValue);
    $end = dvr_export_date($endValue);
    $downloadValue = $_GET['download'] ?? '0';
    if (!is_string($downloadValue) || !in_array($downloadValue, ['0', '1'], true)) {
        throw new InvalidArgumentException('下载参数不正确，请重新选择日期。');
    }
    if (!$start || !$end) {
        throw new InvalidArgumentException('日期格式不正确，请使用 YYYY-MM-DD。');
    }
    if ($start > $end) {
        throw new InvalidArgumentException('开始日期不能晚于结束日期。');
    }
    $dayCount = (int)$start->diff($end)->format('%a') + 1;
    if ($dayCount > 366) {
        throw new InvalidArgumentException('一次最多导出 366 天，请缩小日期范围。');
    }

    if ($downloadValue !== '1') {
        $database->close();
        $database = null;
        dvr_export_form($start->format('Y-m-d'), $end->format('Y-m-d'));
        exit;
    }

    // 一次查询整个区间，右边界为结束日的次日，不设置分页或条数限制。
    $rows = $database->query(
        "SELECT DATE_FORMAT(d.date, '%Y-%m-%d') AS stat_date,
                d.venue_id,
                COALESCE(NULLIF(v.venue_name, ''), '名称缺失') AS venue_name,
                CAST(d.total_revenue AS DECIMAL(18, 2)) AS income
         FROM DailyVenueRevenue AS d
         LEFT JOIN venues AS v ON v.id = d.venue_id
         WHERE d.date >= ? AND d.date < ?
         ORDER BY d.date ASC, d.venue_id ASC, d.id ASC",
        [$start->format('Y-m-d'), $end->modify('+1 day')->format('Y-m-d')]
    );
    if (!is_array($rows)) {
        throw new RuntimeException('Daily revenue query failed');
    }
    $database->close();
    $database = null;
    if (!$rows) {
        dvr_export_error('所选日期没有账单记录，请检查日期或确认每日账单已生成。', 404);
    }

    $files = [];
    $stats = [];
    $seen = [];
    $bom = "\xEF\xBB\xBF";
    $csvHeader = $bom . dvr_export_csv_line(['日期', 'venue_id', 'venue_name', '收入']);
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $files[$key . '.csv'] = $csvHeader;
        $stats[$key] = ['rows' => 0, 'unique' => 0, 'duplicates' => 0, 'empty_income' => 0];
        $seen[$key] = [];
    }

    foreach ($rows as $row) {
        $key = (string)$row['stat_date'];
        $venueId = (string)$row['venue_id'];
        if (!isset($stats[$key]) || !preg_match('/^\d+$/D', $venueId)) {
            throw new RuntimeException('Unexpected date or venue id in query result');
        }
        $income = $row['income'] === null ? '' : (string)$row['income'];
        if ($income !== '' && !preg_match('/^-?\d+\.\d{2}$/D', $income)) {
            throw new RuntimeException('Unexpected revenue value in query result');
        }
        $files[$key . '.csv'] .= dvr_export_csv_line([
            $key,
            $venueId,
            dvr_export_safe_name($row['venue_name']),
            $income
        ]);
        $stats[$key]['rows']++;
        if (isset($seen[$key][$venueId])) {
            $stats[$key]['duplicates']++;
        } else {
            $seen[$key][$venueId] = true;
            $stats[$key]['unique']++;
        }
        if ($income === '') {
            $stats[$key]['empty_income']++;
        }
    }
    unset($rows, $seen);

    $notes = "每日场地收入导出\r\n";
    $notes .= '账期：' . $start->format('Y-m-d') . ' 至 ' . $end->format('Y-m-d') . "（含首尾两天）\r\n";
    $notes .= '导出时间：' . date('Y-m-d H:i:s') . "（北京时间）\r\n";
    $notes .= "收入：后台财务对账 DailyVenueRevenue.total_revenue，单位元。\r\n";
    $notes .= "保留已有0收入以及全部核对、结算状态；场地名称取当前名称。\r\n";
    $notes .= "记录缺失不等同于收入为0。重复账单保留原行，空收入保留空白。\r\n";
    $notes .= "场地名以公式符号或控制字符开头时添加文本前缀，防止表格软件将名称当作公式。\r\n";
    $notes .= "\r\n日期\t账单行数\t场地数\t重复行数\t收入空值数\t说明\r\n";
    foreach ($stats as $key => $stat) {
        $remarks = [];
        if ($stat['rows'] === 0) {
            $remarks[] = '无账单记录，本日CSV仅有表头';
        }
        if ($stat['duplicates'] > 0) {
            $remarks[] = '同日同场地存在重复账单，请核对';
        }
        if ($stat['empty_income'] > 0) {
            $remarks[] = '存在空收入，请核对';
        }
        $notes .= implode("\t", [
            $key, $stat['rows'], $stat['unique'], $stat['duplicates'],
            $stat['empty_income'], implode('；', $remarks)
        ]) . "\r\n";
    }
    $files['导出说明.txt'] = $bom . $notes;
    $zip = dvr_export_zip($files);
    unset($files);

    $filename = '场地每日收入_' . $start->format('Ymd') . '_' . $end->format('Ymd') . '.zip';
    $fallback = 'venue_income_' . $start->format('Ymd') . '_' . $end->format('Ymd') . '.zip';
    ob_clean();
    header('Content-Type: application/zip');
    header("Content-Disposition: attachment; filename=\"" . $fallback . "\"; filename*=UTF-8''" . rawurlencode($filename));
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $zip;
    exit;
} catch (Throwable $e) {
    if ($database !== null) {
        try {
            $database->close();
        } catch (Throwable $ignored) {
            // 保留最初的错误信息。
        }
    }
    if ($e instanceof InvalidArgumentException) {
        dvr_export_error($e->getMessage(), 400);
    }
    error_log('ExportDailyVenueRevenue: ' . $e->getMessage());
    dvr_export_error('导出失败，请检查服务器 PHP 错误日志后重试。', 500);
}
