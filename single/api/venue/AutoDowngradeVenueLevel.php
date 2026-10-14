<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

date_default_timezone_set('Asia/Shanghai');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const TARGET_LEVEL = 'C';
const ZERO_DAYS = 5;
const UPGRADE_TARGET_LEVEL = 'A';
const UPGRADE_INVITE_THRESHOLD = 15;
const LOCK_NAME = 'auto_downgrade_venue_level_zero_invites';

$execute = in_array('--execute', $argv, true);
$mode = $execute ? 'execute' : 'dry-run';
$runId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
$startDateOption = null;
$endDateOption = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--start-date=') === 0) {
        $startDateOption = substr($argument, strlen('--start-date='));
    } elseif (strpos($argument, '--end-date=') === 0) {
        $endDateOption = substr($argument, strlen('--end-date='));
    }
}

if (($startDateOption === null) !== ($endDateOption === null)) {
    fwrite(STDERR, "--start-date 和 --end-date 必须同时提供\n");
    exit(2);
}

if ($startDateOption !== null) {
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startDateOption);
    $inclusiveEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endDateOption);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$startDate || !$inclusiveEndDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
        fwrite(STDERR, "日期格式必须为 YYYY-MM-DD\n");
        exit(2);
    }
    $endDate = $inclusiveEndDate->modify('+1 day');
    if ($startDate >= $endDate) {
        fwrite(STDERR, "开始日期必须早于或等于结束日期\n");
        exit(2);
    }
} else {
    $endDate = new DateTimeImmutable('today');
    $startDate = $endDate->modify('-' . ZERO_DAYS . ' days');
}

$windowDays = (int)$startDate->diff($endDate)->days;
$logFile = dirname(__DIR__, 2) . '/log/venue_level_auto_downgrade.log';
$database = new Database();
$lockAcquired = false;

