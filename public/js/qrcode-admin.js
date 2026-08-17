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

    function getJquery() {
        if (typeof window === 'undefined') {
            return null;
        }
        return window.jQuery || (window.layui && window.layui.$) || null;
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
                    reject(new Error((response && response.msg) || '请求失败'));
                },
                error: function (xhr) {
                    var message = '请求失败，请检查网络或登录状态';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.msg) {
                        message = xhr.responseJSON.msg;
                    }
                    reject(new Error(message));
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
        if (item.amountStatus === 'detected') { return '已自动识别'; }
        if (item.status === 'ready') { return '请确认金额'; }
        return '等待识别';
    }

    function uploadItemsHtml(items) {
        if (!items.length) {
            return '<div class="qr-empty">尚未选择图片</div>';
        }

        return items.map(function (item, index) {
            var statusClass = item.status === 'error'
                ? ' is-error'
                : (item.amountStatus === 'detected' ? ' is-success' : '');
            var detail = item.error || item.warning || (item.decoder ? '识别引擎：' + item.decoder : '等待处理');
            return '<article class="qr-card qr-upload-card" data-index="' + index + '">' +
                '<img class="qr-card__image" src="' + escapeHtml(item.preview) + '" alt="二维码预览">' +
                '<div class="qr-card__content">' +
                    '<div class="qr-card__heading">' +
                        '<strong class="qr-upload-card__name" title="' + escapeHtml(item.file.name) + '">' + escapeHtml(item.file.name) + '</strong>' +
                        '<span class="qr-status' + statusClass + '">' + statusText(item) + '</span>' +
                    '</div>' +
                    '<label class="qr-url-field"><span>收款链接</span><input type="text" data-field="url" value="' + escapeHtml(item.url) + '" placeholder="自动识别失败时可手动粘贴"></label>' +
                    '<div class="qr-upload-item__detail" title="' + escapeHtml(detail) + '">' + escapeHtml(detail) + '</div>' +
                    '<div class="qr-upload-card__actions">' +
                        '<label class="qr-inline-amount"><span>金额</span><input type="text" inputmode="decimal" data-field="amount" value="' + escapeHtml(item.amount) + '" placeholder="0.00"></label>' +
                        '<button type="button" class="qr-icon-btn qr-icon-btn--danger" data-action="remove" title="移除"><i class="layui-icon layui-icon-delete"></i></button>' +
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

    function mountUpload(options) {
        var root = typeof options.root === 'string' ? document.querySelector(options.root) : options.root;
        if (!root) { return null; }

        var type = Number(options.type) === 2 ? 2 : 1;
        var label = type === 1 ? '微信收款码' : '支付宝收款码';
        var endpoint = API_ROOT + (type === 1 ? '/wechat' : '/alipay');
        var items = [];
        var selectionVersion = 0;

        root.innerHTML = '<div class="qr-page-toolbar">' +
            '<div><h2>' + label + '</h2><span class="qr-toolbar-status" data-role="summary">0 个文件</span></div>' +
            '<div class="qr-toolbar-actions">' +
                '<input type="file" data-role="file" accept="image/jpeg,image/png,image/webp" multiple hidden>' +
                '<button type="button" class="layui-btn layui-btn-normal" data-action="select"><i class="layui-icon layui-icon-upload"></i>选择图片</button>' +
                '<button type="button" class="layui-btn layui-btn-primary" data-action="dependencies"><i class="layui-icon layui-icon-set"></i>识别组件</button>' +
                '<button type="button" class="layui-btn" data-action="save"><i class="layui-icon layui-icon-ok"></i>保存</button>' +
            '</div>' +
        '</div><div class="qr-card-grid qr-upload-list" data-role="items"></div>';

        var input = root.querySelector('[data-role="file"]');
        var itemContainer = root.querySelector('[data-role="items"]');
        var summary = root.querySelector('[data-role="summary"]');

        function render() {
            renderUploadItems(itemContainer, items);
            summary.textContent = items.length + ' 个文件';

            Array.prototype.forEach.call(itemContainer.querySelectorAll('[data-field="url"]'), function (field) {
                field.addEventListener('input', function () {
                    var card = field.closest('[data-index]');
                    var item = items[Number(card.getAttribute('data-index'))];
                    item.url = field.value.trim();
                    if (item.url) {
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
                    var item = items[Number(field.closest('[data-index]').getAttribute('data-index'))];
                    item.amount = field.value.trim();
                    item.amountStatus = 'manual';
                });
            });
            Array.prototype.forEach.call(itemContainer.querySelectorAll('[data-action="remove"]'), function (button) {
                button.addEventListener('click', function () {
                    var index = Number(button.closest('[data-index]').getAttribute('data-index'));
                    if (items[index].previewObjectUrl) {
                        URL.revokeObjectURL(items[index].previewObjectUrl);
                    }
                    items.splice(index, 1);
                    render();
                });
            });
        }

        function selectFiles(fileList) {
            var version = ++selectionVersion;
            items.forEach(function (item) {
                if (item.previewObjectUrl) { URL.revokeObjectURL(item.previewObjectUrl); }
            });
            items = Array.prototype.slice.call(fileList).map(function (file) {
                var preview = URL.createObjectURL(file);
                return {
                    file: file,
                    preview: preview,
                    previewObjectUrl: preview,
                    url: '',
                    amount: '',
                    amountStatus: 'manual',
                    status: 'queued',
                    error: ''
                };
            });
            render();
            var batch = items.slice();

            analyzeFiles(batch.map(function (item) { return item.file; }), {
                onItemStart: function (file, index) {
                    var item = batch[index];
                    if (version !== selectionVersion || items.indexOf(item) === -1) { return; }
                    item.status = 'processing';
                    render();
                },
                onItemDone: function (result, file, index) {
                    var item = batch[index];
                    if (version !== selectionVersion || items.indexOf(item) === -1) { return; }
                    item.url = result.url || '';
                    item.amount = result.amount || '';
                    item.amountStatus = result.amount_status || 'manual';
                    item.decoder = result.decoder || '';
                    item.warning = result.warning || '';
                    item.status = 'ready';
                    render();
                },
                onItemError: function (error, file, index) {
                    var item = batch[index];
                    if (version !== selectionVersion || items.indexOf(item) === -1) { return; }
                    item.status = 'error';
                    item.error = error.message;
                    render();
                }
            });
        }

        root.querySelector('[data-action="select"]').addEventListener('click', function () { input.click(); });
        root.querySelector('[data-action="dependencies"]').addEventListener('click', showDependencyHelp);
        input.addEventListener('change', function () {
            selectFiles(input.files || []);
            input.value = '';
        });

        root.querySelector('[data-action="save"]').addEventListener('click', function () {
            if (!items.length) {
                layer.msg('请先选择二维码图片');
                return;
            }
            for (var i = 0; i < items.length; i++) {
                if (items[i].status === 'processing' || items[i].status === 'queued') {
                    layer.msg('请等待识别完成');
                    return;
                }
                if (!items[i].url) {
                    layer.msg('序号 ' + (i + 1) + ' 未识别到二维码内容');
                    return;
                }
                if (!isValidAmount(items[i].amount)) {
                    layer.msg('序号 ' + (i + 1) + ' 的金额有误');
                    return;
                }
            }

            var loading = layer.load(1);
            var batch = items.slice();
            var version = selectionVersion;
            runPool(batch, 2, function (item) {
                return request({
                    url: endpoint,
                    method: 'POST',
                    data: {pay_url: item.url, price: item.amount}
                }).then(function () {
                    item.saved = true;
                }, function (error) {
                    item.error = error.message;
                    item.status = 'error';
                });
            }).then(function () {
                layer.close(loading);
                if (version !== selectionVersion) {
                    return;
                }
                var failed = batch.filter(function (item) { return !item.saved; });
                batch.filter(function (item) { return item.saved; }).forEach(function (item) {
                    if (item.previewObjectUrl) { URL.revokeObjectURL(item.previewObjectUrl); }
                });
                items = failed;
                render();
                layer.msg(failed.length ? '部分二维码保存失败，请检查错误信息' : '二维码保存成功');
            });
        });

        render();
        return {getItems: function () { return items.slice(); }};
    }

    return {
        analyzeFiles: analyzeFiles,
        dependencyHelpHtml: dependencyHelpHtml,
        escapeHtml: escapeHtml,
        isValidAmount: isValidAmount,
        mount: mountUpload,
        mountUpload: mountUpload,
        request: request,
        runPool: runPool,
        showDependencyHelp: showDependencyHelp,
        uploadItemsHtml: uploadItemsHtml
    };
}));
