(function () {
    'use strict';

    function install() {
        var root = document.getElementById('comment-notify-settings');
        var form = root && root.closest('form');
        if (!form) return;
        var buttons = root.querySelectorAll('button[data-action]');
        var message = document.getElementById('comment-notify-message');
        var names = ['qq_enabled', 'qq_app_id', 'qq_app_secret', 'qq_target_type', 'qq_target_id', 'weixin_enabled', 'notify_pending', 'notify_approved', 'notify_admin', 'include_link'];
        var busy = false;
        var current = {};
        var qrUrl = '';

        function node(id) { return document.getElementById('comment-notify-' + id); }
        function field(name) { return form.elements.namedItem(name); }
        function snapshot() {
            return JSON.stringify(names.map(function (name) { return field(name).value; }));
        }
        var saved = snapshot();
        function dirty() { return snapshot() !== saved; }
        function showMessage(text, ok) {
            message.hidden = false;
            message.className = 'message ' + (ok ? 'success' : 'error');
            message.textContent = text;
        }
        function time(value) {
            var number = Number(value);
            return number > 0 ? new Date(number * 1000).toLocaleString('zh-CN', { hour12: false }) : '尚无记录';
        }
        function action(name, visible, enabled) {
            var button = root.querySelector('[data-action="' + name + '"]');
            button.hidden = !visible;
            button.disabled = busy || !enabled;
        }
        function drawQr(value) {
            var url;
            try {
                url = new URL(value);
                if (url.protocol !== 'https:' || url.port || url.username || url.password
                    || !/(^|\.)weixin\.qq\.com$/.test(url.hostname)) throw new Error('Invalid QR URL');
            } catch (error) {
                node('qr-section').hidden = true;
                return;
            }
            node('qr-section').hidden = false;
            node('qr-link').href = url.href;
            if (qrUrl === value) return;
            qrUrl = value;
            var container = node('qr');
            container.replaceChildren();
            try {
                var qr = window.qrcode(0, 'M');
                qr.addData(value, 'Byte');
                qr.make();
                var count = qr.getModuleCount();
                var canvas = document.createElement('canvas');
                var size = 4;
                canvas.width = canvas.height = (count + 8) * size;
                canvas.style.cssText = 'display:block;width:100%;max-width:320px;height:auto';
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', '微信登录二维码');
                var context = canvas.getContext('2d');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#000';
                for (var row = 0; row < count; row++) {
                    for (var col = 0; col < count; col++) {
                        if (qr.isDark(row, col)) context.fillRect((col + 4) * size, (row + 4) * size, size, size);
                    }
                }
                container.appendChild(canvas);
            } catch (error) {
                container.textContent = '无法生成二维码，请重新获取或打开下方微信官方登录链接。';
                qrUrl = '';
            }
        }
        function render(status) {
            current = status || {};
            var qq = current.qq || {};
            var weixin = current.weixin || {};
            var binding = qq.binding || {};
            var clean = !dirty();
            node('webhook').value = qq.url || '';
            var targetType = qq.target_type || field('qq_target_type').value;
            node('qq-status').textContent = !qq.available ? 'PHP 未启用 sodium，无法验证 QQ Webhook；手动填写接收 ID 仍可发送通知。'
                : !qq.configured ? '请先保存 QQ AppID 和 AppSecret，再验证回调并生成绑定码。'
                : targetType === 'channel' ? 'QQ 频道请手动填写子频道 ID，自动绑定支持私聊和 QQ 群。'
                : '已保存 QQ 机器人凭证，可配置官方事件回调并生成一次性绑定码。';
            node('binding').hidden = !binding.code;
            node('command').value = binding.code ? '绑定通知 ' + binding.code : '';
            node('binding-hint').textContent = '用于' + (binding.target_type === 'group' ? '目标 QQ 群，须 @机器人发送' : '当前 QQ 私聊用户') + '；有效期至 ' + time(binding.expires_at) + '，匹配成功后立即失效。';
            node('qq-events').textContent = '最近回调验证：' + time(qq.last_challenge_at) + '；最近消息事件：' + time(qq.last_event_at) + '。绑定只更新接收 ID，请在启用通知并保存后测试。';
            action('qq_binding_start', true, qq.available && qq.configured && targetType !== 'channel');
            action('qq_binding_cancel', !!binding.code, !!binding.code);
            action('status', true, true);
            root.querySelector('[data-action="qq_binding_start"]').textContent = binding.code ? '重新生成绑定码' : '生成绑定码';

            node('weixin-status').textContent = '登录状态：' + (weixin.expired ? '登录已失效，请重新扫码'
                : weixin.logged_in ? '已登录；接收人' + (weixin.user_id ? '已绑定；会话' + (weixin.has_context ? '已就绪' : '尚未同步') : '尚未绑定')
                : '尚未登录') + '。';
            if (weixin.qrcode_url) drawQr(weixin.qrcode_url);
            else { node('qr-section').hidden = true; qrUrl = ''; }
            node('verify').hidden = !weixin.need_verifycode;
            action('weixin_start', true, true);
            action('weixin_check', !!weixin.login_pending, !!weixin.login_pending);
            action('weixin_sync', !!weixin.logged_in, !weixin.expired);
            action('weixin_disconnect', !!weixin.logged_in, true);
            var candidate = node('candidate');
            var selected = candidate.value;
            candidate.replaceChildren(new Option('请选择接收人', ''));
            (weixin.candidates || []).forEach(function (item) {
                if (typeof item.user_id === 'string') candidate.add(new Option(item.user_id, item.user_id));
            });
            candidate.value = selected;
            node('candidates').hidden = candidate.options.length < 2 || !!weixin.user_id;
            action('weixin_bind', true, candidate.options.length > 1 && !weixin.user_id);
            if (clean) {
                if (typeof qq.target_type === 'string') field('qq_target_type').value = qq.target_type;
                if (typeof qq.target_id === 'string') field('qq_target_id').value = qq.target_id;
                if (typeof qq.enabled === 'boolean') field('qq_enabled').value = qq.enabled ? '1' : '0';
                if (typeof weixin.enabled === 'boolean') field('weixin_enabled').value = weixin.enabled ? '1' : '0';
                field('weixin_user_id').value = weixin.user_id || '';
                saved = snapshot();
            }
            ['qq', 'weixin'].forEach(function (channel) {
                var result = (current.results || {})[channel] || {};
                node('result-' + channel).textContent = (channel === 'qq' ? 'QQ' : '微信') + (result.time
                    ? '最近结果：' + (result.ok ? '接口已受理' : '失败') + ' · ' + time(result.time) + ' · ' + (result.message || '')
                    : '尚无发送记录。');
                action('test_' + channel, true, true);
            });
        }
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (busy) return;
                if (dirty()) {
                    showMessage('设置尚未保存，请先点击页面底部的“保存设置”，再进行绑定或测试。', false);
                    return;
                }
                var operation = button.getAttribute('data-action');
                var body = new URLSearchParams({ do: operation });
                if (operation === 'weixin_check') {
                    var code = node('verify-code').value.trim();
                    if (current.weixin && current.weixin.need_verifycode && !/^\d{1,12}$/.test(code)) {
                        showMessage('请输入手机微信显示的数字验证码。', false);
                        node('verify-code').focus();
                        return;
                    }
                    body.set('verify_code', code);
                }
                if (operation === 'weixin_bind') {
                    if (!node('candidate').value) {
                        showMessage('请先选择实际收到过消息的微信接收人。', false);
                        return;
                    }
                    body.set('weixin_user_id', node('candidate').value);
                }
                busy = true;
                buttons.forEach(function (item) { item.disabled = true; });
                message.hidden = false;
                message.className = 'message notice';
                message.textContent = operation.indexOf('test_') === 0 ? '正在发送测试通知…' : '正在处理，请稍候…';
                fetch(root.getAttribute('data-url'), {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
                    body: body.toString()
                }).then(function (response) { return response.json(); }).then(function (result) {
                    if (result.status && typeof result.status === 'object') render(result.status);
                    if (operation === 'weixin_check' && result.ok) node('verify-code').value = '';
                    showMessage(result.message || (result.ok ? '操作完成。' : '操作失败，请检查已保存的设置。'), !!result.ok);
                }).catch(function () {
                    showMessage(operation.indexOf('test_') === 0
                        ? '无法确认发送结果，请先检查 QQ / 微信中的消息，再决定是否重试。'
                        : '无法确认操作结果，请刷新状态后重试。', false);
                }).finally(function () {
                    busy = false;
                    render(current);
                });
            });
        });
        try { render(JSON.parse(root.getAttribute('data-status') || '{}')); }
        catch (error) { showMessage('无法读取连接状态，请刷新页面。', false); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
    else install();
}());
