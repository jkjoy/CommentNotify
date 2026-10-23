<?php
declare(strict_types=1);

if (!defined('__TYPECHO_ROOT_DIR__')) { http_response_code(403); exit; }
require_once __DIR__ . '/Plugin.php';

class CommentNotify_Action extends Widget_Base implements Widget_Interface_Do
{
    public function action()
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        if (!Typecho_Plugin::exists('CommentNotify')) {
            $this->response->setStatus(404)->throwJson(['ok' => false, 'message' => '评论通知插件未启用。']);
        }
        $do = $this->request->get('do', '');
        if ($do === 'qq_webhook') {
            comment_notify_qq_webhook_request();
            return;
        }
        if (!$this->user->pass('administrator', true)) {
            $this->response->setStatus(403)->throwJson(['ok' => false, 'message' => '请以管理员身份登录。']);
        }
        if (!$this->request->isPost()) {
            $this->response->setHeader('Allow', 'POST')->setStatus(405)->throwJson(['ok' => false, 'message' => '请使用 POST 提交操作。']);
        }
        $token = $this->request->get('_');
        if (!is_string($token) || !hash_equals($this->security->getToken($this->request->getReferer()), $token)) {
            $this->response->setStatus(403)->throwJson(['ok' => false, 'message' => '表单验证失败，请刷新页面后重试。']);
        }
        try {
            $result = ['ok' => true, 'message' => '状态已更新。'];
            switch ($do) {
                case 'test_qq': case 'test_weixin':
                    $result = comment_notify_test($do === 'test_qq' ? 'qq' : 'weixin');
                    break;
                case 'qq_binding_start':
                    comment_notify_qq_binding_start();
                    $result['message'] = '绑定码已生成，请向目标 QQ 会话发送下方绑定命令。';
                    break;
                case 'qq_binding_cancel':
                    comment_notify_qq_binding_cancel();
                    $result['message'] = '绑定码已取消。';
                    break;
                case 'weixin_start':
                    $result = array_merge($result, comment_notify_weixin_start());
                    break;
                case 'weixin_check':
                    $result = array_merge($result, comment_notify_weixin_check($this->text('verify_code')));
                    break;
                case 'weixin_sync':
                    $result = array_merge($result, comment_notify_weixin_sync());
                    break;
                case 'weixin_bind':
                    comment_notify_weixin_bind($this->text('weixin_user_id'));
                    $result['message'] = '微信接收人已绑定，可开启微信通知并保存。';
                    break;
                case 'weixin_disconnect':
                    comment_notify_weixin_disconnect();
                    $result['message'] = '微信连接已断开，登录凭证和会话已清除。';
                    break;
                case 'status':
                    break;
                default:
                    $this->response->setStatus(400)->throwJson(['ok' => false, 'message' => '操作不存在。']);
            }
            $result['status'] = comment_notify_status();
        } catch (RuntimeException $exception) {
            $result = ['ok' => false, 'message' => get_class($exception) === RuntimeException::class
                ? $exception->getMessage() : '操作失败，请检查数据库和服务器环境。'];
            try { $result['status'] = comment_notify_status(); } catch (Throwable) {}
            $this->response->setStatus(400)->throwJson($result);
        } catch (Throwable) {
            $this->response->setStatus(502)->throwJson(['ok' => false, 'message' => '操作失败，请检查网络、数据库和服务器环境。']);
        }
        $this->response->throwJson($result);
    }

    private function text(string $name): string
    {
        $value = $this->request->get($name, '');
        if (!is_string($value)) { throw new RuntimeException('提交内容格式无效。'); }
        return trim($value);
    }
}
