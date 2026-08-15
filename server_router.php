<?php
/**
 * PHP 内置服务器路由脚本
 * 模拟 .htaccess 的 RewriteRule ^(.*)$ index.php?p=$1
 * 使用方式: php -S 127.0.0.1:8080 -t . server_router.php
 */

// 已存在的静态文件（含入口 index.php / admin.php 自身）交给内置服务器直接输出
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requested = __DIR__ . $uri;

// 如果请求的是真实存在的文件或目录（但排除目录本身），直接返回 false 让内置服务器处理
if ($uri !== '/' && $uri !== '/index.php' && file_exists($requested)) {
    if (!is_dir($requested)) {
        return false; // 让内置服务器输出静态资源
    }
}

// 否则交给 PbootCMS 入口处理，模拟 ?p=$1
$path = ltrim($uri, '/');

// 仅当有实际路径时才注入 p 参数；首页（/）保持不传 p，走默认 home/Index
if ($path !== '') {
    $_GET['p'] = $path;
    $existing = $_SERVER['QUERY_STRING'] ?? '';
    $_SERVER['QUERY_STRING'] = ($existing !== '') ? 'p=' . $path . '&' . $existing : 'p=' . $path;
}

require __DIR__ . '/index.php';
