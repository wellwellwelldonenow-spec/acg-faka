!function () {
    let table;

    const escapeHtml = (value) => $('<div>').text(value ?? '').html();
    const formatPrice = (value) => {
        const number = Number(value ?? 0);
        return Number.isFinite(number) ? `¥${number}` : `¥${value}`;
    };

    const requestStoreItems = (storeId, data = {}, done = null, options = {}) => {
        util.post({
            url: "/admin/api/store/items",
            data: Object.assign({id: storeId}, data),
            loader: options.loader ?? false,
            done: res => done && done(res.data || {}, res),
            error: res => {
                options.error ? options.error(res) : message.alert(res.msg || "获取远端货源失败", "error");
            },
            fail: () => {
                options.fail ? options.fail() : message.alert("请求远端货源失败，请稍后再试", "error");
            }
        });
    };

    const waitStoreItemsReady = (storeId, done, retry = 0) => {
        requestStoreItems(storeId, {}, data => {
            if (data.building) {
                if (retry === 0) {
                    layer.msg("首次同步远端货源中，请稍候...", {icon: 16, shade: 0.15, time: 1500});
                }

                if (retry >= 30) {
                    message.alert("远端货源仍在同步，请先点“刷新货源”并稍后再试。", "warning");
                    return;
                }

                setTimeout(() => waitStoreItemsReady(storeId, done, retry + 1), 2000);
                return;
            }

            done(data);
        }, {loader: retry === 0});
    };

    const refreshStoreItems = (storeId, done = null) => {
        requestStoreItems(storeId, {refresh: 1}, data => {
            layer.msg("已提交后台刷新，系统将继续展示当前缓存货源", {icon: 16, shade: 0.15, time: 1500});
            done && done(data);
        }, {
            loader: false,
            error: res => message.alert(res.msg || "刷新远端货源失败", "error"),
            fail: () => message.alert("刷新远端货源失败，请稍后再试", "error")
        });
    };

    const openImportPopup = (row, initialData) => {
        component.popup({
            submit: (result, index) => {
                let codes = [];
                try {
                    codes = JSON.parse(result.codes_json || '[]') || [];
                } catch (e) {
                    codes = [];
                }

                if (codes.length === 0) {
                    layer.msg("至少选择一个远端店铺的商品");
                    return;
                }

                delete result.codes_json;
                result.store_id = row.id;

                const chunkSize = result.image_download ? 2 : 5;
                const chunks = [];
                for (let i = 0; i < codes.length; i += chunkSize) {
                    chunks.push(codes.slice(i, i + chunkSize));
                }

                let current = 0;
                let success = 0;
                let fail = 0;
                let loadingIndex = layer.msg(`正在导入货源 0/${chunks.length} 批...`, {
                    icon: 16,
                    shade: 0.15,
                    time: 0
                });

                layer.close(index);

                const runBatch = () => {
                    if (current >= chunks.length) {
                        layer.close(loadingIndex);
                        message.alert(`导入完成，共 ${codes.length} 个商品，成功 ${success} 个，失败 ${fail} 个`, fail > 0 ? "warning" : "success");
                        return;
                    }

                    const payload = Object.assign({}, result, {codes: chunks[current], store_id: row.id});
                    layer.close(loadingIndex);
                    loadingIndex = layer.msg(`正在导入货源 ${current + 1}/${chunks.length} 批...`, {
                        icon: 16,
                        shade: 0.15,
                        time: 0
                    });

                    util.post({
                        url: '/admin/api/store/addItem',
                        data: payload,
                        loader: false,
                        done: () => {
                            success += payload.codes.length;
                            current++;
                            runBatch();
                        },
                        error: () => {
                            fail += payload.codes.length;
                            current++;
                            runBatch();
                        },
                        fail: () => {
                            fail += payload.codes.length;
                            current++;
                            runBatch();
                        }
                    });
                };

                runBatch();
            },
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-link") + " 接入货源",
                    form: [
                        {
                            title: "商品分类",
                            name: "category_id",
                            type: "treeSelect",
                            placeholder: "请选择商品分类",
                            dict: `category->owner=0,id,name,pid&tree=true`,
                            required: true,
                            parent: false
                        },
                        {
                            title: "远端图片本地化",
                            name: "image_download",
                            type: "switch",
                            tips: "启用后，导入对方商品时，会自动将对方所有图片资源下载至本地"
                        },
                        {
                            title: "远端信息同步",
                            name: "shared_sync",
                            type: "switch",
                            tips: "启用后，远端商品信息会实时同步本地，远端价发生变化会立即同步"
                        },
                        {
                            title: "立即上架",
                            name: "shelves",
                            type: "switch",
                            tips: "开启后，入库完毕后会立即上架"
                        },
                        {
                            title: "加价模式",
                            name: "premium_type",
                            type: "radio",
                            dict: [
                                {id: 0, name: "普通金额加价"},
                                {id: 1, name: "百分比加价(99%的人选择)"}
                            ],
                            default: 1,
                            required: true
                        },
                        {
                            title: "加价数额",
                            name: "premium",
                            type: "input",
                            placeholder: "加价金额/百分比(小数代替)",
                            required: true
                        },
                        {
                            title: "远程商品",
                            name: "codes_picker",
                            type: "custom",
                            complete: (form, dom) => {
                                const state = {
                                    page: initialData.page || 1,
                                    limit: initialData.limit || 20,
                                    total: initialData.total || 0,
                                    keyword: '',
                                    category: '',
                                    list: initialData.list || [],
                                    categories: initialData.categories || [],
                                    summary: initialData.summary || {},
                                    selected: {}
                                };

                                const renderCategories = () => {
                                    const options = ['<option value="">全部分类</option>'];
                                    state.categories.forEach(item => {
                                        options.push(`<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)} (${item.count})</option>`);
                                    });
                                    dom.find('.shared-category').html(options.join('')).val(state.category);
                                };

                                const renderSelected = () => {
                                    const values = Object.values(state.selected);
                                    const $list = dom.find('.shared-selected-list');
                                    dom.find('input[name="codes_json"]').val(JSON.stringify(values.map(item => item.code)));
                                    dom.find('.shared-selected-count').text(values.length);

                                    if (values.length === 0) {
                                        $list.html('<div class="text-muted small">暂未选择商品</div>');
                                        return;
                                    }

                                    $list.html(values.map(item => `
<div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-2 bg-white">
    <div>
        <div class="fw-bold">${escapeHtml(item.name)}</div>
        <div class="small text-muted">${escapeHtml(item.category)} · ${escapeHtml(item.code)}</div>
    </div>
    <a class="text-danger shared-remove-item" data-code="${escapeHtml(item.code)}" style="cursor:pointer;">移除</a>
</div>`).join(''));
                                };

                                const renderList = () => {
                                    const $body = dom.find('.shared-items-body');
                                    const totalPages = Math.max(1, Math.ceil((state.total || 0) / state.limit));
                                    dom.find('.shared-page-info').text(`第 ${state.page} / ${totalPages} 页，共 ${state.total} 个商品`);
                                    dom.find('.shared-summary').text(`缓存分类 ${state.summary.category_total || 0} 个，商品 ${state.summary.item_total || 0} 个，更新时间 ${state.summary.generated_at || '未知'}`);

                                    dom.find('.shared-prev-page').prop('disabled', state.page <= 1);
                                    dom.find('.shared-next-page').prop('disabled', state.page >= totalPages);

                                    if (!state.list.length) {
                                        $body.html('<tr><td colspan="6" class="text-center text-muted py-4">当前筛选条件下暂无商品</td></tr>');
                                        return;
                                    }

                                    $body.html(state.list.map(item => {
                                        const checked = !!state.selected[item.code];
                                        return `
<tr>
    <td style="width: 48px;"><input type="checkbox" class="form-check-input shared-item-check" data-code="${escapeHtml(item.code)}" ${checked ? 'checked' : ''}></td>
    <td>
        <div class="fw-bold">${escapeHtml(item.name)}</div>
        <div class="small text-muted">编码：${escapeHtml(item.code)}</div>
    </td>
    <td>${escapeHtml(item.category)}</td>
    <td>${formatPrice(item.price)}</td>
    <td>${formatPrice(item.user_price)}</td>
    <td>${escapeHtml(item.stock || '充足')}</td>
</tr>`;
                                    }).join(''));
                                };

                                const loadPage = (page = 1, loader = false) => {
                                    state.keyword = dom.find('.shared-keyword').val().trim();
                                    state.category = dom.find('.shared-category').val() || '';
                                    requestStoreItems(row.id, {
                                        page,
                                        limit: state.limit,
                                        keyword: state.keyword,
                                        category: state.category
                                    }, data => {
                                        state.page = data.page || 1;
                                        state.total = data.total || 0;
                                        state.list = data.list || [];
                                        state.categories = data.categories || state.categories;
                                        state.summary = data.summary || state.summary;
                                        renderCategories();
                                        renderList();
                                        renderSelected();
                                    }, {loader});
                                };

                                dom.html(`
<div class="shared-items-picker">
    <input type="hidden" name="codes_json" value="[]">
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="text" class="layui-input shared-keyword" placeholder="搜索商品名/分类/编码">
        </div>
        <div class="col-md-3">
            <select class="layui-input shared-category"></select>
        </div>
        <div class="col-md-5 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-primary shared-search-btn">搜索</button>
            <button type="button" class="btn btn-sm btn-light-primary shared-select-page">全选本页</button>
            <button type="button" class="btn btn-sm btn-light-warning shared-clear-page">取消本页</button>
            <button type="button" class="btn btn-sm btn-light-danger shared-refresh-cache">刷新货源缓存</button>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
        <div class="shared-summary"></div>
        <div class="shared-page-info"></div>
    </div>
    <div class="table-responsive border rounded" style="max-height: 360px; overflow: auto;">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>选</th>
                    <th>商品</th>
                    <th>分类</th>
                    <th>游客价</th>
                    <th>会员价</th>
                    <th>库存</th>
                </tr>
            </thead>
            <tbody class="shared-items-body"></tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <div class="small text-muted">建议用搜索筛选后分批导入，避免一次导入过多。</div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light shared-prev-page">上一页</button>
            <button type="button" class="btn btn-sm btn-light shared-next-page">下一页</button>
        </div>
    </div>
    <div class="border rounded p-3 mt-3" style="background: #f8f9fa;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-bold">已选商品 <span class="shared-selected-count">0</span> 个</div>
            <a class="text-danger shared-clear-all" style="cursor:pointer;">清空全部</a>
        </div>
        <div class="shared-selected-list"></div>
    </div>
</div>`);

                                renderCategories();
                                renderList();
                                renderSelected();

                                dom.off('click.shared-search').on('click.shared-search', '.shared-search-btn', () => loadPage(1, true));
                                dom.off('keydown.shared-search').on('keydown.shared-search', '.shared-keyword', event => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        loadPage(1, true);
                                    }
                                });
                                dom.off('change.shared-category').on('change.shared-category', '.shared-category', () => loadPage(1, false));
                                dom.off('click.shared-prev').on('click.shared-prev', '.shared-prev-page', () => {
                                    if (state.page > 1) {
                                        loadPage(state.page - 1, false);
                                    }
                                });
                                dom.off('click.shared-next').on('click.shared-next', '.shared-next-page', () => {
                                    const totalPages = Math.max(1, Math.ceil((state.total || 0) / state.limit));
                                    if (state.page < totalPages) {
                                        loadPage(state.page + 1, false);
                                    }
                                });
                                dom.off('change.shared-check').on('change.shared-check', '.shared-item-check', function () {
                                    const code = $(this).data('code');
                                    const item = state.list.find(entry => entry.code === code);
                                    if (!item) {
                                        return;
                                    }

                                    if ($(this).is(':checked')) {
                                        state.selected[code] = item;
                                    } else {
                                        delete state.selected[code];
                                    }
                                    renderSelected();
                                });
                                dom.off('click.shared-select-page').on('click.shared-select-page', '.shared-select-page', () => {
                                    state.list.forEach(item => {
                                        state.selected[item.code] = item;
                                    });
                                    renderList();
                                    renderSelected();
                                });
                                dom.off('click.shared-clear-page').on('click.shared-clear-page', '.shared-clear-page', () => {
                                    state.list.forEach(item => delete state.selected[item.code]);
                                    renderList();
                                    renderSelected();
                                });
                                dom.off('click.shared-clear-all').on('click.shared-clear-all', '.shared-clear-all', () => {
                                    state.selected = {};
                                    renderList();
                                    renderSelected();
                                });
                                dom.off('click.shared-remove-item').on('click.shared-remove-item', '.shared-remove-item', function () {
                                    const code = $(this).data('code');
                                    delete state.selected[code];
                                    renderList();
                                    renderSelected();
                                });
                                dom.off('click.shared-refresh-cache').on('click.shared-refresh-cache', '.shared-refresh-cache', () => {
                                    refreshStoreItems(row.id, () => {
                                        waitStoreItemsReady(row.id, data => {
                                            state.page = 1;
                                            state.total = data.total || 0;
                                            state.list = data.list || [];
                                            state.categories = data.categories || [];
                                            state.summary = data.summary || {};
                                            renderCategories();
                                            renderList();
                                            renderSelected();
                                        });
                                    });
                                });
                            }
                        }
                    ]
                }
            ],
            assign: {},
            autoPosition: true,
            height: "auto",
            width: "1080px"
        });
    };

    table = new Table("/admin/api/store/data", "#shared-store-table");

    const modal = (title, assign = {}) => {
        component.popup({
            submit: '/admin/api/store/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "协议",
                            name: "type",
                            type: "select",
                            placeholder: "请选择协议",
                            dict: "_shared_type",
                            default: 0,
                            required: true
                        },
                        {
                            title: "店铺地址",
                            name: "domain",
                            type: "input",
                            placeholder: "需要带http://或者https://(推荐,如果支持)",
                            required: true
                        },
                        {
                            title: "商户ID", name: "app_id", type: "input", placeholder: "请输入商户ID",
                            required: true
                        },
                        {
                            title: "商户密钥", name: "app_key", type: "input", placeholder: "请输入商户密钥",
                            required: true
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            assign: assign,
            width: "580px",
            done: () => {
                table.refresh();
            }
        });
    };

    table.setColumns([
        {checkbox: true},
        {
            field: 'name', title: '店铺名称', formatter: (a, b) => {
                return `<span class="table-item"><img src="${b.domain}/favicon.ico" class="table-item-icon"><span class="table-item-name">${a}</span></span>`;
            }
        }, {
            field: 'domain', title: '店铺地址', formatter: format.link
        }, {
            field: 'balance', title: '余额(缓存)', formatter: _ => format.money(_, "green")
        }, {
            field: 'status', title: '状态', formatter: function (val, item) {
                return '<span class="connect-' + item.id + '"><span class="badge badge-light-primary">连接中..</span></span>';
            }
        }, {
            field: 'type', title: '协议', dict: "_shared_type"
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-link',
                    tips: "接入货源",
                    class: "text-primary",
                    click: (event, value, row) => {
                        waitStoreItemsReady(row.id, data => {
                            openImportPopup(row, data);
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-rotate',
                    tips: "刷新货源",
                    class: "text-warning",
                    click: (event, value, row) => {
                        refreshStoreItems(row.id);
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + " 修改远端店铺", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row) => {
                        message.ask("您确定要移除此远端店铺吗，此操作无法恢复", () => {
                            util.post('/admin/api/store/del', {list: [row.id]}, () => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);
    table.setPagination(15, [15, 30]);

    table.onComplete((a, b, c) => {
        c?.data?.list?.forEach(item => {
            $.post("/admin/api/store/connect", {id: item.id}, run => {
                let ins = $(".connect-" + item.id);
                if (run.code == 200) {
                    ins.html(format.badge("正常", "a-badge-success"));
                    $(".items-" + item.id).show();
                } else {
                    ins.html(format.badge(run.msg, "a-badge-danger"));
                }
            });
        });
    });
    table.render();

    $('.btn-app-create').click(function () {
        modal(`${util.icon("fa-duotone fa-regular fa-link")} 添加远端店铺`);
    });
}();
