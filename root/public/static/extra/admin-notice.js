$(function () {
    (function () {
        // 自动注入样式
        if ($('link[href*="admin-notice.css"]').length === 0) {
            $('head').append('<link rel="stylesheet" href="/static/extra/admin-notice.css">');
        }

        // 时间格式化补丁工具
        var formatDate = function (ts) {
            if (!ts) return '';
            var d = new Date(ts * 1000);
            var pad = function (n) { return n < 10 ? '0' + n : n; };
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        };

        /**
         * HTML 转义。
         *
         * ⚠️ 这里原来是把 item.title / item.content 直接拼进 innerHTML 的，而通知内容里
         * 有用户可控的部分 —— RechargeService/WithdrawService 拼的是
         * `用户 [{$name}] 提交了...`，$name 来自注册时填的昵称。
         * 也就是说注册一个名字叫 `<img src=x onerror=...>` 的用户，就能在**每个管理员**
         * 的通知面板里执行脚本。属于存储型 XSS，顺手在这里堵掉。
         */
        var esc = function (v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
            });
        };

        /** 转义后把换行还原成 <br> —— 通知正文是 "\n" 拼的多行列表，塌成一行就看不出是哪几个交易对 */
        var escMultiline = function (v) { return esc(v).replace(/\n/g, '<br>'); };

        /**
         * 通知类型 → 该跳去哪个页面。
         *
         * system_notification 表里没有存目标路由（只有 type/title/content），所以这里按标题
         * 匹配。是有点糙，但比在表上加一列、再去改所有生产者要小得多，而标题是发送方
         * 写死的常量（见 MarketPriceCheckExpire / RechargeService / WithdrawService），
         * 不是用户输入，匹配是稳定的。
         * 新增一类通知时在这里加一行即可；匹配不到就只显示「关闭」，不会报错。
         */
        var NOTICE_ROUTES = [
            {match: /行情价格|标记价格/, node: 'business/market_pipeline/index', label: '去落库链路诊断'},
            {match: /充值/,             node: 'business/user_recharge/index',   label: '去充值管理'},
            {match: /提现/,             node: 'business/withdraw/index',        label: '去提现管理'}
        ];

        var routeFor = function (title) {
            for (var i = 0; i < NOTICE_ROUTES.length; i++) {
                if (NOTICE_ROUTES[i].match.test(String(title || ''))) return NOTICE_ROUTES[i];
            }
            return null;
        };

        /** 走 ThinkAdmin 自己的 data-open 通道开 Tab，而不是 location.href（那样会把整个后台框架重载） */
        var openNode = function (node, label) {
            if (window.$ && $.form && $.form.href) {
                var a = document.createElement('a');
                a.dataset.open = node;
                a.dataset.title = label || '';
                $.form.href(node, a);
            } else {
                window.location.href = '/' + node;
            }
        };

        var updateNotices = function () {
            var $badge = $('#f2c-notif-badge');
            var $panel = $('#f2c-notif-pan');
            if ($badge.length === 0) return;

            $.ajax({
                url: '/business/api.notice/summary',
                type: 'POST',
                data: {},
                global: false,
                success: function (ret) {
                    if (ret.code) {
                        var data = ret.data || {};
                        var unread = data.unread || 0;
                        var list = data.list || [];
                        $badge.text(unread > 99 ? '99+' : unread).toggle(unread > 0);
                        if ($panel.length > 0) {
                            renderList($panel.find('.f2c-dd-content'), list, 'notice');
                        }
                    }
                }
            });
        };

        var updateTasks = function () {
            var $badge = $('#f2c-pending-badge');
            var $panel = $('#f2c-pending-pan');
            if ($badge.length === 0) return;

            $.ajax({
                url: '/business/api.task/summary',
                type: 'POST',
                data: {},
                global: false,
                success: function (ret) {
                    if (ret.code) {
                        var data = ret.data || {};
                        var total = data.total || 0;
                        var items = data.items || [];
                        $badge.text(total > 99 ? '99+' : total).toggle(total > 0);
                        if ($panel.length > 0) {
                            renderList($panel.find('.f2c-dd-content'), items, 'pending');
                        }
                    }
                }
            });
        };

        var renderList = function ($container, list, type) {
            if (!$container || $container.length === 0) return;
            $container.empty();
            if (!list || list.length === 0) {
                $container.append('<div class="f2c-dd-empty"><i class="layui-icon layui-icon-face-smile" style="font-size:28px;display:block;margin-bottom:6px;opacity:.3"></i>暂无相关数据</div>');
                return;
            }
            list.forEach(function (item) {
                var html = '';
                if (type === 'notice') {
                    var unreadClass = (parseInt(item.is_read) === 0) ? 'unread' : '';
                    var timeStr = formatDate(item.create_ts);
                    var tagColor = item.type || 'blue';

                    // 面板里只放一行摘要（多行正文压成空格，样式再截断），
                    // 完整内容留给点开的弹窗 —— 见下面的 showNoticeDetail()
                    var preview = String(item.content || '').replace(/\s*\n\s*/g, ' ');

                    // 整条数据挂在 data-notice 上，弹窗直接取，省一次请求
                    var payload = esc(JSON.stringify({
                        id: item.id, title: item.title, content: item.content,
                        type: item.type, create_ts: item.create_ts, is_read: item.is_read
                    }));

                    html = '<a href="javascript:;" class="f2c-dd-item ' + unreadClass + '"' +
                        ' data-notice-id="' + esc(item.id) + '" data-notice="' + payload + '">' +
                        '<div class="f2c-item-dot-wrap"><span class="f2c-item-dot"></span></div>' +
                        '<div class="f2c-item-body">' +
                        '   <div class="f2c-item-header">' +
                        '       <span class="item-title">' + esc(item.title || '系统通知') + '</span>' +
                        '       <span class="item-tag layui-bg-' + esc(tagColor) + '">' + esc(tagColor) + '</span>' +
                        '   </div>' +
                        '   <div class="f2c-item-content">' + esc(preview) + '</div>' +
                        '   <div class="f2c-item-time"><i class="layui-icon layui-icon-time"></i> ' + esc(timeStr) + '</div>' +
                        '</div>' +
                        '</a>';
                } else {
                    html = '<a href="javascript:;" data-open="' + esc(item.node) + '" data-title="' + esc(item.nodeName) + '" class="f2c-dd-item">' +
                        '<div class="f2c-item-dot-wrap"><span class="f2c-item-dot" style="display:none"></span></div>' +
                        '<div class="f2c-item-body">' +
                        '   <div class="f2c-item-header">' +
                        '       <span class="item-title">' + esc(item.title) + '</span>' +
                        '       <span class="item-tag layui-bg-orange">待处理</span>' +
                        '   </div>' +
                        '   <div class="f2c-item-content">当前积压数量: <b>' + esc(item.count || 0) + '</b></div>' +
                        '</div>' +
                        '</a>';
                }
                $container.append(html);
            });
        };

        /**
         * 面板显隐切换
         */
        $(document).on('click', '#f2c-notif-btn, #f2c-pending-btn', function (e) {
            e.stopPropagation();
            var isNotif = $(this).attr('id') === 'f2c-notif-btn';
            var $target = $(isNotif ? '#f2c-notif-pan' : '#f2c-pending-pan');

            if ($target.length === 0) return;
            $('.f2c-dropdown').not($target).removeClass('active');
            $target.toggleClass('active');
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.f2c-dropdown, #f2c-notif-btn, #f2c-pending-btn').length) {
                $('.f2c-dropdown').removeClass('active');
            }
        });
        // 允许消息面板内的点击事件冒泡，以便触发 ThinkAdmin 的 data-open 事件打开 Tab
        $(document).on('click', '.f2c-dropdown', function (e) { /* 让事件冒泡以触发 data-open */ });

        /** 标记单条已读并同步角标。$item 可选（从弹窗调用时列表项可能已经不在了） */
        var markRead = function (id, $item) {
            $.post('/business/api.notice/read', {id: id}, function (ret) {
                if (!ret.code) return;
                if ($item && $item.length) $item.removeClass('unread');
                var $badge = $('#f2c-notif-badge');
                var count = parseInt($badge.text(), 10) - 1;
                if (isNaN(count) || count < 0) count = 0;
                $badge.text(count).toggle(count > 0);
            });
        };

        /**
         * 通知详情弹窗。
         *
         * 面板里那一行是**截断的单行摘要**，而行情告警的正文恰恰是多行的
         * `BTCUSDT(永续合约) [HUOBI_btcusdt_2] 已过期 3分20秒`，
         * 一截断就只剩个「行情价格异常」，看不出是哪个交易所、哪个交易对、哪个数据组 ——
         * 这正是「通知里看不到 symbol」的真正原因：内容一直都在，只是没地方显示。
         * 所以这里把完整正文原样展开（换行保留），而不是去改发送方的文案。
         */
        var showNoticeDetail = function (item, $item) {
            if (!window.layui || !layui.layer) return;

            var route = routeFor(item.title);
            var btns  = route ? [route.label, '关闭'] : ['关闭'];
            var tag   = esc(item.type || 'info');

            var body = '<div style="padding:18px 20px;line-height:1.9">' +
                '<div style="margin-bottom:10px">' +
                '<span class="layui-badge layui-bg-' + tag + '">' + tag + '</span>' +
                '<span style="margin-left:8px;color:#999;font-size:12px">' + esc(formatDate(item.create_ts)) + '</span>' +
                '</div>' +
                '<div style="white-space:normal;word-break:break-word;font-size:14px">' +
                escMultiline(item.content || '（无内容）') +
                '</div></div>';

            layui.layer.open({
                type: 1,
                title: esc(item.title || '系统通知'),
                area: ['560px', 'auto'],
                maxHeight: 520,
                shadeClose: true,
                btn: btns,
                content: body,
                yes: function (index) {
                    // 有路由时第一个按钮是跳转，没有时第一个按钮就是「关闭」
                    layui.layer.close(index);
                    if (route) openNode(route.node, route.label);
                }
            });

            if (parseInt(item.is_read, 10) === 0) markRead(item.id, $item);
        };

        /**
         * 点开通知。
         *
         * ⚠️ 选择器原来是 `.f2c-dd-item.unread` —— 只有**未读**的能点，而且点完只是把小圆点
         * 去掉，不展示任何内容。已读的那些则完全没有绑定，点下去真的什么都不发生。
         * 这就是「点击没反应」的来源。现在改成按 data-notice-id 绑定（读没读都能点），
         * 行为也从「悄悄标已读」改成「弹出完整内容」，标已读变成顺带的副作用。
         */
        $(document).on('click', '#f2c-notif-pan .f2c-dd-item[data-notice-id]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $item = $(this);
            var item;
            try {
                item = JSON.parse($item.attr('data-notice') || '{}');
            } catch (err) {
                item = {id: $item.data('notice-id'), title: '系统通知', content: ''};
            }
            showNoticeDetail(item, $item);
            return false;
        });

        /**
         * 全部标记为已读按钮
         */
        $(document).on('click', '#f2c-notif-mark-read', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $.post('/business/api.notice/clear', {}, function (ret) {
                if (ret.code) {
                    $('#f2c-notif-badge').hide().text('0');
                    $('#f2c-notif-pan .f2c-dd-item').removeClass('unread');
                }
            });
            return false;
        });

        // 启动轮询 (每分钟一次)
        updateNotices();
        updateTasks();
        setInterval(function () {
            updateNotices();
            updateTasks();
        }, 60000);

        // 导出到全局，方便 WebSocket 触发
        window.AdminNotice = {
            updateNotices: updateNotices,
            updateTasks: updateTasks
        };
    })();
});