# chaka

Telegram 银行卡 BIN / 发卡行查询机器人。

> 仅用于识别银行卡 BIN、发卡银行和卡类型，不验证账户是否真实存在、余额或持卡人身份。

## 功能

- Telegram 私聊发送银行卡号后查询发卡银行和卡类型
- 支持 `/start`、`/id`、`/help`
- `ALLOWED_USER_IDS` 白名单限制访问
- Webhook Secret 校验
- 返回结果自动脱敏（前 6 位 + 后 4 位）
- 不保存银行卡号，不主动记录完整卡号
- Docker / Railway 部署

## Railway 部署

1. 在 Telegram 搜索 `@BotFather`，发送 `/newbot` 创建机器人，保存 Bot Token。
2. 打开 Railway，新建项目并选择 **Deploy from GitHub Repo**。
3. 选择仓库 `liang17818/chaka`。
4. 在 Railway 的 **Variables** 中添加：

```text
BOT_TOKEN=BotFather 给你的 Token
WEBHOOK_SECRET=自己生成的一串随机字符
ALLOWED_USER_IDS=0
```

`WEBHOOK_SECRET` 只使用字母、数字、下划线和短横线，例如：

```text
chaka_K9p3xQ2m7N5v
```

5. 在 Railway 项目 **Settings / Networking** 中生成公开域名。
6. 浏览器打开部署后的域名，若显示 `chaka bot is running`，说明服务正常。
7. 设置 Telegram Webhook：

```bash
curl -X POST "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
  -d "url=https://<你的Railway域名>/webhook" \
  -d "secret_token=<WEBHOOK_SECRET>" \
  -d 'allowed_updates=["message"]'
```

8. 给机器人发送 `/id`，机器人会返回你的 Telegram User ID。
9. 回到 Railway，把：

```text
ALLOWED_USER_IDS=0
```

改成你的 Telegram User ID，例如：

```text
ALLOWED_USER_IDS=123456789
```

多人使用时用逗号分隔：

```text
ALLOWED_USER_IDS=123456789,987654321
```

10. Railway 会自动重新部署。之后发送银行卡号即可查询。

## 环境变量

| 变量 | 必填 | 说明 |
|---|---|---|
| `BOT_TOKEN` | 是 | Telegram BotFather 生成的 Token |
| `WEBHOOK_SECRET` | 是 | Telegram Webhook Secret Token |
| `ALLOWED_USER_IDS` | 是 | 允许使用机器人的 Telegram User ID，逗号分隔 |
| `PORT` | 否 | Railway 自动提供，本地默认 8080 |

## 安全说明

完整银行卡号属于敏感支付数据。本项目不会主动写数据库，也不会主动将完整卡号打印到应用日志；查询结果只显示脱敏卡号。

当前查询方式会把银行卡号提交给用于 BIN 识别的第三方接口。若只需要识别银行/BIN，建议后续改为本地 BIN 数据库，并只发送前 6～8 位数字，以减少完整卡号的传输范围。

不要把 `BOT_TOKEN`、`WEBHOOK_SECRET` 或任何真实银行卡号提交到 GitHub。
