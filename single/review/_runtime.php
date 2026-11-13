<?php
declare(strict_types=1);
// 接口必须返回 JSON/图片，不能让 PHP 的 HTML 错误提示污染响应。
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$reviewRuntimeBufferLevel = ob_get_level();
ob_start();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity) || $severity === E_USER_ERROR) return false;
    error_log('[review-runtime] ' . $message . ' at ' . $file . ':' . $line);
    return true;
});

function review_runtime_failure(string $detail, int $bufferLevel): void
{
    error_log('[review-runtime] ' . $detail);
    while (ob_get_level() > $bufferLevel) ob_end_clean();
    if (!headers_sent()) {
        header_remove('Content-Length');
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
    }
    echo json_encode(['code'=>500, 'success'=>false, 'msg'=>'审核服务执行失败，请查看服务器 PHP 错误日志', 'message'=>'审核服务执行失败，请查看服务器 PHP 错误日志'], JSON_UNESCAPED_UNICODE);
}

set_exception_handler(static function (Throwable $exception) use ($reviewRuntimeBufferLevel): void {
    review_runtime_failure($exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine(), $reviewRuntimeBufferLevel);
});
register_shutdown_function(static function () use ($reviewRuntimeBufferLevel): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        review_runtime_failure($error['message'] . ' at ' . $error['file'] . ':' . $error['line'], $reviewRuntimeBufferLevel);
    }
});
