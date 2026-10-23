<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once __DIR__ . '/Service.php';

/**
 * 新评论通过官方 QQ 机器人和微信 iLink 通知站长。
 *
 * @package CommentNotify
 * @author jkjoy
 * @version 2.0.0
 * @since 1.2.0
 */
class CommentNotify_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        if (PHP_VERSION_ID < 80000 || !extension_loaded('curl')) {
            throw new Typecho_Plugin_Exception('评论通知需要 PHP 8.0 或更高版本，并启用 cURL 扩展。');
        }
        comment_notify_storage_ensure();
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = [__CLASS__, 'finishComment'];
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = [__CLASS__, 'finishComment'];
        Helper::addAction('comment-notify', 'CommentNotify_Action');
        Helper::removePanel(3, 'CommentNotify/manage.php');
        return '评论通知已启用，请在设置中配置 QQ 机器人或扫码连接微信。';
    }

    public static function deactivate()
    {
        Helper::removeAction('comment-notify');
        Helper::removePanel(3, 'CommentNotify/manage.php');
        return '评论通知已停用。';
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $config = comment_notify_config();
        $status = comment_notify_status();
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Hidden('protocol_version', null, '2'));
        self::html($form, '<p>新评论提交后，直接通过 QQ 官方机器人或微信通知站长。配置后请先保存，再进行绑定或测试。'
            . '如果 Enhancement 已开启 QQ 评论通知，请关闭其中的同类通知，避免重复提醒。</p>');

        self::heading($form, '官方 QQ 机器人');
        self::toggle($form, 'qq_enabled', 'QQ 评论通知', $config['qq_enabled']);
        self::text($form, 'qq_app_id', 'QQ AppID', '在 <a href="https://q.qq.com/" target="_blank" rel="noopener noreferrer">QQ 开放平台</a>创建机器人，填写正式环境 AppID。', $config['qq_app_id']);
        $secret = new CommentNotify_Secret('qq_app_secret', null, '', 'QQ AppSecret', '留空保留已保存的密钥；更换 AppID 时必须重新填写 AppSecret。');
        $secret->input->setAttribute('autocomplete', 'new-password')->setAttribute('maxlength', '256');
        $secret->input->setAttribute('placeholder', $config['qq_app_secret'] !== '' ? '已保存，留空保留' : '填写机器人 AppSecret');
        $form->addInput($secret);
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select('qq_target_type', [
            'c2c' => 'QQ 私聊（用户 OpenID）', 'group' => 'QQ 群（群 OpenID）', 'channel' => 'QQ 频道（子频道 ID）',
        ], $config['qq_target_type'], 'QQ 接收类型'));
        self::text($form, 'qq_target_id', 'QQ 接收 ID', '私聊和群聊可用下方绑定码自动获取 OpenID，不能填写 QQ 号或群号；频道请填写子频道 ID。接收人须先添加机器人或将机器人加入目标群，并具备主动消息权限。', $config['qq_target_id']);

        self::heading($form, '微信通知');
        self::toggle($form, 'weixin_enabled', '微信评论通知', $config['weixin_enabled'], '先在下方扫码登录、同步并绑定接收人，再开启通知。');
        $recipient = new Typecho_Widget_Helper_Form_Element_Text('weixin_user_id', null, $config['weixin_user_id'], '微信接收人', '从微信机器人实际收到的消息中绑定；会话过期后，请给机器人发消息并重新同步。');
        $recipient->input->setAttribute('readonly', 'readonly')->setAttribute('placeholder', '尚未绑定');
        $form->addInput($recipient);

        self::heading($form, '通知规则');
        self::toggle($form, 'notify_pending', '通知待审核的新评论', $config['notify_pending']);
        self::toggle($form, 'notify_approved', '通知直接通过审核的新评论', $config['notify_approved'], '仅在新评论提交时通知，之后审核通过不会再次发送。');
        self::toggle($form, 'notify_admin', '通知登录用户自己发表的评论', $config['notify_admin']);
        self::toggle($form, 'include_link', '附上文章链接', $config['include_link'], 'QQ 可能要求配置消息域名白名单；平台拒绝链接时可关闭此项。');

        $controls = new Typecho_Widget_Helper_Layout('div', [
            'id' => 'comment-notify-settings',
            'data-url' => self::e(Typecho_Widget::widget('Widget_Security')->getIndex('action/comment-notify')),
            'data-status' => self::e(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)),
        ]);
        $controls->html('<h3>QQ Webhook 与接收人绑定</h3>'
            . '<p><label for="comment-notify-webhook">官方事件回调地址</label><br><input type="text" id="comment-notify-webhook" readonly autocomplete="off" spellcheck="false" style="width:100%"></p>'
            . '<ol><li>填写 AppID、AppSecret 和接收类型并保存；尚无接收 ID 时先保持 QQ 通知关闭。</li>'
            . '<li>在 QQ 开放平台的事件订阅中配置回调地址，地址须为公网可访问的 HTTPS。私聊订阅 <code>C2C_MESSAGE_CREATE</code>，群聊订阅 <code>GROUP_AT_MESSAGE_CREATE</code>。</li>'
            . '<li>生成绑定码，在目标私聊中发送绑定命令；群聊须 @机器人发送命令。</li>'
            . '<li>发送后刷新绑定状态，确认接收 ID，再开启 QQ 通知、保存并测试。</li></ol>'
            . '<p id="comment-notify-qq-status"></p>'
            . '<div id="comment-notify-binding" hidden><p><label for="comment-notify-command">一次性绑定命令</label><br><input type="text" id="comment-notify-command" readonly autocomplete="off" spellcheck="false"></p><p id="comment-notify-binding-hint"></p></div>'
            . '<p><button type="button" class="btn" data-action="qq_binding_start">生成绑定码</button> '
            . '<button type="button" class="btn" data-action="qq_binding_cancel" hidden>取消本次绑定</button> '
            . '<button type="button" class="btn" data-action="status">刷新绑定状态</button></p>'
            . '<p id="comment-notify-qq-events" class="description"></p>'
            . '<h3>微信连接</h3><p id="comment-notify-weixin-status"></p>'
            . '<p>获取二维码 → 微信扫码并确认 → 检查登录状态 → 给机器人发送“绑定通知” → 同步接收人 → 开启微信通知并保存。</p>'
            . '<div id="comment-notify-qr-section" hidden><div id="comment-notify-qr" aria-live="polite"></div><p>二维码在浏览器本地生成，使用微信扫码后在手机上确认。</p><p><a id="comment-notify-qr-link" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">打开微信官方登录链接</a></p></div>'
            . '<p id="comment-notify-verify" hidden><label for="comment-notify-verify-code">手机微信显示的数字验证码</label><br><input type="text" id="comment-notify-verify-code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" pattern="[0-9]{1,12}"></p>'
            . '<p><button type="button" class="btn" data-action="weixin_start">获取登录二维码</button> '
            . '<button type="button" class="btn" data-action="weixin_check" hidden>检查登录状态</button> '
            . '<button type="button" class="btn" data-action="weixin_sync" hidden>同步微信接收人</button> '
            . '<button type="button" class="btn" data-action="weixin_disconnect" hidden>退出微信登录</button></p>'
            . '<div id="comment-notify-candidates" hidden><p><label for="comment-notify-candidate">选择刚刚发送消息的接收人</label><br><select id="comment-notify-candidate"><option value="">请选择接收人</option></select> '
            . '<button type="button" class="btn" data-action="weixin_bind">绑定选中的接收人</button></p></div>'
            . '<p class="description">退出登录会清除微信凭证、接收人并关闭微信通知。微信会限制主动消息与会话有效期。</p>'
            . '<h3>测试与最近结果</h3><p>测试使用已保存的设置，立即发送一条消息。修改设置后请先点击页面底部的“保存设置”。</p>'
            . '<p><button type="button" class="btn" data-action="test_qq">发送 QQ 测试通知</button> '
            . '<button type="button" class="btn" data-action="test_weixin">发送微信测试通知</button></p>'
            . '<p id="comment-notify-result-qq"></p><p id="comment-notify-result-weixin"></p>'
            . '<p id="comment-notify-message" role="status" aria-live="polite" hidden></p>'
            . '<noscript><p>绑定和测试需要启用 JavaScript；填写设置后仍可使用下方按钮保存。</p></noscript>');
        $form->addItem($controls);
        $base = Typecho_Common::url('CommentNotify/assets/', Typecho_Widget::widget('Widget_Options')->pluginUrl);
        foreach (['vendor/qrcode.js', 'vendor/qrcode_UTF8.js', 'settings.js'] as $file) {
            $script = new Typecho_Widget_Helper_Layout('script', ['defer' => 'defer', 'src' => self::e($base . $file . '?v=2.0.0')]);
            $script->setClose(false);
            $form->addItem($script);
        }
    }

    public static function configHandle($settings, $isInit)
    {
        $validated = comment_notify_validate($settings, comment_notify_config());
        if ($validated['errors']) {
            throw new Typecho_Widget_Exception(implode(' ', $validated['errors']), 400);
        }
        comment_notify_save_config($validated['config']);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    public static function finishComment($comment)
    {
        try {
            comment_notify_comment_created((int)$comment->coid);
        } catch (Throwable $e) {
            error_log('[CommentNotify] 无法准备评论通知。');
        }
        return $comment;
    }

    private static function heading($form, string $title): void
    {
        self::html($form, '<h3>' . self::e($title) . '</h3>');
    }

    private static function html($form, string $html): void
    {
        $item = new Typecho_Widget_Helper_Layout('div');
        $item->html($html);
        $form->addItem($item);
    }

    private static function toggle($form, string $key, string $label, bool $value, string $description = ''): void
    {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Select($key, ['0' => '关闭', '1' => '开启'], $value ? '1' : '0', $label, $description));
    }

    private static function text($form, string $key, string $label, string $description, string $value): void
    {
        $form->addInput(new Typecho_Widget_Helper_Form_Element_Text($key, null, $value, $label, $description));
    }

    public static function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Typecho fills saved values after config(); never render the stored secret. */
class CommentNotify_Secret extends Typecho_Widget_Helper_Form_Element_Password
{
    protected function inputValue($value)
    {
        parent::inputValue('');
    }
}
