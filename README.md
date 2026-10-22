# CommentNotify · QQ / 微信评论通知

从 [Enhancement](https://github.com/jkjoy/Enhancement) 提取评论通知规则、消息净化和 QQ 服务投递方式，独立提供 QQ 和微信通知。复用已经接通的 OpenClaw 机器人，无需调用模型。也兼容 Enhancement 使用的独立 QQ 通道 / 官方 QQ Bot 通知服务。

## 安装

1. 下载源码后将文件夹命名为 `CommentNotify`，放到 Typecho 的 `usr/plugins/`。也可在 Typecho 根目录执行（私有仓库需先配置 GitHub 访问权限）：

   ```sh
   git clone https://github.com/jkjoy/CommentNotify.git usr/plugins/CommentNotify
   ```

2. 后台「控制台 → 插件」启用 **CommentNotify**，进入设置。需要 Typecho 1.2 / 1.3、PHP 7.4+ 和 cURL。
3. 填写 OpenClaw 网关地址、Gateway Token、对应通道及接收目标。QQ 和微信可以分别开启；测试按钮支持在开启前测试当前填写的配置，不会自动保存。
4. 确认机器人收到测试消息，再保存设置并开启对应评论通知。
5. 在「管理 → 评论通知」查看最近 100 条记录、错误及手动重试操作。

插件启用前不会改动数据库或向机器人发送消息。不会修改 Enhancement 的代码、设置或队列表；从 Enhancement 迁移时，请在原插件中关闭「QQ 评论通知」，避免两边重复提醒。原来的友链通知可继续留在 Enhancement 中。此插件仅发送评论通知，不包含通过聊天管理博客的指令功能。

## OpenClaw 接入

网关地址示例：`http://openclaw:18789`。填写根地址，插件自动请求 `POST /tools/invoke`，使用 `Authorization: Bearer ...`。PHP 在容器中时，需要填写容器能够访问的服务名或地址，`127.0.0.1` 指向 PHP 容器本身。可以保留网关反向代理的路径前缀。

OpenClaw 必须已经安装对应的通道插件并完成登录。其网关 HTTP 工具策略必须允许 `message` 工具的 `send` 操作；如果返回 404 或工具被拒绝，请核对网关策略及当前版本的默认限制。无需开放执行任意指令、运行智能体或模型的接口。令牌保存在 Typecho 插件设置中，页面不会回显；留空保存保留已存令牌，更换服务地址需要重新填写令牌。

已有网关使用 `coding` 等工具配置时，按 [工具策略文档](https://docs.openclaw.ai/gateway/config-tools/tool-policy) 将 `message` 加入允许范围。例如在已有 `tools.profile` 旁配置 `tools.alsoAllow: ["message"]`；如已有 `tools.allow`，应在原列表中加入 `message`，不要在同一层同时配置 `allow` 和 `alsoAllow`。

插件请求示例（其中所有值均为示例）：

```json
{
  "tool": "message",
  "args": {
    "action": "send",
    "channel": "openclaw-weixin",
    "accountId": "your-account-id",
    "target": "your-user-id@im.wechat",
    "message": "【新评论通知】..."
  }
}
```

`accountId` 留空时省略，使用通道默认账号；有多个账号时建议明确填写。QQ 和微信可以分别使用不同的账号。接收目标每行一个，每端最多 20 个。请使用 OpenClaw 实际识别的 ID，不要用昵称猜测。

### QQ

选择「OpenClaw QQ 机器人」，默认通道名称为 `qqbot`。常见目标形式：

```text
qqbot:c2c:USER_OPENID
qqbot:group:GROUP_OPENID
```

目标 OpenID 从对应 QQ 通道的配对、会话或事件信息中取得。主动发送受 QQ 平台的权限、额度、好友 / 群设置及消息域名限制。未报备链接域名时，可以把「通知中的链接」设置为「省略链接」。

### 微信

腾讯微信通道插件为 `@tencent-weixin/openclaw-weixin`，默认通道名称为 `openclaw-weixin`，目前支持私聊。按 [OpenClaw 微信文档](https://docs.openclaw.ai/channels/wechat) 安装、扫码登录，并先从接收通知的微信向机器人发送一条私聊消息。

接收目标是类似 `xxx@im.wechat` 的 Tencent iLink 用户 ID，**不能填微信号、手机号或昵称**。从该微信通道的会话记录中取得 ID；多账号填写与该会话对应的 `accountId`。

该通道发送时依赖先前私聊所缓存的会话 context token，有效期由微信平台控制。**可以实现评论通知，但不能保证长时间无人交互后仍能无限制主动推送。** 提示缺少会话或会话过期时，先向机器人发送私聊消息再测试。若需要全天候且不依赖私聊会话的提醒，应评估另一种具备对应主动发送能力的微信通道；本插件不会绕过平台限制，也未把企业微信群机器人当作个人微信通道。

使用其他 OpenClaw 微信通道插件时，可填写其实际通道名和目标格式，以该插件的协议为准。

### 原有独立 QQ 服务

选择「独立 QQ 通道 / 官方 QQ Bot 服务」，填写原 Enhancement 使用的服务根地址、服务访问令牌，以及每行一个的已绑定别名，例如 `owner`。此模式向 `/api/notifications` 发送 `targets`、`message` 和固定的 `idempotency_key`；与 [独立 QQ 通道](https://github.com/jkjoy/dockerfile/tree/main/qq-channel) 兼容。微信仍使用 OpenClaw。

## 评论与重试规则

- 仅处理 `type=comment`、`status=approved` 的评论，包含前台评论、回复及后台回复；回响、待审核和垃圾评论不通知。
- 待审核评论通过审核后，在本次请求完成时重新读取数据库，确认审核成功才入队。默认跳过文章作者自己的评论，可在设置中开启作者回复通知。
- 不补发安装前的全部历史评论；之后发生审核操作的评论按正常规则处理。同一评论、同一目标去重，反复审核不会重复提醒。
- 每个 QQ / 微信目标独立保存任务，消息包含编号、评论者、文章标题、评论正文和评论链接。使用纯文本并限制到 1800 个 UTF-8 字节；不发送评论者邮箱和 IP。
- 请求结束时处理队列，PHP-FPM 环境先结束浏览器响应。机器人故障与发送错误不会让评论提交失败。
- 明确失败退避重试，最多 5 次。网络超时、返回结果不确定、发送进程异常退出等情况下，状态标记为「结果不确定」，不自动重发；先核对机器人消息，再手动重试，避免重复通知。
- 任务包含目标快照和固定请求 ID，不存机器人令牌。修改服务地址、通道、账号、目标或关闭对应通知后，旧任务取消，不会改投新目标。修改令牌可以用于同一地址的失败任务重试。
- OpenClaw 的 HTTP `idempotencyKey` 用于关联工具调用，不能当作服务端重复投递保证；插件以本地唯一事件键去重，并对结果不确定的发送停止自动重试。独立 QQ 服务使用其原有的服务端 `idempotency_key`。
- 只对明确失败 / 结果不确定的记录提供手动重试；已成功记录保留去重标记。机器人报告已发送不等于用户已阅读，最终以客户端消息为准。

普通访问会触发到期任务处理。无人访问时的重试需要可选的服务器计划任务；Linux `crontab` 示例：

```cron
* * * * * /usr/bin/php /path/to/typecho/usr/plugins/CommentNotify/cron.php
```

Windows 可用任务计划程序执行同一 PHP 脚本。CLI 必须使用与站点一致的 PHP 扩展和数据库配置。脚本仅供命令行执行；插件不创建操作系统计划任务。禁用插件保留队列数据，且停止发送。

## 文件与验证

`Plugin.php` 注册评论 / 审核钩子与配置；`Transport.php` 对接机器人；`Service.php` 管理独立 `comment_notify_queue` 表；`Action.php` 提供管理员测试和重试；`manage.php` 显示记录；`cron.php` 可用于调度。

建表支持 SQLite、MySQL / MariaDB 和 PostgreSQL。开发验证使用 Typecho 1.3.0、PHP 8.5、临时 SQLite 和本地模拟机器人，不读取站点配置、不发送真实 QQ / 微信消息。真实网关连接、目标账号和微信会话需要在安装后通过测试按钮验证。

测试依赖 Typecho 的真实核心和安装表结构，需先将插件安装或克隆到 `TYPECHO_ROOT/usr/plugins/CommentNotify`，不能从 Typecho 之外的独立克隆直接运行。在 Typecho 根目录执行隔离测试：

```sh
php usr/plugins/CommentNotify/tests/integration.php
```

详情见 [tests/README.md](tests/README.md)。

接口参考：[Tools Invoke HTTP API](https://docs.openclaw.ai/gateway/tools-invoke-http-api)、[QQ 通道](https://docs.openclaw.ai/channels/qqbot)、[微信通道](https://docs.openclaw.ai/channels/wechat)。
