'use strict';
// 仅补充展示标签；保留表单、节点及既有事件绑定。
(function () {
    document.querySelectorAll('.admin-main table').forEach(function (table) {
        var header = table.tHead ? table.tHead.rows[0] : table.rows[0];
        if (!header || !header.querySelector('th') || header.querySelector('[colspan],[rowspan]')) return;
        var labels = Array.from(header.cells).map(function (cell) { return cell.textContent.trim(); });
        var rows = Array.from(table.rows).filter(function (row) { return row !== header; });
        if (rows.some(function (row) {
            if (row.querySelector('[rowspan],th')) return true;
            if (row.querySelector('[colspan]')) return row.cells.length !== 1 || row.cells[0].colSpan !== labels.length;
            return row.cells.length !== labels.length;
        })) return;
        table.setAttribute('data-admin-responsive', '');
        rows.forEach(function (row) {
            Array.from(row.cells).forEach(function (cell, index) {
                if (cell.hasAttribute('colspan')) return;
                var label = labels[index] || (cell.querySelector('input[type=checkbox]') ? '选择订单' : '操作');
                cell.setAttribute('data-label', label);
                var checkbox = cell.querySelector('input[type=checkbox]');
                if (checkbox && !checkbox.hasAttribute('aria-label')) checkbox.setAttribute('aria-label', label);
            });
        });
    });
    document.querySelectorAll('.admin-main .status,.admin-main .admin-status,.admin-main .admin-announcement-status').forEach(function (badge) {
        var text = badge.textContent.trim();
        if (/^(已发货|已交付|已发布|启用|可用|有效|已奖励|已入账)$/.test(text)) badge.dataset.adminTone = 'good';
        else if (/待|确认中|退款中|草稿|未开始/.test(text)) badge.dataset.adminTone = 'warn';
        else if (/取消|退款|停用|下架|关闭|结束|撤回/.test(text)) badge.dataset.adminTone = 'off';
    });
})();
