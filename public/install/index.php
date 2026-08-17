<?php
declare(strict_types=1);

use app\service\Installer;

$root = dirname(__DIR__, 2);
require_once $root . '/app/service/Installer.php';

$runtime = $root . '/runtime';
if (!is_dir($runtime)) {
    @mkdir($runtime, 0775, true);
}
$noncePath = $runtime . '/installer-nonce';
$status = Installer::status($root);
$isJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$message = '';
$error = '';
$requestInput = $_POST;
if (($requestInput === [] || $requestInput === null) && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $requestInput = $decoded;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedNonce = (string)($requestInput['nonce'] ?? '');
    $storedNonce = is_file($noncePath) ? trim((string)file_get_contents($noncePath)) : '';
    $nonceAge = is_file($noncePath) ? (time() - (int)filemtime($noncePath)) : PHP_INT_MAX;
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $originHost = $origin !== '' ? (string)(parse_url($origin, PHP_URL_HOST) ?? '') : '';
    $requestHost = $host !== '' ? (string)(parse_url('http://' . $host, PHP_URL_HOST) ?? '') : '';
    try {
        if (!hash_equals($storedNonce, $postedNonce) || $storedNonce === '' || $nonceAge > 900) {
            throw new RuntimeException('Installer session expired. Refresh the page and try again.');
        }
        if ($originHost !== '' && $requestHost !== '' && !hash_equals($requestHost, $originHost)) {
            throw new RuntimeException('Invalid installer origin.');
        }
        @unlink($noncePath);
        Installer::install($requestInput, $root);
        $message = '安装完成，请返回首页登录。';
        $status = Installer::status($root);
    } catch (Throwable $exception) {
        $requestId = bin2hex(random_bytes(6));
        $logDirectory = $runtime . '/log';
        if (!is_dir($logDirectory)) {
            @mkdir($logDirectory, 0775, true);
        }
        @file_put_contents(
            $logDirectory . '/install.log',
            sprintf("[%s] %s %s\n", gmdate('c'), $requestId, $exception->getMessage()),
            FILE_APPEND | LOCK_EX
        );
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : $exception->getMessage() . ' Request ID: ' . $requestId;
    }
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $error === '', 'message' => $error !== '' ? $error : $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$nonce = bin2hex(random_bytes(24));
file_put_contents($noncePath, $nonce, LOCK_EX);
@chmod($noncePath, 0600);

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$missingText = $status['missing'] === [] ? '环境检查通过' : '待处理：' . implode('、', $status['missing']);
$defaults = array_merge([
    'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'vmq',
    'db_user' => 'root', 'admin_username' => '', 'timezone' => 'Asia/Shanghai',
], Installer::configurationDefaults($root));
$hasStoredAdminPassword = isset($defaults['admin_password']) && $defaults['admin_password'] !== '';
$databaseImported = (bool)($status['database_imported'] ?? false);
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VMQFox 首次安装</title>
    <style>
        :root { color-scheme: light; font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#f4f6f8; color:#20252b; }
        body { margin:0; padding:32px 16px; } main { max-width:760px; margin:0 auto; background:#fff; border:1px solid #e2e6ea; border-radius:8px; padding:28px; box-shadow:0 6px 24px #1f29370d; }
        h1 { margin:0 0 8px; font-size:25px; } h2 { margin:26px 0 12px; font-size:17px; border-bottom:1px solid #edf0f2; padding-bottom:8px; }
        p { color:#5e6873; line-height:1.6; } .status { background:#f6f8fa; padding:12px 14px; border-radius:6px; margin:18px 0; } .error { color:#a32626; background:#fff1f1; padding:12px 14px; border-radius:6px; } .success { color:#176b3a; background:#effaf3; padding:12px 14px; border-radius:6px; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; } label { display:flex; flex-direction:column; gap:6px; font-size:14px; color:#45505c; } input { box-sizing:border-box; width:100%; border:1px solid #cbd2d9; border-radius:5px; padding:10px; font:inherit; } input:focus { outline:2px solid #a8c7ff; border-color:#3976d3; }
        button { margin-top:22px; border:0; border-radius:5px; padding:11px 18px; background:#1769d1; color:#fff; font-weight:600; cursor:pointer; } button:disabled { opacity:.6; cursor:wait; } code, pre { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; } pre { overflow:auto; background:#20252b; color:#e9edf1; border-radius:5px; padding:12px; font-size:12px; } a { color:#1769d1; }
        @media (max-width:600px) { main { padding:20px; } .grid { grid-template-columns:1fr; } }
    </style>
</head>
<body><main>
    <h1>VMQFox 首次安装</h1>
    <p>此页面只负责写入配置、导入数据库和创建管理员。系统级依赖请在服务器终端安装，PHP 网页进程不会执行 apt、Docker 或 systemctl。</p>
    <div class="status"><strong>环境状态：</strong><?= $escape($missingText) ?><br><small>当前 Web PHP 版本：<?= $escape((string)$status['php_version']) ?>；项目要求 PHP 8.2+、Composer、PDO MySQL 和二维码识别依赖。</small></div>
    <?php if ($error !== ''): ?><div class="error"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="success"><?= $escape($message) ?></div><?php endif; ?>
    <?php if (!$status['lock']): ?>
    <form method="post" id="installer-form">
        <input type="hidden" name="nonce" value="<?= $escape($nonce) ?>">
        <h2>数据库</h2>
        <div class="grid">
            <label>主机<input name="db_host" value="<?= $escape($defaults['db_host']) ?>" required></label>
            <label>端口<input name="db_port" value="<?= $escape($defaults['db_port']) ?>" inputmode="numeric" required></label>
            <label>数据库名<input name="db_name" value="<?= $escape($defaults['db_name']) ?>" required></label>
            <label>用户名<input name="db_user" value="<?= $escape($defaults['db_user']) ?>" required></label>
            <label>密码<input type="password" name="db_password" autocomplete="new-password"></label>
            <label>时区<input name="timezone" value="<?= $escape($defaults['timezone']) ?>" required></label>
        </div>
        <h2>管理员</h2>
        <div class="grid">
            <label>登录账号<input name="admin_username" value="<?= $escape($defaults['admin_username']) ?>" autocomplete="username" required></label>
            <label>登录密码（至少 8 位）<input type="password" name="admin_password" autocomplete="new-password" minlength="8" placeholder="<?= $hasStoredAdminPassword ? '留空沿用服务器配置' : '' ?>" <?= $hasStoredAdminPassword ? '' : 'required' ?>></label>
        </div>
        <?php if ($databaseImported): ?>
        <div class="status"><strong>已检测到数据库：</strong>四张核心表已经存在，默认跳过 `vmq.sql` 导入，避免重复建表。取消勾选后提交会被拒绝，不会覆盖已有数据。</div>
        <label style="display:block;margin-top:18px"><input type="checkbox" name="skip_schema" value="1" checked style="width:auto;margin-right:8px">跳过已导入的数据库结构</label>
        <?php else: ?>
        <input type="hidden" name="schema_action" value="import">
        <?php endif; ?>
        <label style="display:block;margin-top:18px"><input type="checkbox" name="confirm" value="1" required style="width:auto;margin-right:8px">我确认数据库可用于此站点，并同意<?= $databaseImported ? '跳过已存在的数据库导入并' : '导入 `vmq.sql` 和' ?>创建管理员。</label>
        <button type="submit">开始安装</button>
    </form>
    <?php else: ?><p>系统已安装。请访问 <a href="/">首页</a> 登录。</p><?php endif; ?>
    <h2>主机依赖安装</h2>
    <p>Docker：</p><pre>bash scripts/install.sh --mode docker</pre>
    <p>宝塔/Linux：</p><pre>bash scripts/install.sh --mode baota</pre>
    <p>二维码识别需要 `zbarimg`、`tesseract` 和 PHP `proc_open`。安装后如果检测仍显示未安装，请先重启宝塔中当前网站使用的 PHP-FPM 版本，再刷新此页。</p>
</main><script>document.getElementById('installer-form')?.addEventListener('submit',function(){this.querySelector('button').disabled=true;});</script></body></html>
