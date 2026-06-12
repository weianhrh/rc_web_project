# ==============================
# Windows 主动检测宝塔服务器 PHP 变动并下载
# 服务器不生成备份
# 只下载 .php
# 本地目录结构和宝塔目录结构对应
# 带下载记录，避免重复下载
# ==============================

$serverUser = "root"
$serverHost = "47.93.53.97"
$serverPort = 22

# 宝塔服务器目录
$remoteBase = "/www/wwwroot/rcwulian.cn/app"

# Windows 本地对应目录
$localBase = "E:\bt_sync\www\wwwroot\rcwulian.cn\app"

# 本地记录目录
$stateDir = "E:\bt_sync\state"
$recordFile = "$stateDir\rcwulian_php_downloaded.record"

New-Item -ItemType Directory -Force -Path $localBase | Out-Null
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null

if (!(Test-Path $recordFile)) {
    New-Item -ItemType File -Force -Path $recordFile | Out-Null
}

$server = "$serverUser@$serverHost"

Write-Host "开始检测宝塔服务器 PHP 变动..."
Write-Host "服务器目录：$remoteBase"
Write-Host "本地目录：$localBase"
Write-Host "记录文件：$recordFile"
Write-Host ""

# 只检测最近 24 小时修改过的 .php
# 输出格式：修改时间戳|文件大小|文件完整路径
# 这样方便判断是否已经下载过
$findCmd = "find '$remoteBase' -type f -name '*.php' -mtime -1 -printf '%T@|%s|%p\n' | sort"

$lines = @(ssh -p $serverPort $server $findCmd)

if ($lines.Count -eq 0 -or [string]::IsNullOrWhiteSpace(($lines -join ""))) {
    Write-Host "最近 24 小时没有修改过的 PHP 文件"
    exit 0
}

$downloadedRecords = @{}
Get-Content $recordFile | ForEach-Object {
    if (![string]::IsNullOrWhiteSpace($_)) {
        $downloadedRecords[$_] = $true
    }
}

$count = 0
$skipCount = 0
$failCount = 0

foreach ($line in $lines) {
    $line = $line.Trim()

    if ([string]::IsNullOrWhiteSpace($line)) {
        continue
    }

    # 解析格式：
    # 1778496000.1234567890|1234|/www/wwwroot/rcwulian.cn/app/api/test.php
    $parts = $line -split '\|', 3

    if ($parts.Count -lt 3) {
        Write-Host "无法解析，跳过：$line"
        $skipCount++
        continue
    }

    $mtime = $parts[0]
    $size = $parts[1]
    $remoteFile = $parts[2].Trim()

    # 第二层保险：不是 .php 就不下载
    if ([System.IO.Path]::GetExtension($remoteFile).ToLower() -ne ".php") {
        Write-Host "不是 PHP，跳过：$remoteFile"
        $skipCount++
        continue
    }

    # 第三层保险：必须在指定的宝塔目录下面
    if (!$remoteFile.StartsWith($remoteBase + "/")) {
        Write-Host "不在指定目录下，跳过：$remoteFile"
        $skipCount++
        continue
    }

    # 下载记录 key：完整路径|修改时间|大小
    $recordKey = "$remoteFile|$mtime|$size"

    if ($downloadedRecords.ContainsKey($recordKey)) {
        Write-Host "已下载过，跳过：$remoteFile"
        $skipCount++
        continue
    }

    # 转换成相对路径
    # /www/wwwroot/rcwulian.cn/app/api/user/login.php
    # => api/user/login.php
    $relPath = $remoteFile.Substring($remoteBase.Length).TrimStart('/')

    # Linux 路径转 Windows 路径
    $winRelPath = $relPath -replace '/', '\'

    # 拼接本地文件路径
    $localFile = Join-Path $localBase $winRelPath
    $localDir = Split-Path $localFile -Parent

    New-Item -ItemType Directory -Force -Path $localDir | Out-Null

    Write-Host "下载 PHP：$remoteFile"
    Write-Host "保存到：$localFile"

    scp -P $serverPort "${server}:$remoteFile" "$localFile"

    if ($LASTEXITCODE -eq 0) {
        Add-Content -Path $recordFile -Value $recordKey
        $downloadedRecords[$recordKey] = $true

        $count++
        Write-Host "成功：$relPath"
    } else {
        $failCount++
        Write-Host "失败：$relPath"
    }

    Write-Host ""
}

Write-Host "本次检测完成"
Write-Host "成功下载 PHP 数量：$count"
Write-Host "跳过数量：$skipCount"
Write-Host "失败数量：$failCount"