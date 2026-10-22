# 评论通知隔离测试

先将插件安装或克隆到 `TYPECHO_ROOT/usr/plugins/CommentNotify`。测试依赖 Typecho 核心与安装表结构，不能从 Typecho 之外的独立克隆直接运行。在 Typecho 根目录执行：

```sh
php usr/plugins/CommentNotify/tests/integration.php
```

需要 PHP 8+ CLI、PDO SQLite 和 cURL，且 `proc_open` 可用、允许本机端口监听。测试使用真实 Typecho 核心、临时 SQLite 数据库、随机端口的 `127.0.0.1` HTTP 模拟服务，以及两个独立 PHP 进程验证并发抢占。网络超时用例约需 10 秒。

测试不会加载站点 `config.inc.php`，不会访问真实数据库，不使用真实机器人令牌，不向 QQ / 微信或其他外部服务器发送通知。临时文件和 HTTP 服务在退出时清理。

共 31 项，覆盖审核状态、作者过滤、真实 Typecho 审核前置钩子、双平台及多目标队列、幂等去重、独立失败、指数退避和五次上限、不确定状态人工重试、超时与租约、并发抢占、旧目标取消、取消后重新审核、OpenClaw HTTP 协议、独立 QQ 服务兼容、UTF-8 截断、令牌不进入 HTML / 队列、空令牌仅在服务地址不变时保留、设置保存后同请求缓存刷新，以及测试入口的管理员 / POST / CSRF 校验和未保存字段测试。
