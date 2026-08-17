# VMQFox Backend

VMQFox 的 PHP / ThinkPHP 8 后端，提供订单、微信/支付宝收款二维码、监控端通知、管理后台兼容接口和 REST 风格 API。

当前版本为 **2.3.2**。本文档按当前代码整理，最后核对日期为 **2026-08-17**。

[![PHP](https://img.shields.io/badge/PHP-8.2%20recommended-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-8-brightgreen)](https://www.thinkphp.cn/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## 2026 首次安装向导

部署后把网站运行目录指向 `public/`。当 Composer 依赖、`.env`、数据库初始化或安装锁缺失时，访问网站会自动跳转到 `/install/`。安装向导会完成数据库连接、必要时创建数据库、导入 `vmq.sql`、生成随机通信密钥、使用 `PASSWORD_DEFAULT` 保存管理员密码，并在 `runtime/install.lock` 写入安装锁。

网页安装器不会执行 `apt`、Docker、systemctl 或宝塔面板命令。主机依赖使用仓库脚本安装：

```bash
# Docker Compose
bash scripts/install.sh --mode docker

# 宝塔 / Debian / Ubuntu（root 终端）
bash scripts/install.sh --mode baota
```

宝塔脚本直接使用终端当前默认的 `php`，不扫描或询问 PHP 版本。执行前请在宝塔把默认 PHP CLI 和网站 PHP 都设置为 `PHP >= 8.2`；脚本检测到默认版本过低时会停止，不会自动切换版本。脚本完成后必须重启网站实际使用的 PHP-FPM 服务，然后访问 `https://你的域名/install/`。网页安装器始终运行在该网站当前配置的 PHP-FPM 下，不提供 PHP 版本选择。如果 `zbarimg` 或 Tesseract 仍显示“未检测到”，先从对应 PHP 版本的禁用函数中移除 `proc_open`，保存并重启 PHP；未移除并重启前网页进程无法检测这些命令。

宝塔脚本会预创建仅网站用户可写的 `.env` 占位文件。手动部署遇到 `.env.tmp` 或 `.env` 权限错误时，在项目根目录执行 `install -m 600 -o www -g www /dev/null .env`，然后刷新 `/install/`；不要把整个项目根目录设置为可写。

安装器不会覆盖已有 `.env`。中断后可保留现有配置再次打开 `/install/`；只有确认数据库初始化失败且尚未生成 `runtime/install.lock` 时，才由服务器管理员备份后手动调整 `.env`。安装锁生成后，网页会拒绝重复安装。

安装前会检查 `pay_order`、`pay_qrcode`、`setting`、`tmp_price` 四张核心表。四张表都存在时页面会显示“已检测到数据库”，并默认勾选“跳过已导入的数据库结构”；未检测到完整结构时才导入 `vmq.sql`。取消跳过选项不会覆盖旧表，安装器会直接提示先确认数据库状态。

## 主要功能

- ThinkPHP 8 后端，兼容现有旧版接口和新的 `/api/*` 接口。
- 微信、支付宝收款二维码自动识别。
- ZXing-C++、OpenCV、zbarimg 多级二维码解析。
- Tesseract OCR 辅助识别图片中的金额。
- 自动识别后允许人工修改二维码金额和内容。
- 二维码管理采用服务端 API 分页，不会一次读取全部数据。
- 支持二维码启用、关闭、删除和金额修改。
- 支持订单创建、查询、关闭、删除、异步通知与返回地址。
- 兼容旧版 APK 推送协议，并推荐使用 KernelSU 监控模块。
- 提供 Docker Compose 与宝塔/Linux 直接部署方式。

## 工作原理

```text
业务系统创建订单
        ↓
VMQFox 分配一个已启用的收款二维码和实际金额
        ↓
用户使用微信或支付宝完成付款
        ↓
手机收到收款通知
        ↓
APK 监控端或 KSU 模块监听通知并推送到 VMQFox
        ↓
VMQFox 按支付方式、金额和订单状态匹配订单
        ↓
更新订单状态并请求 notifyUrl / 跳转 returnUrl
```

系统依赖设备通知完成订单匹配，适合个人、自用、测试或二次开发场景。它不是微信支付或支付宝官方商户接口，也不适合作为无需改造的多商户支付平台。

## 环境要求

| 组件 | 要求 |
| --- | --- |
| PHP | `>= 8.2` |
| PHP 扩展 | `bcmath`、`curl`、`pdo_mysql`、`mbstring`、`zip`、`xml`、`simplexml` |
| PHP 函数 | 必须允许 `proc_open` |
| 数据库 | MySQL 5.7 兼容环境 |
| 缓存 | Redis 7 可选；默认也可使用文件缓存 |
| Composer | Composer 2 |
| 二维码工具 | zbarimg、OpenCV、ZXing-C++ Python 绑定 |
| OCR | Tesseract OCR，建议同时安装英文语言包 |
| Web 服务 | Nginx 或 Apache，网站运行目录必须指向 `public/` |

> 当前服务端识别链不依赖 PHP GD。宝塔“PHP 安装扩展”中找不到 GD，不会影响本项目现有二维码识别功能。

## Docker Compose 部署

### 1. 克隆并准备配置

```bash
git clone https://github.com/LeoChen-CoreMind/vmqfox-backend.git
cd vmqfox-backend
cp .env.docker.example .env.docker
```

编辑 `.env.docker`，至少修改下面四个值，并分别使用随机凭据：

```dotenv
ADMIN_USERNAME=replace-with-an-admin-username
ADMIN_PASSWORD=replace-with-a-long-random-password
VMQ_DB_PASSWORD=replace-with-a-random-password
VMQ_DB_ROOT_PASSWORD=replace-with-a-different-random-password
```

如果前端不是通过本机 `3006` 端口访问，还需要修改：

```dotenv
VMQ_FRONTEND_URL=https://your-frontend.example.com
```

### 2. 检查并启动

```bash
docker compose --env-file .env.docker config
docker compose --env-file .env.docker up -d --build
```

默认地址：

- 前端/管理界面：`http://服务器地址:3006`
- 后端：`http://服务器地址:8000`

首次启动时，后端只会在数据库管理员记录为空时使用 `ADMIN_USERNAME` 和 `ADMIN_PASSWORD` 初始化账号；项目不再提供默认 `admin/admin`。

### 3. 查看状态

```bash
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=200 backend
docker compose --env-file .env.docker logs --tail=200 mysql
```

Docker 镜像构建时会安装 zbarimg、OpenCV、ZXing-C++ Python 绑定、Tesseract 和 PHP XML 扩展。MySQL 与 Redis 默认只在 Compose 内部网络开放，不映射宿主机端口。

国内服务器构建 Alpine 依赖较慢时，可在 `.env.docker` 设置 `VMQ_APK_MIRROR=https://mirrors.aliyun.com/alpine`。该参数只替换 APK 下载源，不改变依赖版本。Docker Hub 加速器如果提示 MySQL 镜像层不存在，应更换可用加速器或从其他可信镜像源拉取同一 `mysql:5.7` 镜像并重新标记，不要修改数据库主版本。

完整 Compose 会额外运行 MySQL、Redis、前端和后端。同一服务器还保留宝塔、宿主机 MySQL/Redis 时建议至少 4 GB 内存；1.6 GB 等低内存主机请只保留一种部署方式，否则首次初始化可能导致系统长时间换页和站点超时。

停止服务：

```bash
docker compose --env-file .env.docker down
```

如需连同数据库卷一起删除，必须先做好备份，再自行执行带 `--volumes` 的命令。

## 宝塔 / Linux 直接部署

以下命令以 Debian 12 / Ubuntu 为例。宝塔用户应通过面板安装网站实际使用的 PHP 版本与扩展。

### 1. 安装基础环境

```bash
apt update
apt install -y nginx mysql-server redis-server \
  php-fpm php-cli php-bcmath php-curl php-mysql php-mbstring php-zip php-xml \
  unzip composer zbar-tools tesseract-ocr tesseract-ocr-eng \
  python3 python3-venv
```

Debian 12 / Ubuntu 常用仓库可以继续安装：

```bash
apt install -y python3-opencv python3-zxing-cpp
```

如果当前发行版没有这两个软件包，或系统包版本存在兼容问题，可以为二维码识别建立独立 Python 环境：

```bash
python3 -m venv /opt/vmqfox-qr
/opt/vmqfox-qr/bin/pip install --upgrade pip
/opt/vmqfox-qr/bin/pip install opencv-python-headless zxing-cpp
```

独立虚拟环境可以避免 Debian/Ubuntu 的系统 Python 包管理冲突。

### 2. 下载项目并安装 PHP 依赖

```bash
cd /www/wwwroot
git clone https://github.com/LeoChen-CoreMind/vmqfox-backend.git
cd vmqfox-backend
composer install --no-dev --optimize-autoloader
cp env.example .env
```

编辑 `.env`：

- 设置数据库地址、库名、账号和随机密码。
- 设置随机 `ADMIN_USERNAME` 和长随机 `ADMIN_PASSWORD`，数据库中的管理员记录为空时会用它们完成首次初始化。
- 生产环境保持 `APP_DEBUG = false` 和 `DEBUG = false`。
- 使用上面的 Python 虚拟环境时，将 `QRCODE_PYTHON_BINARY` 改成：

```dotenv
QRCODE_PYTHON_BINARY = /opt/vmqfox-qr/bin/python
```

### 3. 创建数据库

```bash
mysql -u root -p -e "CREATE DATABASE vmq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p vmq < vmq.sql
```

创建仅供网站使用的最小权限数据库用户，不要让 PHP-FPM 长期使用 MySQL root 账号：

```sql
CREATE USER 'vmqfox'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE ON vmq.* TO 'vmqfox'@'127.0.0.1';
FLUSH PRIVILEGES;
```

如果 PHP 通过 `localhost` 或 Unix socket 连接，请把授权主机同步改为 `localhost`。

### 4. 设置目录和权限

网站运行目录必须是项目的 `public/`，不是项目根目录。

Debian/Ubuntu 常见 PHP-FPM 用户为 `www-data`，宝塔通常为 `www`，按实际环境选择：

```bash
mkdir -p /www/wwwroot/vmqfox-backend/runtime
chown -R www-data:www-data /www/wwwroot/vmqfox-backend
find /www/wwwroot/vmqfox-backend -type d -exec chmod 755 {} \;
find /www/wwwroot/vmqfox-backend -type f -exec chmod 644 {} \;
chmod -R 775 /www/wwwroot/vmqfox-backend/runtime
chmod 600 /www/wwwroot/vmqfox-backend/.env
```

`.env` 只应由部署用户和 PHP-FPM 运行用户读取，不要放在 `public/` 目录中。

### 5. 配置 Nginx

宝塔中设置：

1. 网站目录指向 `/www/wwwroot/vmqfox-backend/public`。
2. 运行目录设置为 `/public`。
3. 伪静态选择 ThinkPHP。
4. PHP 版本选择实际安装扩展的版本。

标准 Nginx 的核心规则可使用：

```nginx
server {
    listen 80;
    server_name your-domain.example.com;
    root /www/wwwroot/vmqfox-backend/public;
    index index.php index.html;

    location / {
        if (!-e $request_filename) {
            rewrite ^(.*)$ /index.php?s=/$1 last;
        }
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # Replace X.Y with the PHP-FPM version currently configured for this website.
        fastcgi_pass unix:/run/php/phpX.Y-fpm.sock;
    }

    location ~ ^/(\.env|\.git|app|config|runtime|vendor) {
        deny all;
    }
}
```

`fastcgi_pass` 必须改成服务器实际使用的 PHP-FPM socket 或地址。

### 6. 宝塔必须解除 `proc_open` 限制

宝塔默认可能禁用 `proc_open`。操作路径通常为：

```text
软件商店 → 已安装 → 当前网站使用的 PHP → 设置 → 禁用函数
```

从禁用函数列表中移除 `proc_open`，保存后重启该 PHP 服务。

必须先移除并重启，网页才能检测 zbarimg、ZXing-C++、OpenCV 和 Tesseract；未移除时显示“未检测到”属于预期结果。

同时注意：

- 命令行执行的 `php` 可能和网站 PHP-FPM 不是同一个版本。
- 安装 `php-xml` 后必须重启网站实际使用的 PHP-FPM。
- 只重启 Nginx 不能代替重启 PHP-FPM。

如果通过系统环境变量提供管理员凭据或二维码工具路径，需要确认 PHP-FPM 没有清除它们。可在网站使用的池配置中显式加入：

```ini
env[ADMIN_USERNAME] = replace-with-an-admin-username
env[ADMIN_PASSWORD] = replace-with-a-long-random-password
env[APP_TIMEZONE] = Asia/Shanghai
env[QRCODE_ZBAR_BINARY] = /usr/bin/zbarimg
env[QRCODE_TESSERACT_BINARY] = /usr/bin/tesseract
```

修改 PHP-FPM 池配置后必须重启对应 PHP-FPM 服务。

## 二维码识别

### 识别顺序

上传微信或支付宝收款码后，系统按以下方式处理：

1. 页面先把图片提交到服务端识别接口。
2. 服务端优先使用 zbarimg 解析二维码。
3. zbarimg 未识别时调用 Python 解码脚本，脚本内部先尝试 ZXing-C++，再使用 OpenCV 的多种预处理策略。
4. 服务端仍未获得二维码内容时，浏览器依次回退到 BarcodeDetector 和 llqrcode。
5. 服务端使用 Tesseract OCR 辅助识别图片中显示的金额。
6. 将自动识别结果填入表单，用户可以人工修改金额和二维码内容后再保存。

不同二维码图片的密度、中心 Logo、截图缩放、压缩程度和边缘留白不同，因此同一个解码器可能出现“一张能识别、另一张不能识别”。当前实现会尝试多个解码器和图像处理策略，但仍建议上传原图。

提高识别率：

- 保留二维码四周完整白边，不要紧贴边缘裁剪。
- 优先使用原图，不要使用聊天软件反复压缩后的缩略图。
- 避免反光、阴影、马赛克、明显模糊或透视变形。
- 图片中可以有中心 Logo，但 Logo 或遮挡面积不能过大。
- 自动金额不正确时直接人工修改金额，再提交保存。

### 依赖检测

后台二维码上传页面提供“识别依赖安装说明”按钮，并检测：

- zbarimg
- ZXing-C++
- OpenCV
- Tesseract
- PHP `proc_open`
- PHP 临时目录写权限
- PHP SimpleXML

服务器命令行可辅助检查：

```bash
command -v zbarimg
zbarimg --version
tesseract --version
/opt/vmqfox-qr/bin/python -c "import cv2, zxingcpp; print(cv2.__version__)"
php -r 'var_dump(function_exists("proc_open"), class_exists("SimpleXMLElement"));'
```

最终以网站后台的检测结果为准，因为 CLI PHP 和 PHP-FPM 的配置可能不同。

## 二维码管理和 API 分页

微信与支付宝二维码管理页不会一次加载全部记录。页面通过 API 请求当前页，服务端在数据库中分页查询。

示例：

```http
GET /api/qrcode/wechat?page=1&limit=12
GET /api/qrcode/alipay?page=2&limit=24
```

`limit` 支持 `12`、`24`、`48`。响应数据包含：

```json
{
  "total": 120,
  "items": [],
  "page": 1,
  "limit": 12
}
```

二维码状态约定：

- `state = 0`：启用，可用于新订单。
- `state = 1`：关闭，不再分配给新订单。

管理页还支持删除二维码和修改识别后的金额。

## 监控端选择

### APK 监控端

普通 Android 设备可以使用 APK 监控端。需要授予通知监听/辅助功能权限，并将监控端、微信和支付宝加入后台运行与省电白名单。

本仓库不再捆绑 APK 文件，后台“监控端设置”页面也不再提供旧 APK 下载。已有 APK 客户端仍可继续使用兼容接口。

### KernelSU 监控模块

本项目同时支持独立开源的 KSU 监控项目：

**[LeoChen-CoreMind/vmq-ksu-listener](https://github.com/LeoChen-CoreMind/vmq-ksu-listener)**

KSU 模块和 APK 监控端的目标与原理相同：监听设备上的收款通知，然后推送到 VMQFox 服务端。

注意：

- **KSU 模块与监控 APK 不能同时使用。**
- 同时运行可能造成同一条收款通知被重复推送。
- 普通设备使用 APK 即可。
- 已具备 KernelSU 环境的设备推荐使用 KSU 模块。
- KSU 模块常驻方式通常更稳定，更不容易因系统后台限制而被清理或出现监控进程掉线。

后台“监控端设置”中已经提供“下载监控 KSU 模块”按钮。KSU 的安装步骤、版本要求和更新方法以该项目自己的 README 为准。

## 核心 API

完整参数说明可在部署后的 `/api.html` 查看。以下为当前代码中常用接口：

除登录、订单创建/支付页查询和监控端签名回调外，`/api/user/*`、订单管理、二维码管理及 `/api/config/*` 均要求管理员 Session 或有效 `Authorization` 令牌。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/api/auth/login` | 管理员登录 |
| POST | `/api/auth/logout` | 退出登录 |
| GET | `/api/user/info` | 当前用户信息 |
| POST | `/api/order/create` | 创建订单 |
| GET | `/api/order/list` | 订单列表 |
| GET | `/api/order/detail/:id` | 订单详情 |
| POST | `/api/order/close/:id` | 关闭订单 |
| GET | `/api/qrcode/wechat` | 微信二维码分页列表 |
| GET | `/api/qrcode/alipay` | 支付宝二维码分页列表 |
| POST | `/api/qrcode/parse` | 上传并识别二维码图片 |
| POST | `/api/qrcode/wechat` | 添加微信二维码 |
| POST | `/api/qrcode/alipay` | 添加支付宝二维码 |
| POST | `/api/qrcode/:id/amount` | 人工修改金额 |
| POST | `/api/qrcode/bind/:id` | 启用或关闭二维码 |
| DELETE | `/api/qrcode/wechat/:id` | 删除微信二维码 |
| DELETE | `/api/qrcode/alipay/:id` | 删除支付宝二维码 |
| GET | `/api/qrcode/dependencies` | 检测识别依赖 |
| GET | `/api/config/status` | 系统与监控状态 |
| POST | `/api/monitor/heart` | 监控端心跳 |
| POST | `/api/monitor/push` | 推送收款通知 |

项目继续兼容 `/createOrder`、`/getOrder`、`/checkOrder`、`/appHeart` 和 `/appPush` 等旧接口。

### 监控事件时间戳与防重放

- 心跳和收款推送的 `t` 参数支持 10 位 Unix 秒时间戳或 13 位 Unix 毫秒时间戳。
- 服务端只接受当前时间前后 300 秒内的事件，服务器和手机都应开启自动时间同步。
- 计算签名时必须使用请求中原始的 `t` 字符串，13 位时间戳不能先除以 1000 再参与签名。
- 已成功认领的相同心跳或收款事件会被判定为重放并拒绝，客户端不应重复发送完全相同的 `t` 和签名组合。

订单返回地址的签名只允许在订单状态为 `1` 或 `2` 时生成。状态为 `0` 的未支付订单和状态为 `-1` 的已关闭订单不会获得返回签名。

`public/example/` 仅保留同步与异步回调验签示例，不包含公开下单表单。使用前需为网站 PHP-FPM 配置 `VMQFOX_COMMUNICATION_KEY`，其值必须与后台通讯密钥一致，然后重启 PHP-FPM。

## 生产环境建议

- 使用随机 `ADMIN_USERNAME` 和长随机 `ADMIN_PASSWORD` 完成首次初始化。
- 为数据库创建最小权限用户，并使用随机密码。
- 保持 `APP_DEBUG=false` 和数据库调试关闭。
- 使用 HTTPS，并限制管理后台的访问来源。
- 不要把 `.env`、`.env.docker`、数据库备份或日志提交到 Git。
- 不要将 MySQL、Redis 端口直接暴露到公网。
- 定期备份 MySQL 数据和二维码配置。
- 为服务器开启时间同步：

```bash
timedatectl set-ntp true
timedatectl status
```

- 升级前同时备份数据库、`.env` 和 `runtime` 中需要保留的数据。

## 常见问题

### 已安装 zbarimg/Tesseract，但网页仍显示“未检测到”

先在宝塔 PHP 的禁用函数中移除 `proc_open`，然后重启网站实际使用的 PHP-FPM。未解除限制时，系统无法执行检测命令。

如果仍然失败，检查：

- PHP-FPM 用户是否有权执行对应二进制文件。
- `.env` 中是否需要填写二进制绝对路径。
- CLI PHP 与网站 PHP-FPM 是否为不同版本。
- PHP 临时目录是否可写。
- 安装 `php-xml` 后是否重启了正确的 PHP 服务。

### 宝塔 PHP 扩展中找不到 GD

当前识别链不要求 PHP GD。需要的是 XML/SimpleXML、`proc_open`、zbarimg、OpenCV、ZXing-C++ 和 Tesseract。

### 下单提示 `bcmul` 或 `bcdiv` 未定义

安装或启用网站实际使用 PHP 版本的 BCMath 扩展。Debian/Ubuntu 可执行 `apt install -y php-bcmath`，宝塔可在对应 PHP 的“安装扩展”中安装 `bcmath`，完成后必须重启该 PHP-FPM 服务。

### 支付宝能识别，但某些微信图片不能识别

常见原因是二维码密度、中心 Logo、压缩、裁剪或边缘留白不同。优先上传原图，确保四周白边完整。系统会自动使用多种解码方式，结果仍可人工修改。

### Docker Compose 启动失败

```bash
docker compose --env-file .env.docker config
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=200 backend mysql
```

重点检查 `.env.docker` 是否存在、两个数据库密码是否已经设置，以及 Docker daemon 是否正在运行。

### 运行时间或订单时间不正确

确认宿主机启用了 NTP，并检查 PHP、MySQL、Docker 容器的时区。Docker Compose 默认使用 `Asia/Shanghai`，可通过 `VMQ_TIMEZONE` 修改。

## 2026-08-17 更新

- 重做微信/支付宝二维码上传与管理布局。
- 支持二维码服务端 API 分页，避免一次加载全部记录。
- 增加自动识别后人工修改金额。
- 增加 ZXing-C++ 与 OpenCV 多策略识别回退。
- 修复二维码删除、关闭开关和订单分配状态过滤。
- 修复系统运行时间显示。
- Docker 镜像加入二维码识别依赖。
- 后台增加 KSU 监控模块下载入口和互斥使用说明。

## 相关项目

- KSU 监控模块：<https://github.com/LeoChen-CoreMind/vmq-ksu-listener>
- VMQFox 前端：<https://github.com/hulisang/vmqfox-frontend>
- V免签原始项目：<https://github.com/szvone/Vmq>
- 旧版监控 APK：<https://github.com/szvone/VmqApk>

本仓库包含基于上游项目持续维护和修复的代码。捆绑的第三方源码及其许可证见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)，Composer 和前端依赖继续遵循各自上游许可证。

## 贡献

问题反馈和改进建议请提交到：

<https://github.com/LeoChen-CoreMind/vmqfox-backend/issues>

提交代码前请先运行 PHP、Node 和 Python 测试，并确认没有提交本地环境文件或运行数据。

## 许可证

本项目自身代码以 [MIT License](LICENSE) 发布，并保留原始项目署名。第三方组件不受本项目 MIT 许可证重新授权。
