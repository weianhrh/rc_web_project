<?php
/**
 * /single/api/pop/cleanup_review_backup.php
 *
 * 清理 review_backup/YYYY-MM-DD 日期目录：
 * - 保留最近 7 天（包含今天）
 * - 删除“今天往前第 7 天”及更早的日期目录
 * - 只处理严格的 YYYY-MM-DD 目录，其他文件/目录不会动
 *
 * 宝塔计划任务命令：
 *   php /www/wwwroot/open.rcwulian.cn/single/api/pop/cleanup_review_backup.php
 *
 * 手工测试（只预览，不删除）：
 *   php /www/wwwroot/open.rcwulian.cn/single/api/pop/cleanup_review_backup.php --dry-run
 */

date_default_timezone_set('Asia/Shanghai');

$backupBase = __DIR__ . '/review_backup';
$keepDays   = 7;
$dryRun     = in_array('--dry-run', $argv ?? [], true);

$logDir = __DIR__ . '/log';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/cleanup_review_backup.log';

function cleanup_log($message, $logFile)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function valid_date_dir($name)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
        return false;
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $name);
    return $dt !== false && $dt->format('Y-m-d') === $name;
}

function rrmdir_safe($dir)
{
    if (is_link($dir)) {
        return false;
    }
    if (!is_dir($dir)) {
        return true;
    }

    $items = scandir($dir);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                return false;
            }
            continue;
        }

        if (is_dir($path) && !rrmdir_safe($path)) {
            return false;
        }
    }

    return @rmdir($dir);
}

if (!is_dir($backupBase)) {
    $msg = "review_backup 目录不存在：{$backupBase}";
    cleanup_log($msg, $logFile);
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
}

// 示例：2026-09-07 执行时，cutoff=2026-08-31；删除 08-31 及更早，保留 09-01~09-07。
$cutoff = date('Y-m-d', strtotime("-{$keepDays} days"));

$dirs = scandir($backupBase);
if ($dirs === false) {
    $msg = "无法读取目录：{$backupBase}";
    cleanup_log($msg, $logFile);
    fwrite(STDERR, $msg . PHP_EOL);
    exit(1);
}

$deleted = 0;
$skipped = 0;
$failed  = 0;
$preview = 0;

foreach ($dirs as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }

    $fullPath = $backupBase . DIRECTORY_SEPARATOR . $name;

    // 只处理真实目录；符号链接、普通文件、非日期目录全部跳过。
    if (!is_dir($fullPath) || is_link($fullPath) || !valid_date_dir($name)) {
        $skipped++;
        continue;
    }

    if ($name > $cutoff) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        $preview++;
        cleanup_log("[DRY-RUN] 将删除：{$fullPath}", $logFile);
        echo "[DRY-RUN] 将删除：{$fullPath}" . PHP_EOL;
        continue;
    }

    if (rrmdir_safe($fullPath)) {
        $deleted++;
        cleanup_log("已删除：{$fullPath}", $logFile);
    } else {
        $failed++;
        cleanup_log("删除失败：{$fullPath}", $logFile);
    }
}

$mode = $dryRun ? 'dry-run' : 'delete';
$msg = "完成：mode={$mode} cutoff={$cutoff} deleted={$deleted} preview={$preview} skipped={$skipped} failed={$failed}";
cleanup_log($msg, $logFile);
echo $msg . PHP_EOL;

exit($failed > 0 ? 2 : 0);
