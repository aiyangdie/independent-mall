<p align="center"><img src="assets/covers/02-independent-mall.jpg" alt="Independent Mall 项目封面" width="100%"></p>

<h1 align="center">Independent Mall · 独立商城（社区版）</h1>

<p align="center">PHP 8.2+ 通用商品与服务商城，自带离线授权，无需授权服务器即可启动</p>

## 简介

一套可独立部署的商城系统：商品、下单、支付、会员、后台管理都在同一份源码里，不依赖外部授权服务。结算默认接入 Fake 支付适配器，真实支付通道需要自行接入。

本项目**不包含**刷量、非官方虚拟币、账号交易、外挂，也不捆绑第三方 AppID。

## 功能

- 商品与分类、购物车、下单与订单履约
- 安装向导：首次访问 `/install` 或命令行一键安装
- 会员注册登录、后台管理、健康检查接口
- 站内营销站与客服中心（`apps/www`，独立端口）
- 测试覆盖：PHPUnit + HTTP 冒烟脚本 + 跨应用授权 E2E

## 技术栈

PHP 8.2+（本机验证 PHP 8.5.10）· Composer · PHPUnit ｜ 内置服务器 `php -S` ｜ 存储与模板按需配置

## 快速开始

空机器（首次会写入 `.env`，之后不覆盖）：

```bat
composer install
php bin/console doctor
php bin/console install --email=ops@example.invalid --password=correct-horse-battery --seed
php -S 127.0.0.1:8090 -t public public/index.php
```

已自行配置 `.env` 与 `APP_KEY` 时：

```bat
php bin/console migrate
php bin/console admin:create
php bin/console demo:seed
php -S 127.0.0.1:8090 -t public public/index.php
```

| 入口 | 地址 |
|---|---|
| 商城前台 | http://127.0.0.1:8090/ |
| 注册登录 | http://127.0.0.1:8090/register |
| 管理后台 | http://127.0.0.1:8090/admin/login |
| 健康检查 | http://127.0.0.1:8090/healthz |
| 官方站（营销 + 客服中心） | http://127.0.0.1:8092/ |

## 测试

```bat
vendor\bin\phpunit
php tests\http_storefront_smoke.php
php tests\http_commerce_smoke.php
```

HTTP 脚本要求社区版商城跑在 `http://127.0.0.1:8090/`，且 `/healthz` 返回 `"edition":"community"`。

## 许可证

仓库未指定著作权人与公开许可条款，详见 `LICENSE.md` 与 `docs/licensing-design.md`。`docs/legal/` 下的法律文本为草稿，未经律师审阅。
