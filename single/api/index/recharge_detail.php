<?php
require_once '../Database.php';
$database = new Database();

$period = $_GET['period'] ?? 'day'; // day, week, month

switch ($period) {
    case 'week':
        $sql = "
            SELECT 
                YEARWEEK(created_at, 1) AS label,
                MIN(DATE(created_at)) AS start_date,
                MAX(DATE(created_at)) AS end_date,
                ROUND(SUM(payer_total), 2) AS total
            FROM RechargeOrders
            WHERE status = '支付成功'
            GROUP BY label
            ORDER BY label DESC
            LIMIT 12
        ";
        break;

    case 'month':
        $sql = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') AS label,
                ROUND(SUM(payer_total), 2) AS total
            FROM RechargeOrders
            WHERE status = '支付成功'
            GROUP BY label
            ORDER BY label DESC
            LIMIT 12
        ";
        break;

    case 'day':
    default:
        $sql = "
            SELECT 
                DATE(created_at) AS label,
                ROUND(SUM(payer_total), 2) AS total
            FROM RechargeOrders
            WHERE status = '支付成功'
            GROUP BY label
            ORDER BY label DESC
            LIMIT 30
        ";
        break;
}

$data = $database->query($sql);
$database->close();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>平台流水统计</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f9f9f9;
            margin: 0;
        }
        h2 {
            margin-bottom: 20px;
            font-size: 20px;
            word-break: break-word;
            text-align: center;
        }
        .toolbar {
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: center;
        }
        .toolbar button {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            background: #5b8cff;
            color: #fff;
            cursor: pointer;
            font-size: 14px;
        }
        .toolbar button.active {
            background: #2a66e0;
        }
        .card-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 15px 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            width: 160px;
            text-align: center;
        }
        .card-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        .card-value {
            font-size: 18px;
            color: #333;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h2>平台流水统计</h2>

    <div class="toolbar">
        <button onclick="changePeriod('day')" class="<?= $period == 'day' ? 'active' : '' ?>">按天</button>
        <button onclick="changePeriod('week')" class="<?= $period == 'week' ? 'active' : '' ?>">按周</button>
        <button onclick="changePeriod('month')" class="<?= $period == 'month' ? 'active' : '' ?>">按月</button>
    </div>

    <div class="card-container">
        <?php foreach ($data as $row):
            if ($period === 'week') {
                $label = date("m月d日", strtotime($row['start_date'])) . ' ~ ' . date("m月d日", strtotime($row['end_date']));
            } elseif ($period === 'month') {
                $label = date("Y年m月", strtotime($row['label'] . '-01'));
            } elseif ($period === 'day') {
                $label = date("m月d日", strtotime($row['label']));
            } else {
                $label = htmlspecialchars($row['label']);
            }
        ?>
        <div class="card">
            <div class="card-label"><?= $label ?></div>
            <div class="card-value"><?= $row['total'] ?> 元</div>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
        function changePeriod(period) {
            window.location.href = `recharge_detail.php?period=${period}`;
        }
    </script>
</body>
</html>
