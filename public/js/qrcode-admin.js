(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.QrAdmin = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var API_ROOT = 'index.php/api/qrcode';
    var clientDecodeChain = Promise.resolve();

    function isValidAmount(value) {
        var text = String(value == null ? '' : value).trim();
        return /^\d{1,8}(?:\.\d{1,2})?$/.test(text) && Number(text) > 0;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function runPool(items, limit, worker) {
        var results = new Array(items.length);
        var nextIndex = 0;
        var workerCount = Math.min(Math.max(1, limit), items.length);

        function work() {
            var index = nextIndex++;
            if (index >= items.length) {
                return Promise.resolve();
            }

            return Promise.resolve(worker(items[index], index))
                .then(function (result) {
                    results[index] = result;
                })
                .then(work);
        }

        var workers = [];
        for (var i = 0; i < workerCount; i++) {
            workers.push(work());
        }

        return Promise.all(workers).then(function () {
            return results;
        });
    }

    function fileIdentity(file) {
        return [file.name, Number(file.size) || 0, Number(file.lastModified) || 0].join('\u0000');
    }

    function partitionImageFiles(files) {
        var accepted = [];
        var rejected = [];
        Array.prototype.forEach.call(files || [], function (file) {
            var type = String(file.type || '').toLowerCase();
            var name = String(file.name || '');
            if (type.indexOf('image/') === 0 || /\.(?:jpe?g|png|webp)$/i.test(name)) {
                accepted.push(file);
            } else {
                rejected.push(file);
            }
        });
        return {accepted: accepted, rejected: rejected};
    }

    function appendFiles(items, files, createItem, maxItems) {
        var result = items.slice();
        var existing = {};
        var added = [];
        var ignored = 0;
        var rejected = 0;
        result.forEach(function (item) { existing[fileIdentity(item.file)] = true; });

        Array.prototype.forEach.call(files || [], function (file, index) {
            var key = fileIdentity(file);
            if (existing[key]) {
                ignored++;
                return;
            }
            if (result.length >= maxItems) {
                rejected++;
                return;
            }
            var item = createItem(file, index);
            existing[key] = true;
            result.push(item);
            added.push(item);
        });

        return {items: result, added: added, ignored: ignored, rejected: rejected};
    }

    function createRecognitionQueue(worker, concurrency, onChange) {
        var pending = [];
        var active = 0;
        var records = {};
        var idleWaiters = [];
        concurrency = Math.min(2, Math.max(1, Number(concurrency) || 1));

        function settleIdle() {
            if (active || pending.length) { return; }
            idleWaiters.splice(0).forEach(function (resolve) { resolve(); });
        }

        function pump() {
            while (active < concurrency && pending.length) {
                (function (id) {
                    var item = records[id];
                    if (!item || item.cancelled) { return; }
                    active++;
                    item.status = 'processing';
                    onChange(item);
                    Promise.resolve().then(function () {
                        return worker(item);
                    }).then(function (result) {
                        if (records[id] === item && !item.cancelled) {
                            Object.keys(result || {}).forEach(function (key) { item[key] = result[key]; });
                            item.status = 'ready';
                            item.error = '';
                            onChange(item);
                        }
                    }, function (error) {
                        if (records[id] === item && !item.cancelled) {
                            item.status = 'error';
                            item.error = error.message;
                            onChange(item);
                        }
                    }).then(function () {
                        active--;
                        pump();
                        settleIdle();
                    });
                }(pending.shift()));
            }
            settleIdle();
        }

        function enqueue(items) {
            items.forEach(function (item) {
                records[item.id] = item;
                pending.push(item.id);
            });
            pump();
        }

        function remove(id) {
            if (records[id]) {
                records[id].cancelled = true;
                delete records[id];
            }
            pending = pending.filter(function (pendingId) { return pendingId !== id; });
            settleIdle();
        }

        function whenIdle() {
            if (!active && !pending.length) { return Promise.resolve(); }
            return new Promise(function (resolve) { idleWaiters.push(resolve); });
        }

        return {enqueue: enqueue, remove: remove, whenIdle: whenIdle};
    }

    function bindDropTarget(root, onFiles, onState) {
        var depth = 0;

        function isFileDrag(event) {
            var types = event.dataTransfer && event.dataTransfer.types;
            return !types || Array.prototype.indexOf.call(types, 'Files') !== -1;
        }
        function enter(event) {
            if (!isFileDrag(event)) { return; }
            event.preventDefault();
            depth++;
            onState(true);
        }
        function over(event) {
            if (!isFileDrag(event)) { return; }
            event.preventDefault();
        }
        function leave(event) {
            if (!isFileDrag(event)) { return; }
            event.preventDefault();
            depth = Math.max(0, depth - 1);
            if (!depth) { onState(false); }
        }
        function drop(event) {
            if (!isFileDrag(event)) { return; }
            event.preventDefault();
            depth = 0;
            onState(false);
            onFiles(Array.prototype.slice.call((event.dataTransfer && event.dataTransfer.files) || []));
        }

        root.addEventListener('dragenter', enter);
        root.addEventListener('dragover', over);
        root.addEventListener('dragleave', leave);
        root.addEventListener('drop', drop);

        return function () {
            root.removeEventListener('dragenter', enter);
            root.removeEventListener('dragover', over);
            root.removeEventListener('dragleave', leave);
            root.removeEventListener('drop', drop);
            depth = 0;
            onState(false);
        };
    }

    function buildConflictDecisions(preview, mode, reviewChoice) {
        if (!preview || !Array.isArray(preview.items) || !preview.items.length ||
            ['replace_all', 'individual', 'skip_all'].indexOf(mode) === -1) {
            return Promise.reject(new Error('二维码冲突数据无效'));
        }
        if (mode === 'individual' && typeof reviewChoice !== 'function') {
            return Promise.reject(new Error('逐个确认回调无效'));
        }

        var seenIds = {};
        var groups = {};
        var priceOrder = [];
        var decisions = {};
        var validPrice = /^\d{1,8}\.\d{2}$/;

        for (var i = 0; i < preview.items.length; i++) {
            var item = preview.items[i];
            if (!item || typeof item.client_id !== 'string' || !item.client_id || seenIds[item.client_id] ||
                typeof item.pay_url !== 'string' || !item.pay_url || !validPrice.test(String(item.price || '')) ||
                (item.existing_id !== null && item.existing_id !== undefined && !/^[1-9]\d*$/.test(String(item.existing_id)))) {
                return Promise.reject(new Error('二维码冲突项目无效'));
            }
            seenIds[item.client_id] = true;
            if (!groups[item.price]) {
                groups[item.price] = [];
                priceOrder.push(item.price);
            }
            groups[item.price].push(item);
            decisions[item.client_id] = {client_id: item.client_id, action: 'skip'};
        }

        function existingIdFor(group) {
            var existingId = null;
            group.forEach(function (item) {
                if (item.existing_id !== null && item.existing_id !== undefined) {
                    if (existingId !== null && existingId !== String(item.existing_id)) {
                        throw new Error('同金额二维码冲突目标不一致');
                    }
                    existingId = String(item.existing_id);
                }
            });
            return existingId;
        }

        function chooseWinner(group, existingId) {
            if (mode === 'replace_all') {
                return Promise.resolve(group[group.length - 1]);
            }
            if (mode === 'skip_all') {
                return Promise.resolve(existingId === null ? group[0] : null);
            }

            var winner = group[0];
            var chain = Promise.resolve();
            group.slice(1).forEach(function (candidate) {
                chain = chain.then(function () {
                    return Promise.resolve(reviewChoice(winner, candidate, {
                        kind: 'batch',
                        price: candidate.price,
                        client_ids: group.map(function (entry) { return entry.client_id; })
                    })).then(function (choice) {
                        if (choice === 'replace') {
                            winner = candidate;
                        } else if (choice !== 'skip') {
                            throw new Error('逐个确认结果无效');
                        }
                    });
                });
            });
            return chain.then(function () {
                if (existingId === null) { return winner; }
                return Promise.resolve(reviewChoice(null, winner, {
                    kind: 'database', price: winner.price, existing_id: existingId
                })).then(function (choice) {
                    if (choice === 'replace') { return winner; }
                    if (choice === 'skip') { return null; }
                    throw new Error('逐个确认结果无效');
                });
            });
        }

        var sequence = Promise.resolve();
        priceOrder.forEach(function (price) {
            sequence = sequence.then(function () {
                var group = groups[price];
                var existingId;
                try {
                    existingId = existingIdFor(group);
                } catch (error) {
                    return Promise.reject(error);
                }
                return chooseWinner(group, existingId).then(function (winner) {
                    if (!winner) { return; }
                    var decision = {
                        client_id: winner.client_id,
                        action: existingId === null ? 'insert' : 'replace'
                    };
                    if (existingId !== null) { decision.target_id = existingId; }
                    decisions[winner.client_id] = decision;
                });
            });
        });

        return sequence.then(function () {
            return preview.items.map(function (item) { return decisions[item.client_id]; });
        });
    }

    function getJquery() {
        if (typeof window === 'undefined') {
            return null;
        }
        return window.jQuery || (window.layui && window.layui.$) || null;
    }

    function responseError(response, fallback) {
        var error = new Error((response && response.msg) || fallback);
        error.code = response && Number(response.code);
        error.data = response && response.data;
        return error;
    }

    function request(options) {
        var $ = getJquery();
        if (!$ || !$.ajax) {
            return Promise.reject(new Error('页面请求组件未加载'));
        }

        return new Promise(function (resolve, reject) {
            $.ajax({
                url: options.url,
                type: options.method || 'GET',
                data: options.data,
                processData: options.processData,
                contentType: options.contentType,
                dataType: 'json',
                success: function (response) {
                    if (response && response.code === 200) {
                        resolve(response.data);
                        return;
                    }
                    reject(responseError(response, '请求失败'));
                },
                error: function (xhr) {
                    reject(responseError(
                        xhr && xhr.responseJSON,
                        '请求失败，请检查网络或登录状态'
                    ));
                }
            });
        });
    }

    function readAsDataUrl(file) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () { resolve(reader.result); };
            reader.onerror = function () { reject(new Error('图片预览读取失败')); };
            reader.readAsDataURL(file);
        });
    }

    function decodeWithBarcodeDetector(file) {
        if (typeof window === 'undefined' || !window.BarcodeDetector || !window.createImageBitmap) {
            return Promise.reject(new Error('浏览器原生二维码识别不可用'));
        }

        return window.createImageBitmap(file).then(function (bitmap) {
            var detector = new window.BarcodeDetector({formats: ['qr_code']});
            return detector.detect(bitmap).then(function (results) {
                bitmap.close();
                if (results && results[0] && results[0].rawValue) {
                    return results[0].rawValue;
                }
                throw new Error('浏览器原生识别未发现二维码');
            }, function (error) {
                bitmap.close();
                throw error;
            });
        });
    }

    function decodeWithLlqrcode(file) {
        return readAsDataUrl(file).then(function (dataUrl) {
            return new Promise(function (resolve, reject) {
                if (typeof window === 'undefined' || !window.qrcode) {
                    reject(new Error('浏览器二维码备用识别不可用'));
                    return;
                }

                var objectUrl = URL.createObjectURL(file);
                var settled = false;
                var timer = setTimeout(function () {
                    if (!settled) {
                        settled = true;
                        URL.revokeObjectURL(objectUrl);
                        reject(new Error('浏览器二维码识别超时'));
                    }
                }, 10000);

                window.qrcode.callback = function (message) {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    clearTimeout(timer);
                    URL.revokeObjectURL(objectUrl);
                    if (message) {
                        resolve(message);
                    } else {
                        reject(new Error('未识别到二维码内容'));
                    }
                };

                try {
                    window.qrcode.decode(objectUrl, dataUrl);
                } catch (error) {
                    clearTimeout(timer);
                    URL.revokeObjectURL(objectUrl);
                    reject(error);
                }
            });
        });
    }

    function decodeInBrowser(file) {
        clientDecodeChain = clientDecodeChain.catch(function () {
            // Keep the serialized fallback queue usable after one failed image.
        }).then(function () {
            return decodeWithBarcodeDetector(file).catch(function () {
                return decodeWithLlqrcode(file);
            });
        });

        return clientDecodeChain;
    }

    function analyzeFile(file) {
        var formData = new FormData();
        formData.append('file', file);

        return request({
            url: API_ROOT + '/parse',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).then(function (result) {
            if (result.url) {
                return result;
            }
            return decodeInBrowser(file).then(function (url) {
                result.url = url;
                result.decoder = 'browser';
                return result;
            });
        }, function (serverError) {
            return decodeInBrowser(file).then(function (url) {
                return {
                    url: url,
                    amount: '',
                    amount_status: 'manual',
                    decoder: 'browser',
                    warning: serverError.message
                };
            });
        });
    }

    function analyzeFiles(files, callbacks) {
        callbacks = callbacks || {};
        return runPool(Array.prototype.slice.call(files), 2, function (file, index) {
            if (callbacks.onItemStart) {
                callbacks.onItemStart(file, index);
            }
            return analyzeFile(file).then(function (result) {
                if (callbacks.onItemDone) {
                    callbacks.onItemDone(result, file, index);
                }
                return result;
            }, function (error) {
                if (callbacks.onItemError) {
                    callbacks.onItemError(error, file, index);
                }
                return { error: error };
            });
        });
    }

    function statusText(item) {
        if (item.status === 'processing') { return '识别中'; }
        if (item.status === 'error') { return '识别失败'; }
        if (item.status === 'skipped') { return '已放弃'; }
        if (item.status === 'saved') { return '已保存'; }
        if (item.amountStatus === 'detected') { return '已自动识别'; }
        if (item.status === 'ready') { return '请确认金额'; }
        return '等待识别';
    }

    function uploadItemsHtml(items) {
        if (!items.length) {
            return '<div class="qr-empty">尚未选择图片</div>';
        }

        return items.map(function (item) {
            var disabled = item.commitLocked ? ' disabled' : '';
            var statusClass = item.status === 'error'
                ? ' is-error'
                : (item.status === 'processing' ? ' is-processing'
                    : (item.status === 'queued' ? ' is-queued'
                        : (item.status === 'skipped' ? ' is-skipped'
                            : (item.status === 'saved' ? ' is-saved'
                                : (item.amountStatus === 'detected' ? ' is-success' : ' is-ready')))));
            var detail = item.error || item.warning || (item.decoder ? '识别引擎：' + item.decoder : '等待处理');
            return '<article class="qr-card qr-upload-card" data-id="' + escapeHtml(item.id) + '">' +
                '<img class="qr-card__image" src="' + escapeHtml(item.preview) + '" alt="二维码预览">' +
                '<div class="qr-card__content">' +
                    '<div class="qr-card__heading">' +
                        '<strong class="qr-upload-card__name" title="' + escapeHtml(item.file.name) + '">' + escapeHtml(item.file.name) + '</strong>' +
                        '<span class="qr-status' + statusClass + '">' + statusText(item) + '</span>' +
                    '</div>' +
                    '<label class="qr-url-field"><span>收款链接</span><input type="text" data-field="url" value="' + escapeHtml(item.url) + '" placeholder="自动识别失败时可手动粘贴"' + disabled + '></label>' +
                    '<div class="qr-upload-item__detail" title="' + escapeHtml(detail) + '">' + escapeHtml(detail) + '</div>' +
                    '<div class="qr-upload-card__actions">' +
                        '<label class="qr-inline-amount"><span>金额</span><input type="text" inputmode="decimal" data-field="amount" value="' + escapeHtml(item.amount) + '" placeholder="0.00"' + disabled + '></label>' +
                        '<button type="button" class="qr-icon-btn qr-icon-btn--danger" data-action="remove" title="移除"' + disabled + '><i class="layui-icon layui-icon-delete"></i></button>' +
                    '</div>' +
                '</div>' +
            '</article>';
        }).join('');
    }

    function renderUploadItems(container, items) {
        container.innerHTML = uploadItemsHtml(items);
    }

    function dependencyHelpHtml(data) {
        data = data || {};
        function state(ok, version) {
            return '<span class="qr-dependency-state ' + (ok ? 'is-ok' : 'is-missing') + '">' +
                (ok ? '可用' + (version ? ' ' + escapeHtml(version) : '') : '未检测到') + '</span>';
        }
        function toolState(dependency) {
            if (!data.proc_open) {
                return '<span class="qr-dependency-state is-missing">无法检测（先移除 proc_open 禁用）</span>';
            }
            return state(dependency && dependency.available, dependency && dependency.version);
        }

        var procOpenWarning = data.proc_open ? '' :
            '<div class="qr-dependency-warning"><strong>必须先解除 PHP 限制</strong>' +
            '<span>请在宝塔 PHP 的“禁用函数”中移除 <code>proc_open</code>，然后重启 PHP。' +
            '未移除前，系统无法检测或运行 zbarimg、ZXing-C++、OpenCV 和 Tesseract。</span></div>';

        return '<div class="qr-dependency-help">' +
            procOpenWarning +
            '<div class="qr-dependency-grid">' +
                '<div>zbarimg</div>' + toolState(data.zbarimg) +
                '<div>ZXing-C++</div>' + toolState(data.zxingcpp) +
                '<div>OpenCV</div>' + toolState(data.opencv) +
                '<div>Tesseract</div>' + toolState(data.tesseract) +
                '<div>PHP proc_open</div>' + state(data.proc_open) +
                '<div>PHP 临时目录</div>' + state(data.temp_dir_writable) +
                '<div>PHP SimpleXML</div>' + state(data.simplexml) +
            '</div>' +
            '<h4>Debian / Ubuntu</h4><pre>apt update\napt install -y zbar-tools python3-opencv python3-zxing-cpp tesseract-ocr tesseract-ocr-eng php-xml</pre>' +
            '<h4>CentOS 7</h4><pre>yum install -y epel-release\nyum install -y zbar tesseract php-xml python3 python3-pip\npython3 -m pip install opencv-python-headless zxing-cpp</pre>' +
            '<h4>Rocky / AlmaLinux</h4><pre>dnf install -y epel-release\ndnf install -y zbar tesseract php-xml python3 python3-pip\npython3 -m pip install opencv-python-headless zxing-cpp</pre>' +
            '<h4>宝塔 PHP（必须先操作）</h4><p>软件商店 → 已安装 → PHP → 设置 → 禁用函数，移除 <code>proc_open</code> 后重启 PHP，否则无法检测上述识别组件。</p>' +
            '<p class="qr-dependency-note">本识别方案不需要安装 PHP GD 扩展。</p>' +
        '</div>';
    }

    function showDependencyHelp() {
        var loading = typeof layer !== 'undefined' ? layer.load(1) : null;
        return request({url: API_ROOT + '/dependencies'}).then(function (data) {
            if (loading !== null) { layer.close(loading); }
            if (typeof layer !== 'undefined') {
                layer.open({
                    type: 1,
                    title: '二维码识别组件',
                    area: [Math.min(720, window.innerWidth - 32) + 'px', 'auto'],
                    content: dependencyHelpHtml(data)
                });
            }
            return data;
        }, function (error) {
            if (loading !== null) { layer.close(loading); }
            if (typeof layer !== 'undefined') { layer.alert(error.message); }
            throw error;
        });
    }

    function conflictSummaryHtml(preview) {
        var databaseCount = (preview.database_conflicts || []).length;
        var batchCount = (preview.batch_conflicts || []).length;
        return '<div class="qr-conflict-summary">' +
            '<p>检测到同金额二维码，请选择本批次的处理方式。</p>' +
            '<dl><div><dt>管理列表冲突</dt><dd>' + databaseCount + ' 项</dd></div>' +
            '<div><dt>本批次重复金额</dt><dd>' + batchCount + ' 组</dd></div></dl>' +
            '<p class="qr-conflict-note">全部替换时，同金额以最后选择的图片为准；替换只更新二维码内容。</p>' +
        '</div>';
    }

    function chooseConflictMode(preview) {
        return new Promise(function (resolve, reject) {
            if (typeof layer === 'undefined' || !layer.open) {
                reject(new Error('冲突确认组件未加载'));
                return;
            }
            var settled = false;
            var index = layer.open({
                type: 1,
                title: '处理重复金额',
                area: [Math.min(620, window.innerWidth - 32) + 'px', 'auto'],
                content: conflictSummaryHtml(preview),
                btn: ['全部替换', '逐个确认', '全部放弃冲突项'],
                yes: function () {
                    settled = true;
                    layer.close(index);
                    resolve('replace_all');
                },
                btn2: function () {
                    settled = true;
                    layer.close(index);
                    resolve('individual');
                    return false;
                },
                btn3: function () {
                    settled = true;
                    layer.close(index);
                    resolve('skip_all');
                    return false;
                },
                cancel: function () {
                    if (!settled) {
                        var error = new Error('已取消保存');
                        error.cancelled = true;
                        reject(error);
                    }
                }
            });
        });
    }

    function confirmIndividualConflict(current, candidate, conflict, itemById, paymentLabel) {
        return new Promise(function (resolve, reject) {
            if (typeof layer === 'undefined' || !layer.confirm) {
                reject(new Error('冲突确认组件未加载'));
                return;
            }
            var currentLocal = current && itemById[current.client_id];
            var candidateLocal = itemById[candidate.client_id];
            var currentText = conflict.kind === 'database'
                ? '管理列表现有二维码 #' + escapeHtml(conflict.existing_id)
                : escapeHtml((currentLocal && currentLocal.file.name) || current.pay_url);
            var candidateText = escapeHtml((candidateLocal && candidateLocal.file.name) || candidate.pay_url);
            var content = '<div class="qr-conflict-review">' +
                '<p><strong>' + escapeHtml(paymentLabel) + ' · ' + escapeHtml(candidate.price) + ' 元</strong></p>' +
                '<dl><div><dt>当前保留</dt><dd>' + currentText + '</dd></div>' +
                '<div><dt>待处理图片</dt><dd>' + candidateText + '</dd></div></dl>' +
                '<p>是否用待处理图片替换当前二维码？</p></div>';
            var settled = false;
            var index = layer.confirm(content, {
                title: '逐个确认重复金额',
                area: [Math.min(560, window.innerWidth - 32) + 'px', 'auto'],
                btn: ['替换', '放弃'],
                cancel: function () {
                    if (!settled) {
                        var error = new Error('已取消保存');
                        error.cancelled = true;
                        reject(error);
                    }
                }
            }, function () {
                settled = true;
                layer.close(index);
                resolve('replace');
            }, function () {
                settled = true;
                resolve('skip');
            });
        });
    }

    function mountUpload(options) {
        var root = typeof options.root === 'string' ? document.querySelector(options.root) : options.root;
        if (!root) { return null; }

        var type = Number(options.type) === 2 ? 2 : 1;
        var label = type === 1 ? '微信收款码' : '支付宝收款码';
        var items = [];
        var nextLocalId = 1;
        var maxItems = 20;
        var commitInFlight = false;

        root.innerHTML = '<div class="qr-page-toolbar">' +
            '<div><h2>' + label + '</h2><span class="qr-toolbar-status" data-role="summary">0 个文件</span></div>' +
            '<div class="qr-toolbar-actions">' +
                '<input type="file" data-role="file" accept="image/jpeg,image/png,image/webp" multiple hidden>' +
                '<button type="button" class="layui-btn layui-btn-normal" data-action="select"><i class="layui-icon layui-icon-upload"></i>选择图片</button>' +
                '<button type="button" class="layui-btn layui-btn-primary" data-action="dependencies"><i class="layui-icon layui-icon-set"></i>识别组件</button>' +
                '<button type="button" class="layui-btn" data-action="save"><i class="layui-icon layui-icon-ok"></i>保存</button>' +
            '</div>' +
        '</div><div class="qr-card-grid qr-upload-list" data-role="items"></div>';

        root.classList.add('qr-admin--upload');

        var input = root.querySelector('[data-role="file"]');
        var itemContainer = root.querySelector('[data-role="items"]');
        var summary = root.querySelector('[data-role="summary"]');
        var saveButton = root.querySelector('[data-action="save"]');

        function findItem(id) {
            for (var i = 0; i < items.length; i++) {
                if (items[i].id === id) { return items[i]; }
            }
            return null;
        }

        function isBusy() {
            return items.some(function (item) {
                return item.status === 'queued' || item.status === 'processing';
            });
        }

        function revokePreview(item) {
            if (item.previewObjectUrl) {
                URL.revokeObjectURL(item.previewObjectUrl);
                item.previewObjectUrl = '';
            }
        }

        function message(text) {
            if (typeof layer !== 'undefined' && layer.msg) { layer.msg(text); }
        }

        var recognitionQueue = createRecognitionQueue(function (item) {
            return analyzeFile(item.file).then(function (result) {
                return {
                    url: result.url || '',
                    amount: result.amount || '',
                    amountStatus: result.amount_status || 'manual',
                    decoder: result.decoder || '',
                    warning: result.warning || ''
                };
            });
        }, 2, function () {
            render();
        });

        function render() {
            renderUploadItems(itemContainer, items);
            summary.textContent = items.length + ' 个文件';
            saveButton.disabled = isBusy() || commitInFlight;

            Array.prototype.forEach.call(itemContainer.querySelectorAll('[data-field="url"]'), function (field) {
                field.addEventListener('input', function () {
                    var card = field.closest('[data-id]');
                    var item = findItem(card.getAttribute('data-id'));
                    if (!item) { return; }
                    item.url = field.value.trim();
                    if (item.url) {
                        recognitionQueue.remove(item.id);
                        item.status = 'ready';
                        item.error = '';
                        item.warning = '已手工填写收款链接';
                        var badge = card.querySelector('.qr-status');
                        badge.className = 'qr-status';
                        badge.textContent = '已手工填写';
                    }
                });
            });
            Array.prototype.forEach.call(itemContainer.querySelectorAll('[data-field="amount"]'), function (field) {
                field.addEventListener('input', function () {
                    var item = findItem(field.closest('[data-id]').getAttribute('data-id'));
                    if (!item) { return; }
                    item.amount = field.value.trim();
                    item.amountStatus = 'manual';
                    if (item.status === 'error' && item.url && isValidAmount(item.amount)) {
                        item.status = 'ready';
                        item.error = '';
                        render();
                    }
                });
            });
            Array.prototype.forEach.call(itemContainer.querySelectorAll('[data-action="remove"]'), function (button) {
                button.addEventListener('click', function () {
                    var id = button.closest('[data-id]').getAttribute('data-id');
                    var item = findItem(id);
                    if (!item || item.commitLocked) { return; }
                    recognitionQueue.remove(id);
                    revokePreview(item);
                    items = items.filter(function (candidate) { return candidate.id !== id; });
                    render();
                });
            });
        }

        function createItem(file) {
            var preview = URL.createObjectURL(file);
            return {
                id: 'local-' + nextLocalId++,
                file: file,
                preview: preview,
                previewObjectUrl: preview,
                url: '',
                amount: '',
                amountStatus: 'manual',
                status: 'queued',
                error: ''
            };
        }

        function ingestFiles(fileList) {
            var partition = partitionImageFiles(fileList);
            var result = appendFiles(items, partition.accepted, createItem, maxItems);
            items = result.items;
            if (partition.rejected.length) {
                message('已忽略 ' + partition.rejected.length + ' 个非图片文件');
            }
            if (result.ignored) {
                message('已忽略 ' + result.ignored + ' 个重复图片');
            }
            if (result.rejected) {
                message('最多可添加 ' + maxItems + ' 张图片，已忽略 ' + result.rejected + ' 张');
            }
            render();
            recognitionQueue.enqueue(result.added);
        }

        var removeDropTarget = bindDropTarget(root, ingestFiles, function (active) {
            root.classList.toggle('is-dragging', active);
        });

        root.querySelector('[data-action="select"]').addEventListener('click', function () { input.click(); });
        root.querySelector('[data-action="dependencies"]').addEventListener('click', showDependencyHelp);
        input.addEventListener('change', function () {
            ingestFiles(input.files || []);
            input.value = '';
        });

        saveButton.addEventListener('click', function () {
            if (!items.length) {
                message('请先选择二维码图片');
                return;
            }
            if (isBusy()) {
                message('请等待识别完成');
                return;
            }
            if (commitInFlight) { return; }
            for (var i = 0; i < items.length; i++) {
                if (!items[i].url) {
                    message('序号 ' + (i + 1) + ' 未识别到二维码内容');
                    return;
                }
                if (!isValidAmount(items[i].amount)) {
                    message('序号 ' + (i + 1) + ' 的金额有误');
                    return;
                }
            }

            var batch = items.slice();
            var itemById = {};
            var payloadItems = batch.map(function (item) {
                item.commitLocked = true;
                itemById[item.id] = item;
                return {client_id: item.id, pay_url: item.url.trim(), price: item.amount.trim()};
            });
            commitInFlight = true;
            render();

            function commitPreview(preview) {
                var modePromise = preview.has_conflicts
                    ? chooseConflictMode(preview)
                    : Promise.resolve('replace_all');
                return modePromise.then(function (mode) {
                    return buildConflictDecisions(preview, mode, function (current, candidate, conflict) {
                        return confirmIndividualConflict(current, candidate, conflict, itemById, label);
                    });
                }).then(function (decisions) {
                    return request({
                        url: API_ROOT + '/batch/commit',
                        method: 'POST',
                        data: {
                            type: type,
                            items: payloadItems,
                            conflict_token: preview.conflict_token,
                            decisions: decisions
                        }
                    });
                }, function (error) {
                    throw error;
                }).catch(function (error) {
                    if (error.code === 409 && error.data && error.data.preview) {
                        return commitPreview(error.data.preview);
                    }
                    throw error;
                });
            }

            request({
                url: API_ROOT + '/batch/preview',
                method: 'POST',
                data: {type: type, items: payloadItems}
            }).then(commitPreview).then(function (result) {
                var resultById = {};
                (result.results || []).forEach(function (entry) {
                    resultById[entry.client_id] = entry;
                });
                var removeIds = {};
                batch.forEach(function (snapshotItem) {
                    var current = findItem(snapshotItem.id);
                    var entry = resultById[snapshotItem.id];
                    if (!current) { return; }
                    current.commitLocked = false;
                    if (entry && (entry.status === 'inserted' || entry.status === 'replaced')) {
                        current.status = 'saved';
                        revokePreview(current);
                        removeIds[current.id] = true;
                    } else if (entry && entry.status === 'skipped') {
                        current.status = 'skipped';
                        revokePreview(current);
                        removeIds[current.id] = true;
                    } else {
                        current.status = 'error';
                        current.error = (entry && entry.message) || '服务器未返回该二维码的保存结果';
                    }
                });
                items = items.filter(function (item) { return !removeIds[item.id]; });
                commitInFlight = false;
                render();
                var totals = result.totals || {};
                message('新增 ' + (totals.inserted || 0) + '，替换 ' + (totals.replaced || 0) +
                    '，放弃 ' + (totals.skipped || 0) + '，失败 ' + (totals.failed || 0));
            }, function (error) {
                batch.forEach(function (item) {
                    var current = findItem(item.id);
                    if (current) { current.commitLocked = false; }
                });
                commitInFlight = false;
                render();
                if (!error.cancelled) { message(error.message); }
            });
        });

        render();
        return {
            destroy: function () {
                removeDropTarget();
                items.forEach(function (item) {
                    recognitionQueue.remove(item.id);
                    revokePreview(item);
                });
                items = [];
                root.classList.remove('is-dragging');
                root.classList.remove('qr-admin--upload');
            },
            getItems: function () { return items.slice(); }
        };
    }

    return {
        analyzeFiles: analyzeFiles,
        appendFiles: appendFiles,
        bindDropTarget: bindDropTarget,
        buildConflictDecisions: buildConflictDecisions,
        createRecognitionQueue: createRecognitionQueue,
        dependencyHelpHtml: dependencyHelpHtml,
        escapeHtml: escapeHtml,
        fileIdentity: fileIdentity,
        isValidAmount: isValidAmount,
        mount: mountUpload,
        mountUpload: mountUpload,
        request: request,
        responseError: responseError,
        runPool: runPool,
        showDependencyHelp: showDependencyHelp,
        partitionImageFiles: partitionImageFiles,
        uploadItemsHtml: uploadItemsHtml
    };
}));
