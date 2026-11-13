<?php
declare(strict_types=1);

function platform_image_mime(string $bytes): string
{
    // 图片内容检测不依赖可选的 fileinfo/finfo，也不需要 GD。
    if (!function_exists('getimagesizefromstring')) return '';
    $info = @getimagesizefromstring($bytes);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    return in_array($mime, ['image/jpeg','image/png','image/gif','image/webp','image/bmp','image/x-ms-bmp'], true) ? $mime : '';
}
