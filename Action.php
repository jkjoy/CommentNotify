<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
require_once __DIR__ . '/Plugin.php';

class CommentNotify_Action extends Widget_Base implements Widget_Interface_Do
{
    public function action()
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        if (!$this->user->pass('administrator', true)) {
            $this->response->setStatus(403)->throwJson(array('ok' => false, 'message' => '请以管理员身份登录。'));
        }
        if (!$this->request->isPost()) {
            $this->response->setStatus(405)->throwJson(array('ok' => false, 'message' => '请使用 POST 提交操作。'));
        }
        $token = $this->request->get('_');
        if (!is_string($token) || !hash_equals($this->security->getToken($this->request->getReferer()), $token)) {
            $this->response->setStatus(403)->throwJson(array('ok' => false, 'message' => '表单验证失败，请刷新页面后重试。'));
        }
        $do = $this->request->get('do', '');
        if ($do === 'test') {
            $this->test();
            return;
        }
        try {
            if ($do === 'retry') {
                $id = $this->request->get('id');
                if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $id)) {
                    throw new InvalidArgumentException('通知编号无效。');
                }
                $changed = CommentNotify_Service::retry((int) $id);
                $message = $changed ? '已加入重试队列，请刷新查看结果。' : '该记录无需重试或正在发送。';
            } elseif ($do === 'process') {
                $result = CommentNotify_Service::process(4);
                $message = '队列已处理，请查看通知记录。';
            } else {
                throw new InvalidArgumentException('操作不存在。');
            }
        } catch (InvalidArgumentException $e) {
            throw new Typecho_Widget_Exception($e->getMessage(), 400);
        }
        Typecho_Widget::widget('Widget_Notice')->set($message, 'notice');
        $this->response->redirect(Helper::url('CommentNotify/manage.php'));
    }

    private function test()
    {
        try {
            $kind = $this->request->get('kind');
            if (!in_array($kind, array('qq', 'wechat'), true)) {
                throw new InvalidArgumentException('请选择 QQ 或微信通道。');
            }
            $input = $this->request->get('settings', array(), $exists);
            if (!$exists || !is_array($input) || $this->request->get('settings') !== null) {
                throw new InvalidArgumentException('测试配置格式无效，请刷新插件设置页。');
            }
            // Only the explicitly selected test channel can send a message.
            $input['qq_enabled'] = $kind === 'qq' ? '1' : '0';
            $input['wechat_enabled'] = $kind === 'wechat' ? '1' : '0';
            $settings = CommentNotify_Transport::validate(CommentNotify_Plugin::mergeSecrets($input));
            $results = array();
            foreach (CommentNotify_Transport::destinations($settings) as $destination) {
                $destination['message'] = CommentNotify_Transport::prepareMessage('【评论通知测试】' . "\n" . '站点：' . (string) $this->options->title . "\n" . '收到此消息表示该接收目标可以接收评论通知。', $settings['include_links'] === '1');
                $destination['request_id'] = bin2hex(random_bytes(20));
                $result = CommentNotify_Transport::send($destination, $settings);
                $results[] = array('target' => $destination['target'], 'ok' => $result['ok'], 'message' => $result['message']);
            }
            $ok = count($results) > 0;
            foreach ($results as $result) {
                $ok = $ok && $result['ok'];
            }
        } catch (InvalidArgumentException $e) {
            $this->response->setStatus(400)->throwJson(array('ok' => false, 'message' => $e->getMessage()));
        } catch (Throwable $e) {
            $this->response->setStatus(502)->throwJson(array('ok' => false, 'message' => '测试发送失败，请检查机器人服务与通道配置。'));
        }
        $this->response->throwJson(array('ok' => $ok, 'message' => $ok ? '测试请求已提交，请确认收到消息后保存设置。' : '部分或全部目标发送失败，请查看各目标结果。', 'results' => $results));
    }
}
