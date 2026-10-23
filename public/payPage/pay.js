(function () {
    'use strict';
    var $ = function (id) { return document.getElementById(id); };
    var orderId = new URLSearchParams(location.search).get('orderId');
    var base = new URL('../', location.href);
    var storageKey = 'vmq:qr:' + orderId;
    var order = null, kind = 'normal', selectedId = '', rotation = null, poll = null, done = false;
    var deadline = 0, clock = null, lastOptions = '', redirectAttempts = 0;
    var mobile = window.matchMedia('(max-width:767px)');

    function readChoice() {
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (saved) { kind = saved.kind === 'reward' ? 'reward' : 'normal'; selectedId = String(saved.id || ''); }
        } catch (error) { /* Selection storage is optional. */ }
    }
    function saveChoice() {
        try { localStorage.setItem(storageKey, JSON.stringify({kind: kind, id: selectedId})); } catch (error) {}
    }
    function api(action) {
        return fetch(new URL('index.php/' + action, base), {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({orderId: orderId}).toString(), cache: 'no-store'
        }).then(function (response) {
            if (!response.ok) throw new Error('网络连接异常，正在重试');
            return response.json();
        });
    }
    function stopRotation() { clearInterval(rotation); rotation = null; $('rotation').checked = false; }
    function options() {
        return order && Array.isArray(order.qrOptions) ? order.qrOptions.filter(function (option) {
            return Number(order.payType) === 1 || option.qrKind !== 'reward';
        }) : [];
    }
    function group() { return options().filter(function (option) { return option.qrKind === kind; }); }
    function emphasis(text, className) {
        var node = document.createElement('strong');
        node.className = className; node.textContent = text; return node;
    }
    function selectKind(value) {
        if (done || !options().some(function (option) { return option.qrKind === value; })) return;
        stopRotation(); kind = value; selectedId = String(group()[0].id); render();
    }
    function renderPaymentHelp(all, list) {
        var note = $('restricted-note'), action = $('switch-kind');
        action.hidden = true; action.onclick = null;
        var otherKind = kind === 'normal' ? 'reward' : 'normal';
        var hasOther = Number(order.payType) === 1 && all.some(function (option) { return option.qrKind === otherKind; });
        if (hasOther) {
            note.replaceChildren(kind === 'normal' ? '收款码' : '赞赏码', emphasis('支付受限或无法付款', 'caution'), '？');
            action.textContent = kind === 'normal' ? '切换赞赏码' : '返回收款码';
            action.hidden = false; action.onclick = function () { selectKind(otherKind); };
        } else if (list.length > 1) {
            note.replaceChildren(emphasis('支付受限或无法付款', 'caution'), '？可切换其他' + (kind === 'reward' ? '赞赏码' : '收款码') + '。');
        } else {
            note.replaceChildren('如', emphasis('无法付款', 'caution'), '，请联系商家。');
        }
    }
    function imageUrl(option) {
        return option.imageUrl ? new URL(option.imageUrl, base).href :
            new URL('index.php/enQrcode?url=' + encodeURIComponent(option.payUrl), base).href;
    }
    function render() {
        if (!order || done) return;
        var all = options();
        if (!selectedId && all.length) kind = all[0].qrKind;
        if (!all.some(function (option) { return option.qrKind === kind; }) && all.length) {
            kind = all[0].qrKind;
        }
        $('normal').hidden = !all.some(function (option) { return option.qrKind === 'normal'; });
        $('reward').hidden = Number(order.payType) !== 1 || !all.some(function (option) { return option.qrKind === 'reward'; });
        $('normal').setAttribute('aria-pressed', String(kind === 'normal'));
        $('reward').setAttribute('aria-pressed', String(kind === 'reward'));
        $('kinds').hidden = $('normal').hidden || $('reward').hidden;
        var list = group(), index = list.findIndex(function (option) { return String(option.id) === selectedId; });
        if (index < 0) index = 0;
        var size = mobile.matches ? 1 : 2;
        var start = Math.floor(index / size) * size;
        selectedId = list[index] ? String(list[index].id) : '';
        saveChoice();
        var visible = list.slice(start, start + size);
        $('codes').replaceChildren();
        $('codes').classList.toggle('single', visible.length === 1);
        visible.forEach(function (option) {
            var figure = document.createElement('figure'); figure.className = 'payment-code';
            var area = document.createElement('div'); area.className = 'image-area';
            var img = document.createElement('img'); img.alt = option.name || '付款二维码'; img.width = 256; img.height = 256; img.src = imageUrl(option);
            var imageError = document.createElement('p'); imageError.className = 'image-error'; imageError.hidden = true;
            img.onerror = function () { imageError.hidden = false; imageError.textContent = '图片加载失败，请切换其他收款码或刷新页面'; save.hidden = true; };
            area.appendChild(img);
            var caption = document.createElement('figcaption'); caption.textContent = option.name || (option.qrKind === 'reward' ? '微信赞赏码' : '收款码');
            var hint = document.createElement('p'); hint.className = 'amount-hint';
            var hintAmount = document.createElement('span');
            hintAmount.append(option.amountMode === 'fixed' ? '请核对付款金额 ' :
                (option.qrKind === 'reward' ? '请在赞赏页面输入 ' : '请在付款时输入 '), emphasis('¥' + money(order.reallyPrice), 'critical'));
            hint.append(hintAmount);
            var save = document.createElement('a');
            save.className = 'save-code';
            save.href = img.src; save.download = 'payment-' + orderId + '-' + option.id + (option.imageUrl ? '.' + option.imageUrl.split('.').pop() : '.png');
            save.innerHTML = '<i class="layui-icon layui-icon-download-circle" aria-hidden="true"></i>保存二维码';
            save.addEventListener('click', stopRotation);
            figure.append(area, caption, hint, imageError, save); $('codes').appendChild(figure);
        });
        var pages = Math.ceil(list.length / size);
        $('position').textContent = list.length ? (start + 1) + (visible.length > 1 ? '–' + (start + visible.length) : '') + ' / ' + list.length : '';
        $('previous').disabled = pages <= 1; $('next').disabled = pages <= 1;
        $('rotation').disabled = pages <= 1;
        $('switcher').hidden = pages <= 1;
        $('rotation-control').hidden = pages <= 1;
        renderPaymentHelp(all, list);
        if (pages <= 1) stopRotation();
        $('next').innerHTML = '<i class="layui-icon layui-icon-refresh" aria-hidden="true"></i> ' + (mobile.matches ? '换一个码' : '换一组码');
        $('status').textContent = list.length ? '' : '暂无可用收款码，请联系商家。';
    }
    function change(direction, manual) {
        if (done) return;
        if (manual) stopRotation();
        var list = group(), size = mobile.matches ? 1 : 2;
        if (!list.length) return;
        var index = Math.max(0, list.findIndex(function (option) { return String(option.id) === selectedId; }));
        var pages = Math.ceil(list.length / size);
        var page = (Math.floor(index / size) + direction + pages) % pages;
        selectedId = String(list[page * size].id); render();
    }
    function money(value) { return Number(value).toFixed(2); }
    function finish(text) {
        done = true; stopRotation(); clearTimeout(poll); clearInterval(clock);
        $('payment').hidden = true; $('codes').replaceChildren(); $('countdown').textContent = '';
        $('status').textContent = text;
        $('amount-warning').hidden = true; $('amount-note').hidden = true;
        $('rotation-control').hidden = true;
        $('status').className = text === '支付成功' ? 'is-complete' : 'is-expired';
    }
    function tick() {
        if (done) return;
        var seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (seconds === 0) {
            // A final server read resolves payment arriving at the expiry boundary.
            $('payment').hidden = true; stopRotation(); $('countdown').textContent = '正在确认订单状态';
            return;
        }
        var time = String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
        $('countdown').replaceChildren('支付剩余时间', emphasis(time, 'countdown-value'));
    }
    function paymentReturn() {
        api('checkOrder').then(function (response) {
            if (Number(response.code) === 1 && typeof response.data === 'string' && response.data) {
                var url = new URL(response.data, base);
                if (url.protocol === 'https:' || url.protocol === 'http:') {
                    $('return').href = url.href; $('return').hidden = false;
                    location.assign(url.href);
                }
            }
        }).catch(function () {
            if (++redirectAttempts < 3) setTimeout(paymentReturn, 2000);
        });
    }
    function refresh() {
        if (done) return;
        api('getOrder').then(function (response) {
            if (Number(response.code) !== 1) {
                finish(response.msg || '订单不存在'); return;
            }
            order = response.data;
            document.body.dataset.provider = Number(order.payType) === 1 ? 'wechat' : 'alipay';
            $('provider').textContent = Number(order.payType) === 1 ? '微信付款' : '支付宝付款';
            $('provider-icon').src = Number(order.payType) === 1 ? 'weixin.jpg' : 'alipay.jpg';
            $('provider-icon').alt = $('provider').textContent;
            $('provider-icon').hidden = false; $('provider').hidden = true;
            $('amount').textContent = '¥' + money(order.reallyPrice);
            $('amount-note').textContent = '切换收款码后，应付金额不变'; $('amount-note').hidden = false;
            $('amount-warning').replaceChildren('输入金额必须与 ', emphasis('¥' + money(order.reallyPrice), 'critical'), ' ', emphasis('完全一致', 'critical'));
            $('amount-warning').hidden = false;
            $('merchant').textContent = order.payId; $('original').textContent = '¥' + money(order.price); $('details').hidden = false;
            if ([1, 2].includes(Number(order.state))) {
                finish('支付成功'); paymentReturn(); return;
            }
            if (Number(order.state) === -1 || Number(order.remainingSeconds) === 0) {
                finish('订单已过期，请返回重新下单。'); return;
            }
            var remaining = order.remainingSeconds == null ?
                Number(order.date) + Number(order.timeOut) * 60 - Date.now() / 1000 : Number(order.remainingSeconds);
            if (remaining <= 0) { finish('订单已过期，请返回重新下单。'); return; }
            deadline = Date.now() + remaining * 1000;
            $('payment').hidden = false; $('status').classList.remove('is-error');
            var fingerprint = JSON.stringify(order.qrOptions);
            if (fingerprint !== lastOptions || !$('codes').children.length) {
                lastOptions = fingerprint; render();
            } else {
                $('status').textContent = '';
            }
            tick();
        }).catch(function (error) {
            $('status').textContent = error.message || '网络连接异常，正在重试';
            $('status').classList.add('is-error');
        }).finally(function () { if (!done) poll = setTimeout(refresh, 2000); });
    }
    $('next').onclick = function () { change(1, true); };
    $('previous').onclick = function () { change(-1, true); };
    ['normal', 'reward'].forEach(function (value) {
        $(value).onclick = function () { selectKind(value); };
    });
    $('rotation').onchange = function () {
        clearInterval(rotation);
        if (this.checked && !done) rotation = setInterval(function () { change(1, false); }, 30000);
    };
    document.addEventListener('visibilitychange', function () { if (document.hidden) stopRotation(); });
    mobile.addEventListener('change', function () { stopRotation(); render(); });
    window.addEventListener('pagehide', function () { stopRotation(); clearTimeout(poll); clearInterval(clock); });
    if (!orderId) { finish('订单号不能为空'); return; }
    readChoice(); refresh(); clock = setInterval(tick, 1000);
}());