function writeLog(string $logFile, array $payload): void
{
    $directory = dirname($logFile);
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $payload['logged_at'] = date('Y-m-d H:i:s');
    file_put_contents(
        $logFile,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function printSummary(array $summary): void
{
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

try {
    $lockRows = $database->query('SELECT GET_LOCK(?, 0) AS acquired', [LOCK_NAME]);
    $lockAcquired = (int)($lockRows[0]['acquired'] ?? 0) === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('已有相同任务正在运行');
    }

    $database->query(
        "CREATE TABLE IF NOT EXISTS venue_level_auto_change_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id VARCHAR(40) NOT NULL COMMENT '任务运行编号',
            venue_id INT NOT NULL COMMENT '场地ID',
            invite_code VARCHAR(32) NOT NULL COMMENT '统计时使用的邀请码',
            old_level CHAR(1) NOT NULL COMMENT '变更前等级',
            new_level CHAR(1) NOT NULL COMMENT '变更后等级',
            change_source VARCHAR(20) NOT NULL DEFAULT 'auto' COMMENT 'auto自动/manual人工',
            operator_uid INT NULL COMMENT '人工操作管理员UID',
            operator_name VARCHAR(50) NULL COMMENT '人工操作管理员账号',
            stat_start_date DATE NOT NULL COMMENT '统计开始日期（含）',
            stat_end_date DATE NOT NULL COMMENT '统计结束日期（含）',
            invite_count INT NOT NULL DEFAULT 0 COMMENT '统计期邀请注册人数',
            reason VARCHAR(255) NOT NULL COMMENT '变更原因',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_venue_created (venue_id, created_at),
            KEY idx_run_id (run_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='场地等级自动变更审计记录'",
        [],
        true
    );

    $database->query(
        "CREATE TABLE IF NOT EXISTS venue_level_auto_exemptions (
            venue_id INT NOT NULL COMMENT '豁免场地ID',
            reason VARCHAR(255) NOT NULL COMMENT '豁免原因',
            is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1生效，0停用',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (venue_id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='场地等级自动降级豁免名单'",
        [],
        true
    );

    $sql = "
        SELECT
            v.id AS venue_id,
            v.venue_name,
            v.invite_code,
            UPPER(TRIM(v.venue_level)) AS old_level,
            COALESCE(invites.invite_count, 0) AS invite_count
        FROM venues v
        LEFT JOIN (
            SELECT invitation_code, COUNT(*) AS invite_count
            FROM users
            WHERE created_at >= ?
              AND created_at < ?
              AND invitation_code IS NOT NULL
              AND invitation_code NOT IN ('', '0000')
            GROUP BY invitation_code
        ) invites ON invites.invitation_code = v.invite_code
        LEFT JOIN venue_level_auto_exemptions exemptions
               ON exemptions.venue_id = v.id
              AND exemptions.is_active = 1
        WHERE v.venue_status = '营业中'
          AND v.is_banned = 0
          AND v.invite_code IS NOT NULL
          AND v.invite_code NOT IN ('', '0000')
          AND UPPER(TRIM(v.venue_level)) IN ('S', 'A', 'B')
          AND COALESCE(invites.invite_count, 0) = 0
          AND exemptions.venue_id IS NULL
        ORDER BY v.id
    ";

    $candidates = $database->query($sql, [
        $startDate->format('Y-m-d 00:00:00'),
        $endDate->format('Y-m-d 00:00:00'),
    ]);

    if ($candidates === false) {
        throw new RuntimeException('查询待降级场地失败');
    }

    $upgradeSql = "
        SELECT
            v.id AS venue_id,
            v.venue_name,
            v.invite_code,
            UPPER(TRIM(v.venue_level)) AS old_level,
            COALESCE(invites.invite_count, 0) AS invite_count
        FROM venues v
        LEFT JOIN (
            SELECT invitation_code, COUNT(*) AS invite_count
            FROM users
            WHERE created_at >= ?
              AND created_at < ?
              AND invitation_code IS NOT NULL
              AND invitation_code NOT IN ('', '0000')
            GROUP BY invitation_code
        ) invites ON invites.invitation_code = v.invite_code
        WHERE v.venue_status = '营业中'
          AND v.is_banned = 0
          AND v.invite_code IS NOT NULL
          AND v.invite_code NOT IN ('', '0000')
          AND UPPER(TRIM(v.venue_level)) = 'C'
          AND COALESCE(invites.invite_count, 0) > ?
        ORDER BY v.id
    ";

    $upgradeCandidates = $database->query($upgradeSql, [
        $startDate->format('Y-m-d 00:00:00'),
        $endDate->format('Y-m-d 00:00:00'),
        UPGRADE_INVITE_THRESHOLD,
    ]);

    if ($upgradeCandidates === false) {
        throw new RuntimeException('查询待升级场地失败');
    }

    $changed = [];
    if ($execute && ($candidates || $upgradeCandidates)) {
        $database->beginTransaction();
        try {
            foreach ($candidates as $candidate) {
                $affected = $database->query(
                    "UPDATE venues
                     SET venue_level = ?
                     WHERE id = ?
                       AND venue_status = '营业中'
                       AND is_banned = 0
                       AND UPPER(TRIM(venue_level)) = ?
                     LIMIT 1",
                    [TARGET_LEVEL, $candidate['venue_id'], $candidate['old_level']],
                    true
                );

                if ($affected !== 1) {
                    continue;
                }

                $reason = '统计期连续' . $windowDays . '个自然日引流注册人数为0，自动降为C级';
                $inserted = $database->query(
                    "INSERT INTO venue_level_auto_change_logs
                        (run_id, venue_id, invite_code, old_level, new_level, change_source,
                         stat_start_date, stat_end_date, invite_count, reason)
                     VALUES (?, ?, ?, ?, ?, 'auto', ?, ?, 0, ?)",
                    [
                        $runId,
                        $candidate['venue_id'],
                        $candidate['invite_code'],
                        $candidate['old_level'],
                        TARGET_LEVEL,
                        $startDate->format('Y-m-d'),
                        $endDate->modify('-1 day')->format('Y-m-d'),
                        $reason,
                    ],
                    true
                );

                if ($inserted !== 1) {
                    throw new RuntimeException('写入场地 ' . $candidate['venue_id'] . ' 的审计记录失败');
                }

                $candidate['action'] = 'downgrade';
                $candidate['new_level'] = TARGET_LEVEL;
                $changed[] = $candidate;
            }

            foreach ($upgradeCandidates as $candidate) {
                $affected = $database->query(
                    "UPDATE venues
                     SET venue_level = ?
                     WHERE id = ?
                       AND venue_status = '营业中'
                       AND is_banned = 0
                       AND UPPER(TRIM(venue_level)) = 'C'
                     LIMIT 1",
                    [UPGRADE_TARGET_LEVEL, $candidate['venue_id']],
                    true
                );

                if ($affected !== 1) {
                    continue;
                }

                $reason = '统计期连续' . $windowDays . '个自然日引流注册人数累计大于'
                    . UPGRADE_INVITE_THRESHOLD . '，自动升为A级';
                $inserted = $database->query(
                    "INSERT INTO venue_level_auto_change_logs
                        (run_id, venue_id, invite_code, old_level, new_level, change_source,
                         stat_start_date, stat_end_date, invite_count, reason)
                     VALUES (?, ?, ?, ?, ?, 'auto', ?, ?, ?, ?)",
                    [
                        $runId,
                        $candidate['venue_id'],
                        $candidate['invite_code'],
                        $candidate['old_level'],
                        UPGRADE_TARGET_LEVEL,
                        $startDate->format('Y-m-d'),
                        $endDate->modify('-1 day')->format('Y-m-d'),
                        $candidate['invite_count'],
                        $reason,
                    ],
                    true
                );

                if ($inserted !== 1) {
                    throw new RuntimeException('写入场地 ' . $candidate['venue_id'] . ' 的升级审计记录失败');
                }

                $candidate['action'] = 'upgrade';
                $candidate['new_level'] = UPGRADE_TARGET_LEVEL;
                $changed[] = $candidate;
            }
            $database->commit();
        } catch (Throwable $transactionError) {
            $database->rollBack();
            throw $transactionError;
        }
    }

    $summary = [
        'ok' => true,
        'mode' => $mode,
        'run_id' => $runId,
        'window' => [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->modify('-1 day')->format('Y-m-d'),
        ],
        'candidate_count' => count($candidates) + count($upgradeCandidates),
        'downgrade_candidate_count' => count($candidates),
        'upgrade_candidate_count' => count($upgradeCandidates),
        'changed_count' => count($changed),
        'downgrade_candidates' => array_map(static function (array $row): array {
            return [
                'venue_id' => (int)$row['venue_id'],
                'venue_name' => $row['venue_name'],
                'invite_code' => $row['invite_code'],
                'old_level' => $row['old_level'],
                'invite_count' => (int)$row['invite_count'],
            ];
        }, $candidates),
        'upgrade_candidates' => array_map(static function (array $row): array {
            return [
                'venue_id' => (int)$row['venue_id'],
                'venue_name' => $row['venue_name'],
                'invite_code' => $row['invite_code'],
                'old_level' => $row['old_level'],
                'invite_count' => (int)$row['invite_count'],
            ];
        }, $upgradeCandidates),
    ];

    writeLog($logFile, $summary);
    printSummary($summary);
} catch (Throwable $e) {
    $error = [
        'ok' => false,
        'mode' => $mode,
        'run_id' => $runId,
        'error' => $e->getMessage(),
    ];
    writeLog($logFile, $error);
    printSummary($error);
    exit(1);
} finally {
    if ($lockAcquired) {
        $database->query('SELECT RELEASE_LOCK(?)', [LOCK_NAME]);
    }
    $database->close();
}
