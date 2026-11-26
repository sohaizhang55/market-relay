/**
 * Admin WebSocket Client for ThinkAdmin
 * Connects to port 22345 (AdminGateway)
 * Uses the bindSession pattern for authentication
 */

(function () {
    const port = window.ADMIN_WS_PORT;
    const proxyPath = window.ADMIN_WS_PROXY_PATH || '';
    const scheme = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
    // 配置了 proxy_path 时(域名走 nginx 反代，裸端口从外部不可达)，改走同域路径由 nginx 转发，不带端口；
    // 否则保持原有的直连 host:port 方式 (对应 deploy/nginx/nginx.site.common.sample 的 /adminwss location)
    const wsUrl = proxyPath
        ? scheme + window.location.hostname + proxyPath
        : scheme + window.location.hostname + ':' + port;
    const bindUrl = '/business/ws/bindSession'; // Using logic from Ws.php
    let socket = null;
    let timer = null;
    let reconnectCount = 0;
    let noticeLayerIdx = null; // Store current notification index to prevent stacking

    function connect() {
        console.log('[WS] Connecting to AdminGateway: ' + wsUrl);
        socket = new WebSocket(wsUrl);

        socket.onopen = function () {
            console.log('[WS] Connected');
            reconnectCount = 0;

            // Start heartbeat
            if (timer) clearInterval(timer);
            timer = setInterval(() => {
                if (socket.readyState === WebSocket.OPEN) {
                    socket.send(JSON.stringify({ type: 'ping' }));
                }
            }, 25000);
        };

        socket.onmessage = function (event) {
            try {
                const data = JSON.parse(event.data);
                console.log('[WS] Message received:', data);

                // Handle 'init' type to perform session binding via HTTP
                if (data.type === 'init' && data.client_id) {
                    console.log('[WS] Initialized, binding session for client_id:', data.client_id);
                    $.ajax({
                        url: bindUrl,
                        type: 'GET',
                        data: { client_id: data.client_id },
                        global: false,
                        success: function (res) {
                            if (res.code === 1) {
                                console.log('[WS] Session binding successful:', res.msg);
                            } else {
                                console.error('[WS] Session binding failed:', res.msg);
                            }
                        }
                    });
                }

                // Handle 'notice' or 'system_notice' type
                if (data.type === 'notice' || data.type === 'system_notice') {
                    const info = data.data || {};
                    const msg = data.message || info.msg || 'New notification';
                    const unreadCount = info.unread_count || null;

                    // 1. Update Notification Badge Immediately (No Lag)
                    if (unreadCount !== null) {
                        const $badge = $('#f2c-notif-badge');
                        if ($badge.length) {
                            $badge.text(unreadCount > 99 ? '99+' : unreadCount).toggle(unreadCount > 0);
                        }
                    }

                    // 2. Play notification sound
                    try {
                        const audio = new Audio('/static/extra/sound/admin-notice.mp3');
                        audio.play().catch(e => console.debug('[WS] Audio play failed:', e));
                    } catch (e) {
                        console.error('[WS] Sound error:', e);
                    }

                    // 3. Show notification popup (Top-Middle Pop-Down, No Stacking)
                    if (window.layui && layui.layer) {
                        if (noticeLayerIdx) layui.layer.close(noticeLayerIdx);

                        // 注入临时样式确保美观
                        if ($('#f2c-pop-style').length === 0) {
                            $('head').append('<style id="f2c-pop-style">.layui-layer-f2c-notice{background:none!important;box-shadow:none!important;border:none!important}.f2c-pop-notice{padding:12px 25px;background:#009688;color:#fff;border-radius:0 0 4px 4px;font-size:14px;box-shadow:0 2px 10px rgba(0,0,0,.2);cursor:pointer;min-width:200px;text-align:center}.f2c-pop-notice i{margin-right:8px;vertical-align:middle;font-size:18px}</style>');
                        }

                        noticeLayerIdx = layui.layer.msg('<div class="f2c-pop-notice"><i class="layui-icon layui-icon-notice"></i> ' + msg + '</div>', {
                            offset: 't', // Top Middle
                            anim: 5,     // Slide down
                            time: 5000,
                            isOutAnim: true,
                            skin: 'layui-layer-f2c-notice',
                            success: function (layero, index) {
                                // 使用本地 index 确保关闭操作的准确性
                                layero.on('click', function () { layui.layer.close(index); });
                            }
                        });
                    }

                    // 4. Trigger list refresh in dropdown (Delay 500ms to ensure DB commit)
                    setTimeout(function () {
                        if (window.AdminNotice && window.AdminNotice.updateNotices) {
                            window.AdminNotice.updateNotices();
                        }
                    }, 500);
                }

                // Handle 'pong' type
                if (data.type === 'pong') {
                    console.debug('[WS] Pong received');
                }
            } catch (e) {
                console.error('[WS] Parse error:', e);
            }
        };

        socket.onclose = function () {
            console.log('[WS] Disconnected');
            if (timer) clearInterval(timer);
            // Reconnect logic
            if (reconnectCount < 10) {
                reconnectCount++;
                setTimeout(connect, 3000 * reconnectCount);
            }
        };

        socket.onerror = function (error) {
            console.error('[WS] Error:', error);
        };
    }

    // Initialize connection
    if (window.WebSocket) {
        // Delay a bit to ensure jQuery/Layui are fully ready
        setTimeout(connect, 500);
    } else {
        console.warn('[WS] Browser does not support WebSocket');
    }

    // Export for global use
    window.AdminWS = {
        send: function (data) {
            if (socket && socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify(data));
            }
        }
    };
})();
