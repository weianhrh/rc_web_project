<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

$dir = __DIR__ . "/audio";
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

// 参数
$action = $_GET["action"] ?? "append";
$name = $_GET["name"] ?? "esp32_record.wav";
$name = basename($name);

$path = $dir . "/" . $name;

// WAV 参数：必须和 ESP32 一致
$sampleRate = 8000;
$bitsPerSample = 16;
$channels = 1;

function writeWavHeader($fp, $dataSize, $sampleRate, $bitsPerSample, $channels) {
    $byteRate = $sampleRate * $channels * $bitsPerSample / 8;
    $blockAlign = $channels * $bitsPerSample / 8;
    $chunkSize = 36 + $dataSize;

    fseek($fp, 0);

    fwrite($fp, "RIFF");
    fwrite($fp, pack("V", $chunkSize));
    fwrite($fp, "WAVE");

    fwrite($fp, "fmt ");
    fwrite($fp, pack("V", 16));              // Subchunk1Size
    fwrite($fp, pack("v", 1));               // PCM
    fwrite($fp, pack("v", $channels));
    fwrite($fp, pack("V", $sampleRate));
    fwrite($fp, pack("V", $byteRate));
    fwrite($fp, pack("v", $blockAlign));
    fwrite($fp, pack("v", $bitsPerSample));

    fwrite($fp, "data");
    fwrite($fp, pack("V", $dataSize));
}

// 开始新录音
if ($action === "start") {
    $fp = fopen($path, "wb");
    if (!$fp) {
        echo json_encode(["ok" => false, "msg" => "open failed"]);
        exit;
    }

    writeWavHeader($fp, 0, $sampleRate, $bitsPerSample, $channels);
    fclose($fp);

    echo json_encode(["ok" => true, "msg" => "wav started", "file" => $name]);
    exit;
}

// 追加音频数据
if ($action === "append") {
    $data = file_get_contents("php://input");

    if ($data === false || strlen($data) === 0) {
        echo json_encode(["ok" => false, "msg" => "no data"]);
        exit;
    }

    // 文件不存在时自动创建 WAV 头
    if (!file_exists($path)) {
        $fp = fopen($path, "wb");
        writeWavHeader($fp, 0, $sampleRate, $bitsPerSample, $channels);
        fclose($fp);
    }

    $fp = fopen($path, "c+b");
    if (!$fp) {
        echo json_encode(["ok" => false, "msg" => "open failed"]);
        exit;
    }

    flock($fp, LOCK_EX);

    $fileSize = filesize($path);
    if ($fileSize < 44) {
        ftruncate($fp, 0);
        writeWavHeader($fp, 0, $sampleRate, $bitsPerSample, $channels);
        $fileSize = 44;
    }

    $oldDataSize = $fileSize - 44;

    fseek($fp, 0, SEEK_END);
    fwrite($fp, $data);

    $newDataSize = $oldDataSize + strlen($data);

    // 每次追加后更新 WAV 头，保证文件随时接近可播放
    writeWavHeader($fp, $newDataSize, $sampleRate, $bitsPerSample, $channels);

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode([
        "ok" => true,
        "msg" => "append ok",
        "file" => $name,
        "chunk_bytes" => strlen($data),
        "total_audio_bytes" => $newDataSize
    ]);
    exit;
}

// 结束录音
if ($action === "finish") {
    if (!file_exists($path)) {
        echo json_encode(["ok" => false, "msg" => "file not found"]);
        exit;
    }

    $fp = fopen($path, "c+b");
    flock($fp, LOCK_EX);

    $dataSize = max(0, filesize($path) - 44);
    writeWavHeader($fp, $dataSize, $sampleRate, $bitsPerSample, $channels);

    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode([
        "ok" => true,
        "msg" => "finish ok",
        "file" => $name,
        "url" => "audio/" . $name,
        "audio_bytes" => $dataSize
    ]);
    exit;
}

echo json_encode(["ok" => false, "msg" => "unknown action"]);