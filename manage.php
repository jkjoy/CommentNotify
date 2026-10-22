<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
require_once __DIR__ . '/Plugin.php';
$user->pass('administrator');
$records = CommentNotify_Service::rows(100);
$action = $security->getIndex('action/comment-notify');
$states = array('pending' => '等待发送 / 重试', 'sending' => '发送中', 'sent' => '已提交给机器人', 'failed' => '发送失败', 'uncertain' => '结果不确定', 'cancelled' => '已取消');
include 'header.php';
include 'menu.php';
?>
<div class="main"><div class="body container">
    <div class="typecho-page-title"><h2>评论通知</h2></div>
    <p>显示最近 100 条投递记录。QQ 和微信的每个接收目标独立处理；实际收到消息请以机器人客户端为准。</p>
    <p>明确失败会自动退避重试，最多 5 次。结果不确定表示请求可能已经送达，请先核对机器人消息，手动重试可能重复发送。修改通道、账号、目标或服务地址后，旧任务会取消。</p>
    <p><a class="btn" href="<?php echo CommentNotify_Plugin::e(Typecho_Common::url('options-plugin.php?config=CommentNotify', $options->adminUrl)); ?>">插件设置</a></p>
    <form method="post" action="<?php echo CommentNotify_Plugin::e($action); ?>">
        <input type="hidden" name="do" value="process"><button type="submit" class="btn">处理到期通知</button>
    </form>
    <div style="overflow-x:auto;margin-top:20px"><table class="typecho-list-table">
        <thead><tr><th>评论</th><th>通道 / 目标</th><th>状态</th><th>尝试次数</th><th>更新时间</th><th>说明 / 操作</th></tr></thead>
        <tbody>
        <?php foreach ($records as $record): ?>
        <tr>
            <td>#<?php echo (int) $record['coid']; ?></td>
            <td><?php echo $record['kind'] === 'qq' ? 'QQ' : '微信'; ?><br><?php echo CommentNotify_Plugin::e($record['target']); ?></td>
            <td><?php echo CommentNotify_Plugin::e($states[$record['state']] ?? $record['state']); ?></td>
            <td><?php echo (int) $record['attempts']; ?></td>
            <td><?php echo CommentNotify_Plugin::e((new Typecho_Date((int) $record['updated_at']))->format('Y-m-d H:i:s')); ?></td>
            <td><?php echo CommentNotify_Plugin::e($record['error'] ?? ''); ?>
                <?php if (in_array($record['state'], array('failed', 'uncertain'), true)): ?>
                <form method="post" action="<?php echo CommentNotify_Plugin::e($action); ?>">
                    <input type="hidden" name="do" value="retry"><input type="hidden" name="id" value="<?php echo (int) $record['id']; ?>">
                    <button type="submit" class="btn btn-xs"><?php echo $record['state'] === 'uncertain' ? '已核对消息，重试' : '重试通知'; ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?><tr><td colspan="6">暂无通知记录。配置并开启通知后，新的已审核评论会在此显示。</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div></div>
<?php include 'copyright.php'; include 'common-js.php'; include 'footer.php'; ?>
