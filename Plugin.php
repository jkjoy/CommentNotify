<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/Service.php';

/**
 * 评论通知：独立 QQ / 微信通知，支持复用 OpenClaw 机器人。
 *
 * @package CommentNotify
 * @author jkjoy
 * @version 1.0.0
 * @since 1.2.0
 */
class CommentNotify_Plugin implements Typecho_Plugin_Interface
{
    private static $comments = array();
    private static $registered = false;

    public static function activate()
    {
        if (!extension_loaded('curl')) {
            throw new Typecho_Plugin_Exception('评论通知需要 PHP cURL 扩展。');
        }
        try {
            CommentNotify_Service::install();
        } catch (Throwable $e) {
            throw new Typecho_Plugin_Exception('无法创建评论通知队列，请检查数据库权限与数据库类型。');
        }
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array(__CLASS__, 'finishComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array(__CLASS__, 'finishComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = array(__CLASS__, 'mark');
        Typecho_Plugin::factory('index.php')->begin = array(__CLASS__, 'registerFlush');
        Typecho_Plugin::factory('admin/common.php')->begin = array(__CLASS__, 'registerFlush');
        Helper::addAction('comment-notify', 'CommentNotify_Action');
        Helper::addPanel(3, 'CommentNotify/manage.php', '评论通知', '评论通知记录', 'administrator');
        return '评论通知已启用。请先配置 QQ / 微信并测试，再开启对应通知。';
    }

    public static function deactivate()
    {
        Helper::removeAction('comment-notify');
        Helper::removePanel(3, 'CommentNotify/manage.php');
        return '评论通知已停用，通知记录保留。';
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $intro = new Typecho_Widget_Helper_Layout('p');
        $intro->html('复用已经登录的 OpenClaw QQ / 微信通道，发送已审核评论。微信目前支持私聊，需先向机器人发过消息。'
            . ' <a href="' . self::e(Helper::url('CommentNotify/manage.php')) . '">查看通知记录</a>。'
            . ' 从 Enhancement 迁移时，请关闭原插件的 QQ 评论通知，避免重复提醒。');
        $form->addItem($intro);
        self::heading($form, 'OpenClaw 网关');
        self::text($form, 'gateway_url', '网关地址', '例如 http://openclaw:18789，仅填写根地址；PHP 服务需要能访问该地址。');
        self::secret($form, 'gateway_token', '网关访问令牌', '填写 OpenClaw Gateway Token。留空保留已保存的令牌；更换地址时请重新填写。');

        self::heading($form, 'QQ 通知');
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('qq_enabled', array('0' => '关闭', '1' => '开启'), '0', 'QQ 评论通知'));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('qq_provider', array('openclaw' => 'OpenClaw QQ 机器人', 'service' => '独立 QQ 通道 / 官方 QQ Bot 服务'), 'openclaw', 'QQ 通知方式'));
        self::text($form, 'qq_channel', 'QQ 通道名称', '通常为 qqbot；按 OpenClaw 实际安装的通道填写。', 'qqbot');
        self::text($form, 'qq_account', 'QQ 账号 ID', '单账号可留空；多账号填写 OpenClaw 中的 accountId。');
        self::text($form, 'qq_service_url', 'QQ 服务地址', '仅独立 QQ 通道使用，与 Enhancement 的 QQ 服务根地址相同。');
        self::secret($form, 'qq_service_token', 'QQ 服务令牌', '仅独立 QQ 通道使用。留空保留已保存的令牌；更换地址时请重新填写。');
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea('qq_targets', null, '', 'QQ 接收目标', '每行一个，最多 20 个。OpenClaw 使用 qqbot:c2c:用户OpenID、qqbot:group:群OpenID 或 qqbot:channel:频道ID；独立 QQ 服务填写已绑定别名，例如 owner。'));

        self::heading($form, '微信通知');
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('wechat_enabled', array('0' => '关闭', '1' => '开启'), '0', '微信评论通知'));
        self::text($form, 'wechat_channel', '微信通道名称', '腾讯微信插件通常为 openclaw-weixin；使用其他通道插件时按实际名称填写。', 'openclaw-weixin');
        self::text($form, 'wechat_account', '微信账号 ID', '多微信账号建议明确填写 OpenClaw 的 accountId。');
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Textarea('wechat_targets', null, '', '微信接收目标', '每行一个，最多 20 个。腾讯微信插件使用 xxx@im.wechat 形式的用户 ID，不能填微信号或昵称。需先私聊机器人建立会话，主动发送受微信平台会话有效期限制。'));

        self::heading($form, '通知规则');
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('include_links', array('1' => '包含链接', '0' => '省略链接'), '1', '通知中的链接', 'QQ 未报备消息域名时可选择省略链接；评论内容里的链接也会省略。'));
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('notify_owner', array('0' => '跳过作者自己的评论', '1' => '同时通知作者自己的评论'), '0', '作者回复'));

        $test = new Typecho_Widget_Helper_Layout('div', array('id' => 'comment-notify-test', 'data-url' => self::e(Typecho_Widget::widget('Widget_Security')->getIndex('action/comment-notify?do=test'))));
        $test->html('<h3>测试通知</h3><p>使用当前填写的配置测试所选通道，可在开启通知前验证。测试不会保存设置。</p>'
            . '<p><button type="button" class="btn" data-kind="qq">测试 QQ 通知</button> '
            . '<button type="button" class="btn" data-kind="wechat">测试微信通知</button></p>'
            . '<p id="comment-notify-result" role="status" aria-live="polite" hidden></p>');
        $form->addItem($test);
        $script = new Typecho_Widget_Helper_Layout('script', array('defer' => 'defer', 'src' => self::e(Typecho_Common::url('CommentNotify/assets/settings.js?v=1.0.0', Typecho_Widget::widget('Widget_Options')->pluginUrl))));
        $script->setClose(false);
        $form->addItem($script);
    }

    private static function heading($form, $title)
    {
        $heading = new Typecho_Widget_Helper_Layout('h3');
        $heading->html(self::e($title));
        $form->addItem($heading);
    }

    private static function text($form, $key, $label, $description, $default = '')
    {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text($key, null, $default, $label, $description));
    }

    private static function secret($form, $key, $label, $description)
    {
        $field = new CommentNotify_Secret($key, null, '', $label, $description);
        $field->input->setAttribute('autocomplete', 'new-password');
        $form->addInput($field);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /** Never reuse a saved credential for a newly entered destination. */
    public static function mergeSecrets(array $input): array
    {
        $settings = CommentNotify_Transport::normalize($input);
        $saved = CommentNotify_Service::settings();
        foreach (array('gateway' => 'gateway_url', 'qq_service' => 'qq_service_url') as $prefix => $urlKey) {
            $key = $prefix . '_token';
            if ($settings[$key] === '') {
                if ($settings[$urlKey] === $saved[$urlKey]) {
                    $settings[$key] = $saved[$key];
                } elseif ($settings[$urlKey] !== '' && $saved[$key] !== '') {
                    throw new InvalidArgumentException('更换机器人地址时，请重新填写该服务的访问令牌。');
                }
            }
        }
        return $settings;
    }

    public static function configHandle($settings, $isInit)
    {
        try {
            $settings = CommentNotify_Transport::validate(self::mergeSecrets($settings));
        } catch (InvalidArgumentException $e) {
            throw new Typecho_Widget_Exception($e->getMessage(), 400);
        }
        Typecho_Widget::widget('Widget_Plugins_Edit')->configPlugin('CommentNotify', $settings);
        try {
            // Typecho does not invalidate this widget cache after configPlugin().
            // Shutdown processing in the same request must see the saved switches.
            Typecho_Widget::widget('Widget_Options')->plugin('CommentNotify')->setDefault($settings, true);
        } catch (Typecho_Plugin_Exception $e) {
            // On first activation there was no option in the already-loaded widget;
            // all channels are disabled in the initial defaults.
        }
    }

    public static function finishComment($comment)
    {
        try {
            // These two finish hooks run after INSERT. Persist before leaving the
            // request; only moderation needs to wait for Typecho's later UPDATE.
            self::queueComment((int) $comment->coid);
            self::registerFlush();
        } catch (Throwable $e) {
            error_log('[CommentNotify] 无法登记新评论通知。');
        }
        return $comment;
    }

    public static function mark($comment, $editor, $status)
    {
        if ($status === 'approved') {
            // Typecho calls mark BEFORE its UPDATE. Read the committed row at shutdown.
            try {
                self::remember((int) (is_array($comment) ? $comment['coid'] : $editor->coid));
            } catch (Throwable $e) {
                error_log('[CommentNotify] 无法登记评论审核通知。');
            }
        }
    }

    private static function remember($coid)
    {
        if ($coid > 0) {
            self::$comments[$coid] = true;
            self::registerFlush();
        }
    }

    public static function registerFlush()
    {
        $request = Typecho_Request::getInstance();
        if ($request->is('do=test') && preg_match('~/action/comment-notify/?$~', (string) parse_url($request->getRequestUrl(), PHP_URL_PATH))) {
            return;
        }
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function(array(__CLASS__, 'flush'));
        }
    }

    public static function queueComment(int $coid): int
    {
        $db = Typecho_Db::get();
        $query = $db->select()->from('table.comments')->where('coid = ?', $coid)->limit(1);
        $row = $db->fetchRow($db->query($query->prepare($query), Typecho_Db::WRITE));
        $settings = CommentNotify_Service::settings();
        if (!$row || $row['type'] !== 'comment' || $row['status'] !== 'approved'
            || ($settings['notify_owner'] !== '1' && (int) $row['authorId'] > 0 && (int) $row['authorId'] === (int) $row['ownerId'])) {
            return 0;
        }
        // Use the real comment widget to resolve rewritten / paged permalinks.
        $widget = Typecho_Widget::widget('Widget_Abstract_Comments@comment-notify-' . $coid);
        $widget->push($row);
        $title = (string) $widget->title;
        $link = (string) $widget->permalink;
        $message = "【新评论通知】\n编号：" . $coid
            . "\n评论者：" . (string) $row['author']
            . "\n文章标题：《" . $title . "》\n评论内容：" . (string) $row['text']
            . "\n文章链接：" . $link;
        return CommentNotify_Service::enqueue($coid, $message, $settings);
    }

    public static function flush()
    {
        // Plugin deactivation must not deliver a previously registered shutdown task.
        if (!Typecho_Plugin::exists('CommentNotify')) {
            self::$comments = array();
            return;
        }
        $lastError = error_get_last();
        if ($lastError && in_array($lastError['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $comments = array_keys(self::$comments);
        self::$comments = array();
        foreach ($comments as $coid) {
            try {
                self::queueComment($coid);
            } catch (Throwable $e) {
                error_log('[CommentNotify] 评论通知入队失败，请检查数据库及插件设置。');
            }
        }
        try {
            CommentNotify_Service::process(4);
        } catch (Throwable $e) {
            error_log('[CommentNotify] 通知处理失败，请检查数据库及插件设置。');
        }
    }

    public static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Typecho fills saved values after config(); keep secrets out of rendered HTML. */
class CommentNotify_Secret extends Typecho_Widget_Helper_Form_Element_Password
{
    protected function inputValue($value)
    {
        parent::inputValue('');
    }
}
