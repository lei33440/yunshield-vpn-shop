# 云盾 VPN 套餐商城

原生 PHP + MySQL 的 VPN 套餐商城，提供套餐展示、用户订单、在线支付配置、人工交付和后台管理功能。项目不依赖前端框架；运行时使用 PHP PDO 连接 MySQL/MariaDB。

> 本仓库不包含生产配置、管理员账号、用户数据、真实支付密钥或运行日志。部署时必须使用独立的生产凭据并通过环境变量或受保护的服务器配置注入。

## 功能一览

### 用户端
- 注册、登录和个人资料/密码管理。
- 浏览套餐、购买期限方案并提交订单。
- 查看订单状态、付款/交付进度、到期倒计时和站内通知。
- 查看正式交付内容、复制订阅链接；支持付款确认后的临时订阅地址，正式交付后自动失效。
- 提交售后工单并查看回复。
- 查看软件客户端下载资源、使用教程及文章。

### 管理端
- 订单筛选、付款确认、人工发货、修改交付内容和订单状态处理。
- 套餐及多期限方案管理。
- 临时订阅地址、文章、软件下载资源和售后工单管理。
- 易支付配置（支付宝/微信）、异步通知处理及支付记录。
- 管理员操作日志。
- 活动运营配置：幸运抽奖、拉人返佣、好友拼单和团长免单。
- 幸运抽奖自定义多奖品奖池：每个奖品独立设置面值、使用门槛、有效期、库存、权重和启停状态。
- 好友拼单可按具体 `product_plan` 选择允许参与的套餐方案，并由服务端校验订单优惠。

### 活动运营
- 幸运抽奖支持每日次数、整体中奖概率和多个奖品权重配置。
- 奖品库存支持固定库存和不限库存；库存耗尽后自动排除，后台启用活动前必须存在有效奖品。
- 每个抽奖奖品独立生成优惠券，独立控制优惠金额、使用门槛和有效天数。
- 拉人返佣支持首笔有效付款结算、人工审核提现和管理员审计。
- 好友拼单支持指定多个套餐方案，成员创建/加入时先占位，订单付款确认后才计入成团人数。
- 团长免单支持按有效邀请人数和指定套餐方案发放奖励。

### 安全相关
- 使用 `password_hash/password_verify` 保存密码。
- PDO 预处理、CSRF 校验、HTML 输出转义和用户订单归属校验。
- 管理员交付内容及易支付密钥使用 AES-256-GCM 加密保存。
- 易支付异步通知验证签名、商户号、订单号、金额和交易状态；订单以异步通知确认为准，重复通知按幂等方式处理。

## 技术要求

- PHP 7.4 或兼容版本。
- MySQL/MariaDB，InnoDB，`utf8mb4`。
- PHP 扩展：PDO、`pdo_mysql`、OpenSSL、JSON、Mbstring、Session；使用 HTTPS/在线支付时还需配置服务器与支付网关的网络访问。
- Web 根目录必须指向项目的 `public/`，不要把项目根目录作为公开 Web 根目录。

## 快速开始

1. 创建数据库，例如 `vpn_shop`，字符集使用 `utf8mb4`。
2. 将 `config/config.example.php` 复制为 `config/config.php`，或直接使用该示例配置，并在当前进程环境中设置以下变量。不要把真实值写入 Git：

```sh
export VPN_BASE_URL='http://127.0.0.1:8080'
export VPN_APP_DEBUG='0'
export VPN_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=vpn_shop;charset=utf8mb4'
export VPN_DB_USER='vpn_shop'
export VPN_DB_PASSWORD='替换为独立的数据库密码'
export VPN_ENCRYPTION_KEY='替换为高强度随机密钥，至少 32 字节'
# 可选：付款确认后展示的固定临时订阅地址
export VPN_TEMPORARY_SUBSCRIPTION_URL=''
```

生产环境请将变量放在权限受限的 PHP-FPM/systemd 环境或秘密管理设施中。`VPN_ENCRYPTION_KEY` 必须稳定保存；轮换会导致现有加密数据无法解密。

3. 初始化数据库并导入可选演示套餐：

