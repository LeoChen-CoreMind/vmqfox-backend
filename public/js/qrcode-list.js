(function (root, factory) {
    var api = factory(root.QrAdmin || (typeof require === 'function' ? require('./qrcode-admin.js') : null));
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.QrList = api;
    }
}(typeof self !== 'undefined' ? self : this, function (QrAdmin) {
    'use strict';

    var SORT_TOKENS = {
        newest: true,
        oldest: true,
        amount_asc: true,
        amount_desc: true,
        enabled_first: true,
        disabled_first: true
    };

    function normalizeSort(value) {
        var token = String(value == null ? '' : value).trim();
        return SORT_TOKENS[token] ? token : 'newest';
    }

    function sortStorageKey(type) {
        return Number(type) === 2 ? 'vmqfox:qrcode-sort:alipay' : 'vmqfox:qrcode-sort:wechat';
    }

    function listUrl(endpoint, state) {
        return endpoint + '?page=' + encodeURIComponent(state.page) +
            '&limit=' + encodeURIComponent(state.limit) +
            '&sort=' + encodeURIComponent(normalizeSort(state.sort));
    }

    function readStoredSort(key) {
        try {
            return normalizeSort(window.localStorage.getItem(key));
        } catch (error) {
            return 'newest';
        }
    }

    function writeStoredSort(key, value) {
        try {
            window.localStorage.setItem(key, normalizeSort(value));
        } catch (error) {
            // Storage denial must not prevent list loading or sorting.
        }
    }

    function pageWindow(current, totalPages) {
        if (totalPages <= 5) {
            return Array.from({length: totalPages}, function (_, index) { return index + 1; });
        }
        var start = Math.max(1, current - 2);
        var end = Math.min(totalPages, start + 4);
        start = Math.max(1, end - 4);
        var pages = [];
        for (var page = start; page <= end; page++) { pages.push(page); }
        return pages;
    }

    function afterDeletePage(currentPage, currentItemCount) {
        return currentItemCount <= 1 && currentPage > 1 ? currentPage - 1 : currentPage;
    }

    function escapeAttribute(value) {
        return QrAdmin.escapeHtml(value);
    }

    function normalizeId(value) {
        var text = String(value == null ? '' : value).trim();
        if (!/^\d+$/.test(text) || /^0+$/.test(text)) {
            return '';
        }
        return text.replace(/^0+(?=\d)/, '');
    }

    function deleteRequest(id, endpoint) {
        var normalized = normalizeId(id);
        return {
            url: endpoint || 'index.php/api/qrcode/delete',
            method: 'POST',
            data: {id: normalized}
        };
    }

    function toggleRequest(id, checked) {
        return {
            url: 'index.php/api/qrcode/bind/' + normalizeId(id),
            method: 'POST',
            data: {state: checked ? 0 : 1}
        };
    }

    function mount(options) {
        var root = typeof options.root === 'string' ? document.querySelector(options.root) : options.root;
        if (!root) { return null; }

        var type = Number(options.type) === 2 ? 2 : 1;
        var endpoint = options.endpoint || ('index.php/api/qrcode/' + (type === 1 ? 'wechat' : 'alipay'));
        var deleteEndpoint = options.deleteEndpoint || 'index.php/api/qrcode/delete';
        var storageKey = sortStorageKey(type);
        var state = {page: 1, limit: 12, sort: readStoredSort(storageKey), total: 0, items: [], loading: false};
        var loadVersion = 0;

        root.innerHTML = '<div class="qr-page-toolbar">' +
            '<div><h2>' + (type === 1 ? '微信二维码' : '支付宝二维码') + '</h2><span class="qr-toolbar-status" data-role="count">0 条</span></div>' +
            '<div class="qr-toolbar-actions">' +
                '<label class="qr-page-size">每页<select data-role="limit"><option value="12">12</option><option value="24">24</option><option value="48">48</option></select></label>' +
                '<label class="qr-page-sort">排序<select data-role="sort">' +
                    '<option value="newest">最新优先</option><option value="oldest">最早优先</option>' +
                    '<option value="amount_asc">金额从低到高</option><option value="amount_desc">金额从高到低</option>' +
                    '<option value="enabled_first">启用优先</option><option value="disabled_first">禁用优先</option>' +
                '</select></label>' +
                '<button type="button" class="layui-btn layui-btn-primary" data-action="dependencies"><i class="layui-icon layui-icon-set"></i>识别组件</button>' +
                '<button type="button" class="qr-icon-btn" data-action="refresh" title="刷新"><i class="layui-icon layui-icon-refresh"></i></button>' +
            '</div>' +
        '</div><div class="qr-card-grid" data-role="grid"></div><nav class="qr-pagination" data-role="pagination" aria-label="二维码分页"></nav>';

        var grid = root.querySelector('[data-role="grid"]');
        var count = root.querySelector('[data-role="count"]');
        var pagination = root.querySelector('[data-role="pagination"]');
        var sortSelect = root.querySelector('[data-role="sort"]');
        sortSelect.value = state.sort;

        function renderCards() {
            count.textContent = state.total + ' 条';
            if (state.loading) {
                grid.innerHTML = '<div class="qr-empty">正在加载...</div>';
                return;
            }
            if (!state.items.length) {
                grid.innerHTML = '<div class="qr-empty">当前页没有二维码</div>';
                return;
            }

            grid.innerHTML = state.items.map(function (item) {
                var price = Number(item.price).toFixed(2);
                var imageUrl = 'index.php/api/qrcode/generate?url=' + encodeURIComponent(item.pay_url);
                var enabled = Number(item.state) === 0;
                var id = normalizeId(item.id);
                return '<article class="qr-card" data-id="' + escapeAttribute(id) + '">' +
                    '<img class="qr-card__image" src="' + escapeAttribute(imageUrl) + '" alt="收款二维码" loading="lazy">' +
                    '<div class="qr-card__content">' +
                        '<div class="qr-card__heading"><strong>￥' + escapeAttribute(price) + '</strong>' +
                            '<label class="qr-switch" title="' + (enabled ? '已启用' : '已禁用') + '"><input type="checkbox" data-action="toggle" ' + (enabled ? 'checked' : '') + '><span></span></label>' +
                        '</div>' +
                        '<div class="qr-card__url" title="' + escapeAttribute(item.pay_url) + '">' + escapeAttribute(item.pay_url) + '</div>' +
                        '<div class="qr-card__actions">' +
                            '<label class="qr-inline-amount"><span>金额</span><input type="text" inputmode="decimal" value="' + escapeAttribute(price) + '"></label>' +
                            '<button type="button" class="qr-icon-btn" data-action="amount" title="保存金额"><i class="layui-icon layui-icon-ok"></i></button>' +
                            '<button type="button" class="qr-icon-btn qr-icon-btn--danger" data-action="delete" title="删除"><i class="layui-icon layui-icon-delete"></i></button>' +
                        '</div>' +
                    '</div>' +
                '</article>';
            }).join('');
        }

        function renderPagination() {
            var totalPages = Math.max(1, Math.ceil(state.total / state.limit));
            if (state.total <= state.limit) {
                pagination.innerHTML = '';
                return;
            }
            var buttons = pageWindow(state.page, totalPages).map(function (page) {
                return '<button type="button" data-page="' + page + '" class="' + (page === state.page ? 'is-active' : '') + '">' + page + '</button>';
            }).join('');
            pagination.innerHTML = '<button type="button" data-page="' + (state.page - 1) + '" title="上一页" ' + (state.page === 1 ? 'disabled' : '') + '><i class="layui-icon layui-icon-left"></i></button>' +
                buttons +
                '<button type="button" data-page="' + (state.page + 1) + '" title="下一页" ' + (state.page === totalPages ? 'disabled' : '') + '><i class="layui-icon layui-icon-right"></i></button>' +
                '<span>第 ' + state.page + ' / ' + totalPages + ' 页</span>';
        }

        function load() {
            var version = ++loadVersion;
            state.loading = true;
            renderCards();
            return QrAdmin.request({
                url: listUrl(endpoint, state)
            }).then(function (data) {
                if (version !== loadVersion) { return; }
                state.total = Number(data.total) || 0;
                state.items = Array.isArray(data.items) ? data.items : [];
                state.page = Number(data.page) || state.page;
                state.limit = Number(data.limit) || state.limit;
                state.sort = normalizeSort(data.sort || state.sort);
                sortSelect.value = state.sort;
                state.loading = false;
                renderCards();
                renderPagination();
            }, function (error) {
                if (version !== loadVersion) { return; }
                state.loading = false;
                state.items = [];
                renderCards();
                renderPagination();
                layer.alert(error.message);
            });
        }

        root.querySelector('[data-action="dependencies"]').addEventListener('click', QrAdmin.showDependencyHelp);
        root.querySelector('[data-action="refresh"]').addEventListener('click', load);
        root.querySelector('[data-role="limit"]').addEventListener('change', function (event) {
            state.limit = Number(event.target.value);
            state.page = 1;
            load();
        });
        sortSelect.addEventListener('change', function (event) {
            state.sort = normalizeSort(event.target.value);
            event.target.value = state.sort;
            writeStoredSort(storageKey, state.sort);
            state.page = 1;
            load();
        });
        pagination.addEventListener('click', function (event) {
            var button = event.target.closest('[data-page]');
            if (!button || button.disabled) { return; }
            state.page = Number(button.getAttribute('data-page'));
            load();
        });

        grid.addEventListener('click', function (event) {
            var button = event.target.closest('[data-action]');
            if (!button) { return; }
            var card = button.closest('[data-id]');
            var id = normalizeId(card.getAttribute('data-id'));
            if (!id) {
                layer.alert('二维码 ID 无效，请刷新列表后重试');
                return;
            }

            if (button.getAttribute('data-action') === 'amount') {
                var amount = card.querySelector('.qr-inline-amount input').value.trim();
                if (!QrAdmin.isValidAmount(amount)) {
                    layer.msg('金额必须大于 0，且最多保留两位小数');
                    return;
                }
                QrAdmin.request({
                    url: 'index.php/api/qrcode/' + id + '/amount',
                    method: 'POST',
                    data: {price: amount}
                }).then(function () {
                    layer.msg('金额已保存');
                    load();
                }, function (error) { layer.alert(error.message); });
            }

            if (button.getAttribute('data-action') === 'delete') {
                layer.confirm('确定删除这个二维码吗？', function (dialogIndex) {
                    layer.close(dialogIndex);
                    QrAdmin.request(deleteRequest(id, deleteEndpoint)).then(function () {
                        state.page = afterDeletePage(state.page, state.items.length);
                        layer.msg('二维码已删除');
                        load();
                    }, function (error) { layer.alert(error.message); });
                });
            }
        });

        grid.addEventListener('change', function (event) {
            if (!event.target.matches('[data-action="toggle"]')) { return; }
            var checkbox = event.target;
            var id = normalizeId(checkbox.closest('[data-id]').getAttribute('data-id'));
            if (!id) {
                checkbox.checked = !checkbox.checked;
                layer.alert('二维码 ID 无效，请刷新列表后重试');
                return;
            }
            var requestedChecked = checkbox.checked;
            checkbox.disabled = true;
            QrAdmin.request(toggleRequest(id, requestedChecked)).then(function (data) {
                var responseState = data && data.state;
                var hasStoredState = responseState === 0 || responseState === 1 ||
                    responseState === '0' || responseState === '1';
                var savedState = hasStoredState ? Number(responseState) : (requestedChecked ? 0 : 1);
                checkbox.checked = savedState === 0;
                checkbox.disabled = false;

                state.items.forEach(function (item) {
                    if (normalizeId(item.id) === id) {
                        item.state = savedState;
                    }
                });

                var label = checkbox.closest('.qr-switch');
                if (label) {
                    label.title = checkbox.checked ? '已启用' : '已禁用';
                }
                layer.msg(checkbox.checked ? '二维码已启用' : '二维码已禁用');
                load();
            }, function (error) {
                checkbox.checked = !requestedChecked;
                checkbox.disabled = false;
                layer.alert(error.message);
            });
        });

        load();
        return {load: load, getState: function () { return state; }};
    }

    return {
        afterDeletePage: afterDeletePage,
        deleteRequest: deleteRequest,
        listUrl: listUrl,
        mount: mount,
        normalizeId: normalizeId,
        normalizeSort: normalizeSort,
        pageWindow: pageWindow,
        sortStorageKey: sortStorageKey,
        toggleRequest: toggleRequest
    };
}));
