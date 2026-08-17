# VMQFox 2026 公开发布加固实施计划

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** 修复公开发布前的路由认证绕过、SSRF、未付款回调签名、监控重放、默认凭据、旧 APK 与公开签单页面问题，并保持旧版客户端和 KSU 模块可用。

**Architecture:** 通过 ThinkPHP 强制显式路由建立认证边界；用 MonitorEventGuard 规范秒/毫秒时间戳并用 setting 表唯一键原子认领事件；用 AdminCredentials 统一初始化、哈希验证和旧明文迁移。部署、文档和静态发布面同步收紧。

**Tech Stack:** PHP 8.2、ThinkPHP 8、MySQL 5.7、Composer、原生 JavaScript/Layui、Docker Compose。

## Global Constraints

- 继续支持 /createOrder、/getOrder、/checkOrder、/closeOrder、/getState、/appHeart 和 /appPush。
- 监控 t 接受 10 位秒或 13 位毫秒，服务器时间窗口固定为 ±300 秒。
- 重放键格式为 monitor_event:<sha256>，保留 86400 秒，不新增必需迁移。
- 管理员首次初始化变量为 ADMIN_USERNAME 与 ADMIN_PASSWORD，不提交真实值。
- Compose 前端镜像固定为 hulisang/vmqfox-frontend@sha256:4ae8fdea55298c45bff5ffca70943ce03224b702a29e1b8bc051939df6f8a841。
- 发行版不包含 public/v.apk、公开测试下单表单或固定生产密钥。

---

### Task 1: 强制显式路由并封闭后台通配入口

Files:
- Create: config/route.php
- Modify: route/app.php, route/route.php
- Modify: app/controller/admin/Index.php
- Test: tests/RouteSecurityTest.php

- [ ] 写失败测试：断言 config/route.php 含 url_route_must=true、route/app.php 不含 admin/index/:action、getCurl 为 private。
- [ ] 运行 php tests/run.php，确认新断言失败。
- [ ] 创建 config/route.php，内容为 return ['url_route_must' => true];；删除后台通配路由；补齐前端实际使用的显式兼容路由；将 admin 控制器 getCurl 改为 private。
- [ ] 运行 PHP 测试并检查未声明 action 返回 404、敏感 REST 路由仍返回 401。
- [ ] 提交：git add config/route.php route/app.php route/route.php app/controller/admin/Index.php tests/RouteSecurityTest.php；git commit -m "fix: enforce explicit routes for admin endpoints"。

### Task 2: 阻止未付款订单生成回调签名

Files:
- Modify: app/controller/api/Order.php 的 generateReturnUrl
- Test: tests/CompatibilityControllerTest.php

- [ ] 写断言：计算 sign 前必须检查 order state，允许状态仅为 1 或 2。
- [ ] 在订单存在检查后加入：
  if (!in_array((int) $order['state'], [1, 2], true)) return $this->error('订单尚未支付，不能生成返回地址');
- [ ] 运行 php tests/run.php，确认未付款分支回归通过。
- [ ] 提交：git add app/controller/api/Order.php tests/CompatibilityControllerTest.php；git commit -m "fix: prevent unpaid return-url signatures"。

### Task 3: 监控时间窗、原子重放键和并发订单认领

Files:
- Create: app/service/MonitorEventGuard.php
- Modify: app/controller/api/Monitor.php, app/controller/index/Index.php
- Create: tests/MonitorEventGuardTest.php

Interfaces:
- normalizeTimestamp(mixed $raw, ?int $now = null): ?int
- isFresh(mixed $raw, ?int $now = null): bool
- claim(string $kind, string $type, string $price, string $timestamp, string $signature): bool
- release(string $kind, string $type, string $price, string $timestamp, string $signature): void

- [ ] 先写纯函数测试：10 位秒、13 位毫秒、±300 秒边界和非法字符。
- [ ] 运行 php tests/MonitorEventGuardTest.php，确认缺失类导致失败。
- [ ] 实现严格数字校验、13 位除以 1000、±300 秒判断和 SHA-256 指纹。用参数化 SQL INSERT IGNORE INTO setting(vkey,vvalue) 原子认领，重复返回 false，清理前缀为 monitor_event: 且超过 86400 秒的记录；数据库异常抛出而不是降级。
- [ ] 让 REST heart/push 与旧 appHeart/appPush 共用 guard；签名使用 hash_equals；重复事件不更新 lastpay、不查订单。
- [ ] 订单完成使用 UPDATE ... WHERE state=0，只有影响一行的请求删除 tmp_price 和发送通知；处理中断时释放未提交的认领。
- [ ] 运行 php tests/MonitorEventGuardTest.php 与 php tests/run.php。
- [ ] 提交：git add app/service/MonitorEventGuard.php app/controller/api/Monitor.php app/controller/index/Index.php tests/MonitorEventGuardTest.php；git commit -m "fix: reject stale and replayed monitor events"。

### Task 4: 管理员安全初始化、密码哈希和会话

