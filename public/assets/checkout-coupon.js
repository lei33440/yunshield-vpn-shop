(function () {
    'use strict';
    var button = document.getElementById('checkout-validate-coupon');
    var input = document.getElementById('checkout-promotion');
    if (!button || !input) return;
    var form = input.form;
    var result = document.getElementById('checkout-coupon-result');
    var total = document.getElementById('checkout-total');
    var discount = document.getElementById('checkout-discount');
    var totalLabel = document.getElementById('checkout-total-label');
    var mobileTotal = document.getElementById('checkout-mobile-total');
    var mobileLabel = document.getElementById('checkout-mobile-label');
    var original = Number(total.dataset.originalCents);
    var revision = 0;
    function money(cents) { return '¥' + (cents / 100).toFixed(2); }
    function reset() {
        revision++;
        total.textContent = mobileTotal.textContent = money(original);
        discount.textContent = '提交后核算';
        totalLabel.textContent = '优惠前金额';
        mobileLabel.textContent = '优惠前金额 · 提交后核算';
        result.textContent = '优惠码已修改，请重新验证；最终金额以下单时核算为准。';
        button.disabled = false;
        button.textContent = '验证优惠码';
    }
    input.addEventListener('input', reset);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (!button.disabled) button.click();
        }
    });
    button.addEventListener('click', async function () {
        reset();
        var current = revision;
        var code = input.value.trim();
        if (!code) { result.textContent = '请先输入优惠码。'; input.focus(); return; }
        button.disabled = true;
        button.textContent = '验证中…';
        result.textContent = '正在核验优惠码…';
        try {
            var data = new URLSearchParams();
            ['_csrf', 'product_id', 'plan_id'].forEach(function (name) {
                data.set(name, form.elements.namedItem(name).value);
            });
            data.set('promotion_code', code);
            var response = await fetch('/order-confirm/validate-coupon', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }, body: data
            });
            if (current !== revision) return;
            var payload = await response.json();
            if (current !== revision) return;
            if (!response.ok || !payload.ok) {
                result.textContent = payload.message || '优惠码验证失败，请检查后重试。';
                return;
            }
            var amounts = payload.amounts;
            if (!amounts || !Number.isSafeInteger(amounts.original_amount_cents) ||
                !Number.isSafeInteger(amounts.discount_amount_cents) || !Number.isSafeInteger(amounts.total_amount_cents) ||
                amounts.original_amount_cents < 0 || amounts.discount_amount_cents < 0 || amounts.total_amount_cents < 0 ||
                amounts.original_amount_cents - amounts.discount_amount_cents !== amounts.total_amount_cents) {
                throw new Error('invalid_amounts');
            }
            original = amounts.original_amount_cents;
            document.getElementById('checkout-original').textContent = money(original);
            document.getElementById('checkout-product-price').textContent = money(original);
            discount.textContent = '−' + money(amounts.discount_amount_cents);
            total.textContent = mobileTotal.textContent = money(amounts.total_amount_cents);
            totalLabel.textContent = '预计实付';
            mobileLabel.textContent = '预计实付 · 下单时重新核算';
            result.textContent = payload.message;
        } catch (error) {
            if (current === revision) result.textContent = '暂时无法验证，请刷新页面确认登录状态后重试；也可提交订单由系统核算。';
        } finally {
            if (current === revision) {
                button.disabled = false;
                button.textContent = '验证优惠码';
            }
        }
    });
}());