```sh
php scripts/migrate.php
# 可选：仅开发环境导入演示套餐
php scripts/seed.php
```

`seed.php` 会写入演示套餐与方案；生产环境如不需要演示商品，请不要执行它。

4. 创建管理员。脚本会交互式询问邮箱和密码；密码至少 12 个字符：

```sh
php scripts/create_admin.php
```

也可通过安全的进程环境变量传入 `VPN_ADMIN_PASSWORD`，不要把密码作为 shell 历史中的命令文本。

5. 本地开发启动（PHP 内置服务器仅用于开发）：

```sh
php -S 127.0.0.1:8080 -t public public/router.php
```

- 前台：`http://127.0.0.1:8080/`
- 管理端：`http://127.0.0.1:8080/admin/login`

## 在线支付配置

项目集成了易支付兼容接口。在线支付需要管理员在管理端配置网关地址、商户 ID、MD5 密钥和回调基址，并启用需要的支付渠道。支付密钥加密保存在数据库中，页面不回显明文。

- 支付渠道：支付宝、微信（取决于网关商户开通情况）。
- 回调路径：`/payment/notify`；支付返回页面只展示订单状态，不作为付款确认依据。
- 仅通过验签并核对商户号、金额、订单号和成功状态的异步通知确认付款。
- 生产环境应使用网关和支付平台要求的公网 HTTPS 回调地址，并先以小额订单完整测试回调。
- 在线支付配置失败时，可按站点当前后台设置采用人工确认流程。不要在无公网可达回调时启用线上支付。

## 目录结构

```text
app/Core/          引导、路由、数据库、安全等核心组件
app/Services/      订单、支付、加密等业务服务
config/            配置示例；本地 config.php 不纳入版本控制
database/          数据库结构与可选演示种子
public/            Web 入口、路由器、CSS 和静态资源
scripts/           数据迁移、演示数据和管理员创建脚本
storage/logs/      运行日志（不纳入版本控制）
```

## 部署注意事项

- Web server document root 指向 `public/`；禁止外部访问 `config/`、`database/`、`scripts/`、`storage/`。
- 生产环境设置 `VPN_APP_DEBUG=0`，为数据库和加密密钥使用独立的强凭据，并限制配置文件权限。
- 配置 HTTPS、会话 Cookie 安全属性、备份和日志轮转；不要将日志、数据库转储或线上配置上传至 GitHub。
- 部署后运行迁移并检查站点、登录、订单、支付通知与交付流程；上线支付前确认回调可以从支付平台访问。
- 本仓库不携带生产服务器配置或数据，读者需根据自身环境完成 Nginx/Apache 与 PHP-FPM 配置。

## 验证

```sh
php -l public/index.php
php -l scripts/migrate.php
php -l scripts/seed.php
php -l scripts/create_admin.php
```

完整验证需要可用的 MySQL/MariaDB 数据库和有效的本地环境配置。

## 发布前检查清单

```sh
php -l public/index.php
php -l app/Core/Bootstrap.php
php -l app/Core/AdminDashboard.php
php -l app/Core/AdminExtraPages.php
php -l app/Core/AdminView.php
php -l app/Services/ActivityService.php
php -l app/Services/OrderService.php
php -l app/Services/PaymentService.php
php -l scripts/migrate.php
php -l scripts/create_admin.php
php -l scripts/seed.php
git diff --check
```

发布前请确认：

- `config/config.php`、`.env`、数据库备份、运行日志和支付密钥未被 Git 跟踪。
- 生产环境 `VPN_APP_DEBUG=0`，`VPN_ENCRYPTION_KEY` 使用稳定且高强度的密钥。
- Web 根目录只指向 `public/`。
- 先在测试环境执行 `php scripts/migrate.php`，再备份数据库后执行生产迁移。
- 抽奖奖池、拼团允许方案、支付回调地址和管理员账号已完成配置。
- 不要将真实支付、提现或生产订单数据写入公开仓库。

## 许可证

当前仓库未声明开源许可证。未经项目维护者另行授权，请勿假定可以复制、再分发或用于商业用途。
