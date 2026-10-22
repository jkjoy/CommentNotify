(function () {
    'use strict';
    function install() {
        var card = document.getElementById('comment-notify-test');
        if (!card) return;
        var form = card.closest('form');
        var status = document.getElementById('comment-notify-result');
        var buttons = card.querySelectorAll('button[data-kind]');
        if (!form || !status) return;
        function field(name) { return form.elements.namedItem(name); }
        function visible(name, show) {
            var input = field(name);
            var item = input && input.closest('.typecho-option');
            if (item) item.hidden = !show;
        }
        function refresh() {
            var service = field('qq_provider').value === 'service';
            ['qq_channel', 'qq_account'].forEach(function (name) { visible(name, !service); });
            ['qq_service_url', 'qq_service_token'].forEach(function (name) { visible(name, service); });
        }
        field('qq_provider').addEventListener('change', refresh);
        refresh();
        var busy = false;
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (busy) return;
                busy = true;
                buttons.forEach(function (item) { item.disabled = true; });
                status.hidden = false;
                status.className = 'message notice';
                status.textContent = '正在发送测试通知…';
                var body = new URLSearchParams();
                var keys = ['gateway_url', 'gateway_token', 'qq_enabled', 'qq_provider', 'qq_channel', 'qq_account', 'qq_targets', 'wechat_enabled', 'wechat_channel', 'wechat_account', 'wechat_targets', 'qq_service_url', 'qq_service_token', 'include_links', 'notify_owner'];
                keys.forEach(function (key) { body.set('settings[' + key + ']', field(key).value); });
                body.set('kind', button.getAttribute('data-kind'));
                fetch(card.getAttribute('data-url'), {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
                    body: body.toString()
                }).then(function (response) { return response.json(); }).then(function (result) {
                    status.className = 'message ' + (result.ok ? 'success' : 'error');
                    status.textContent = result.message || '请确认机器人服务与通道配置。';
                    (result.results || []).forEach(function (item) {
                        var line = document.createElement('div');
                        line.textContent = item.target + '：' + item.message;
                        status.appendChild(line);
                    });
                }).catch(function () {
                    status.className = 'message error';
                    status.textContent = '无法确认发送结果，请先检查机器人消息和通知服务，再决定是否重试。';
                }).finally(function () {
                    busy = false;
                    buttons.forEach(function (item) { item.disabled = false; });
                });
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
    else install();
}());
