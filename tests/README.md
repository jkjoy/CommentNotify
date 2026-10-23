# 隔离测试

在 Typecho 根目录运行：

```powershell
php usr/plugins/CommentNotify/tests/integration.php
```

需要 PHP 8.2+、pdo_sqlite、sodium 和 cURL 扩展。测试加载真实 Typecho 核心，仅使用内存 SQLite 和注入的 HTTP 响应，不读取站点数据库，不向外发送消息。

覆盖 QQ 官方端点、令牌刷新、Ed25519 回调及一次性绑定，微信扫码登录、官方域名校验、收件人同步、会话发送与过期，Typecho 配置和评论过滤，以及管理员操作的权限、POST、CSRF 和禁用检查。
