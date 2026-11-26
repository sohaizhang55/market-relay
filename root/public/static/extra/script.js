// +----------------------------------------------------------------------
// | Static Plugin for ThinkAdmin
// +----------------------------------------------------------------------
// | 官方网站: https://thinkadmin.top
// +----------------------------------------------------------------------
// | 版权所有 2014~2025 ThinkAdmin [ thinkadmin.top ]
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// | 免责声明 ( https://thinkadmin.top/disclaimer )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/think-plugs-static
// | github 代码仓库：https://github.com/zoujingli/think-plugs-static
// +----------------------------------------------------------------------
// | 自定义后台扩展脚本，需要在加载 admin.js 后载入
// | 使用 composer require zoujingli/think-plugs-static 时不会更新此文件
// +----------------------------------------------------------------------


$(function () {
    // +----------------------------------------------------------------------
    // | 自定义全局补丁：禁用图片选择器弹窗，强制直接上传 (表单级钩子版)
    // +----------------------------------------------------------------------
    var patch = function (dom) {
        $(dom || 'body').find('[data-file="image"]').attr('data-file', 'one');
        $(dom || 'body').find('[data-file="images"]').attr('data-file', 'btn');

        // +----------------------------------------------------------------------
        // | 全局补丁：自动初始化标记了 .my-editor 的编辑器
        // +----------------------------------------------------------------------
        $(dom || 'body').find('.my-editor').each(function () {
            var $t = $(this);
            if ($t.attr('ck-init')) return;
            require(['ckeditor'], function () {
                if (typeof window.createEditor === 'function') {
                    window.createEditor($t, { height: 400 });
                    $t.attr('ck-init', '1');
                }
            });
        });
    };
    $(document).on('reInit', function (e, dom) { patch(dom) });
    patch(); setTimeout(patch, 100);

    window.$body = $('body');

    // 显示表格图片
    window.showTableImage = function (image, circle, size, title) {
        return $.layTable.showImage(image, circle, size, title);
    };

    /**
     * 通用 Tab 状态记忆加载器
     * @param {string} filter lay-filter 属性值
     * @param {string} storageKey 本地存储的 Key（可选）
     */
    $.tab = {
        remember: function (filter, storageKey) {
            var element = layui.element;
            var key = storageKey || ('tab_memory_' + location.pathname + '_' + filter);
            // 1. 监听切换并存储
            element.on('tab(' + filter + ')', function () {
                var layid = $(this).attr('lay-id');
                if (layid) layui.data('AdminTabState', { key: key, value: layid });
            });
            // 2. 加载时恢复
            var lastTab = layui.data('AdminTabState')[key];
            if (lastTab) {
                element.tabChange(filter, lastTab);
            }
        }
    };

    // 显示商品规格
    window.showSpec = function (spec) {
        let names = [];
        return layui.each(spec.split(';;'), function (i, vv) {
            names.push(vv.split('::').pop());
        }), names.join(' ');
    }

    /*! 后台多标签管理 */
    layui.use(['element', 'dropdown'], function () {
        let element = layui.element, dropdown = layui.dropdown;
        let $container = $('.think-tabs-container');
        if ($container.length < 1) return;

        // 路径标准化函数
        function normalizePath(url) {
            if (!url) return '';
            let path = url.split('#')[0].split('?')[0]; // 去掉 hash 和参数
            return path.replace(/^\//, '').replace(/\.html$/, ''); // 去掉开头斜杠和 .html 后缀
        }

        // 缓存点击时的标题，供 hashchange 使用
        let lastTitleMap = {};
        $(document).on('click', '[data-open]', function () {
            let url = $(this).attr('data-open') || $(this).data('open');
            let title = $(this).attr('data-title') || $(this).attr('data-target-tips') || $(this).text().trim();
            if (url) {
                // 缓存精确路径和带参数路径
                lastTitleMap[url.split('#')[0]] = title;
                lastTitleMap[normalizePath(url)] = title;
            }
        });

        // 从缓存还原标签
        let data = layui.sessionData('admin-tabs') || {};
        let tabs = data.list || [];

        // 渲染标签函数
        function renderTabs() {
            let $ul = $container.find('.layui-tabs-header').empty();
            let currentHash = location.hash || '#main';
            let currentId = '';

            tabs.forEach(t => {
                let isThis = (t.hash === currentHash || (t.id === 'main' && currentHash === '#main'));
                if (isThis) currentId = t.id;

                let $li = $(`<li lay-id="${t.id}" class="${isThis ? 'layui-this' : ''}"></li>`);
                let $a = $(`<a href="${t.hash}">${t.title}</a>`);
                $li.append($a);
                if (t.id !== 'main') {
                    $li.append('<i class="layui-icon layui-unselect layui-tab-close">ဆ</i>');
                }
                $ul.append($li);
            });

            // 自动滚动到激活项
            if (currentId) {
                let $active = $ul.find(`li[lay-id="${currentId}"]`);
                if ($active.length > 0) {
                    let left = $active.position().left + $ul.scrollLeft() - ($ul.width() / 2) + ($active.width() / 2);
                    $ul.animate({ scrollLeft: left }, 200);
                }
            }
        }

        // 监听点击跳转 (委托)
        $container.on('click', '.layui-tabs-header li a', function (e) {
            e.preventDefault();
            location.hash = $(this).attr('href');
        });

        // 监听删除 (委托)
        $container.on('click', '.layui-tab-close', function (e) {
            e.stopPropagation();
            let id = $(this).parent().attr('lay-id');
            tabs = tabs.filter(t => t.id !== id);
            layui.sessionData('admin-tabs', { key: 'list', value: tabs });
            renderTabs();
        });

        // 监听地址变化并同步标签
        $(window).on('hashchange urlchange', function () {
            let hash = location.hash || '#main';
            let path = hash.indexOf('#') === 0 ? hash.substring(1) : hash;
            let cleanPath = normalizePath(path);
            let id = (hash === '#main' || !cleanPath) ? 'main' : btoa(cleanPath).replace(/=/g, '');

            let tabIndex = tabs.findIndex(t => t.id === id);
            if (tabIndex === -1) {
                // 尝试多级定位标题：1. 精确点击缓存 -> 2. 标准化路径缓存 -> 3. 菜单元素捕捉 -> 4. 默认
                let title = lastTitleMap[path] || lastTitleMap[cleanPath];
                if (!title) {
                    let $menu = $(`a[data-open*="${cleanPath}"]`);
                    title = $menu.attr('data-title') || $menu.attr('data-target-tips') || $menu.find('.nav-text').text() || $menu.find('span:last').text() || '新标签';
                }
                tabs.push({ id, title, hash });
            } else {
                tabs[tabIndex].hash = hash;
            }
            layui.sessionData('admin-tabs', { key: 'list', value: tabs });
            renderTabs();
        }).trigger('hashchange');

        // 右侧工具菜单
        dropdown.render({
            elem: '#think-tabs-tools-btn',
            trigger: 'click',
            data: [
                { title: '刷新当前', id: 'refresh' },
                { title: '关闭其他', id: 'closeOther' },
            ],
            click: function (obj) {
                if (obj.id === 'refresh') return location.reload();
                let currentHash = location.hash || '#main';
                if (obj.id === 'closeAll') {
                    tabs = tabs.filter(t => t.id === 'main');
                    location.hash = '#main';
                } else if (obj.id === 'closeOther') {
                    tabs = tabs.filter(t => t.id === 'main' || t.hash === currentHash);
                }
                layui.sessionData('admin-tabs', { key: 'list', value: tabs });
                renderTabs();
            }
        });
    });

    // 全局 AJAX 登录超时拦截：中间件返回 401 + JSON {code:0, url:loginUrl} 时自动跳转
    $(document).ajaxComplete(function (event, xhr) {
        if (xhr.status === 401) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.code === 0 && res.url) {
                    layui.layer && layui.layer.msg(res.info || '登录超时，请重新登录', { icon: 2, time: 1500 }, function () {
                        location.href = res.url;
                    });
                    return;
                }
            } catch (e) {}
            location.reload();
        }
    });

});