Files:
- Create: app/service/AdminCredentials.php, tests/AdminCredentialsTest.php
- Modify: app/AppService.php, app/controller/api/Auth.php, app/controller/index/Index.php
- Modify: app/controller/api/Config.php, app/controller/admin/Index.php
- Modify: config/session.php, config/cookie.php, vmq.sql
- Modify: env.example, .env.docker.example, docker-compose.yml, entrypoint.sh, public/admin/setting.html

Interfaces:
- initializeFromEnvironment(): void
- verify(string $username, string $password): bool
- update(string $username, ?string $password): void
- publicSettings(): array

- [ ] 写测试：PASSWORD_DEFAULT 可验证、旧明文只迁移一次、缺少环境变量不创建账号、publicSettings 不返回密码。
- [ ] 实现 env('admin.username')/env('admin.password') 初始化；空记录才初始化；拒绝默认 admin/admin；旧明文成功登录后立即 password_hash。
- [ ] AppService 启动时调用初始化；两套登录使用 password_verify；成功后 Session::regenerate(true)；配置保存只哈希非空新密码并隐藏哈希。
- [ ] vmq.sql 的 user/pass 置空；Compose 和环境示例要求 ADMIN_USERNAME/ADMIN_PASSWORD；设置 HttpOnly、SameSite=Lax，Secure 由 SESSION_SECURE_COOKIE 控制；设置页密码输入允许留空保持原值。
- [ ] 运行 credential 测试和 PHP 全套测试并提交：git commit -m "fix: initialize secure administrator credentials"。

### Task 5: 收紧公开静态发布面和登录提示

Files:
- Delete: public/example/index.html, public/example/main.php, public/v.apk
- Modify: public/admin/jk.html, public/aaa.html, public/index.html, public/api.html, README.md
- Test: tests/DeploymentRequirementsTest.php

- [ ] 写断言：上述文件不存在；jk.html 含 KSU URL；文档写明 KSU 与旧 APK 互斥。
- [ ] 删除公开测试下单表单、签单脚本和 APK；保留只验证回调的 return.php/notify.php。
- [ ] 移除环境检测弹窗和自动更新确认弹窗；退出链接 POST /api/auth/logout 后跳转 index.html。
- [ ] 监控设置页只提供 KSU 链接和说明；API 文档不再链接公开签单表单。
- [ ] 运行 Node/PHP 测试并提交：git commit -m "chore: remove unsafe public demo and legacy apk"。

### Task 6: Compose、数据库、TLS、CORS 和时间区一致

Files:
- Modify: docker-compose.yml, config/app.php, app/middleware/CORS.php
- Modify: app/controller/api/Monitor.php, app/controller/index/Index.php
- Modify: vmq.sql, env.example, README.md

- [ ] 将前端 latest 改为指定 digest；APP_TIMEZONE 读取 APP_TIMEZONE/TZ，Compose 与示例同步。
- [ ] HTTPS 回调启用 SSL_VERIFYPEER=true、SSL_VERIFYHOST=2，禁止 HTTPS 跳转降级到 HTTP。
- [ ] CORS OPTIONS 在调用 next 前返回 204。
- [ ] 新 SQL 使用 InnoDB/utf8mb4；文档补充最小权限数据库用户、.env 600 和 PHP-FPM 配置。
- [ ] 运行 docker compose --env-file .env.docker.example config、bash -n entrypoint.sh、PHP 语法检查并提交：git commit -m "chore: harden deployment and outbound callbacks"。

### Task 7: 文档、许可证、旧备份和发布门禁

Files:
- Modify: README.md, public/api.html, LICENSE, THIRD_PARTY_NOTICES.md
- Modify: tests/DeploymentRequirementsTest.php, tests/RouteSecurityTest.php
- Delete: app/controller/index/index.5.1.php

- [ ] 统一 merchant.example 示例域名和重新计算的 MD5；写明监控 ±300 秒和已支付状态要求。
- [ ] 删除与 MIT 冲突的非商业表述；列出 HTML5 UP、GreenSock、Layui、二维码库和 APK 状态。
- [ ] 删除未引用旧控制器备份。
- [ ] 增加扫描断言：无 admin/admin、:latest、旧外链、public signer、APK、真实二维码/密钥。
- [ ] 执行完整验证：
  php tests/run.php
  npm test
  python -m unittest discover -s tests/python -v
  composer validate --strict
  docker compose --env-file .env.docker.example config
  sh -n entrypoint.sh
  git diff --check
- [ ] 请求最终代码复审，Critical/Important 清零后提交：git commit -m "feat: publish VMQFox backend 2026 edition"。

### Task 8: 创建并核验公开 GitHub 仓库

- [ ] 创建并推送：
  gh repo create LeoChen-CoreMind/vmqfox-backend --public --source . --remote origin --push --description "VMQFox PHP/ThinkPHP 8 payment notification backend with QR-code recognition and Docker deployment."
- [ ] 添加 topics thinkphp、php、qrcode、payment-notification；核验 isPrivate=false、默认分支 main 和远端 SHA。
- [ ] 用 gh api tree 检查 README、LICENSE、KSU 文档、Docker、测试均在远端，且无 public/v.apk、.env、runtime 数据或公开签单表单。
