<?php

function register_admin_wallet_routes($router, $pdo, $config)
{
    $router->get('/admin/users/{id}/wallet', function($id) use ($pdo, $config) {
        Security::requireAdmin();
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $id)) { http_response_code(404); return; }
        $s = $pdo->prepare('SELECT id,email,display_name FROM users WHERE id=?');
        $s->execute(array($id));
        $user = $s->fetch(PDO::FETCH_ASSOC);
        if (!$user) { http_response_code(404); page('用户不存在', '<div class="admin-info-banner">用户不存在。</div>', $config); return; }
        $wallet = new WalletService($pdo);
        $balance = $wallet->balance($user['id']);
        $page = isset($_GET['page']) && is_string($_GET['page']) && ctype_digit($_GET['page']) ? (int)$_GET['page'] : 1;
        $ledger = $wallet->transactions($user['id'], $page, 20);
        $token = bin2hex(random_bytes(24));
        if (!isset($_SESSION['_wallet_adjust_tokens']) || !is_array($_SESSION['_wallet_adjust_tokens'])) $_SESSION['_wallet_adjust_tokens'] = array();
        $_SESSION['_wallet_adjust_tokens'][$token] = (string)$user['id'];
        while (count($_SESSION['_wallet_adjust_tokens']) > 40) array_shift($_SESSION['_wallet_adjust_tokens']);
        $url = '/admin/users/' . (int)$user['id'] . '/wallet';
        $body = admin_heading('用户余额', '用户 #' . $user['id'] . ' · ' . $user['email'], '<a class="secondary-button" href="/admin/users">返回用户管理</a>');
        $body .= '<div class="admin-info-banner">当前余额：<strong>¥' . e(WalletService::formatAmount($balance)) . '</strong>。新邀请返佣自动进入消费余额，历史佣金独立保留；支持人工调账及全额余额支付，不提供在线充值或余额提现。</div>';
        $body .= '<section class="product-form-card"><div class="product-form-heading"><div><h2>人工调账</h2><p>每次调整均记录原因及管理员审计；扣款不得超过当前余额。</p></div></div><form method="post" action="' . e($url . '/adjust') . '">' . csrf_field() . '<input type="hidden" name="request_id" value="' . e($token) . '"><div class="product-form-grid"><div class="product-form-section"><label>调整方向<select name="direction"><option value="credit">增加余额</option><option value="debit">扣减余额</option></select></label><label>金额（元）<input name="amount" type="text" inputmode="decimal" placeholder="0.00" maxlength="10" pattern="(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?" required></label></div><div class="product-form-section"><label>调账原因<textarea name="reason" rows="3" maxlength="500" required></textarea></label><p>单次及账户余额上限 ¥' . e(WalletService::formatAmount(WalletService::MAX_BALANCE_CENTS)) . '。</p></div></div><div class="product-form-actions"><button class="button" type="submit" data-confirm="确认按填写金额和原因调整此用户余额？">确认调账</button></div></form></section>';
        $rows = '';
        $types = array('admin_adjust'=>'管理员调账','order_payment'=>'订单余额付款','order_refund'=>'余额订单退款','referral_credit'=>'邀请返佣','referral_reversal'=>'返佣撤回','feedback_reward'=>'反馈奖励');
        foreach ($ledger['items'] as $row) {
            $rows .= '<tr><td>#' . (int)$row['id'] . '<br><small>' . e($row['created_at']) . '</small></td><td>' . e(isset($types[$row['type']]) ? $types[$row['type']] : $row['type']) . '</td><td>' . ((int)$row['amount_cents'] > 0 ? '+' : '') . e(WalletService::formatAmount($row['amount_cents'])) . '</td><td>' . e(WalletService::formatAmount($row['balance_before_cents'])) . ' → ' . e(WalletService::formatAmount($row['balance_after_cents'])) . '</td><td>' . ($row['order_id'] ? '#' . (int)$row['order_id'] : '—') . '</td><td>' . ($row['admin_id'] ? '#' . (int)$row['admin_id'] : '—') . '</td><td>' . e($row['reason']) . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="7" class="admin-module-empty">暂无余额流水。</td></tr>';
        $body .= '<section class="admin-panel"><div class="admin-panel-head"><div><h2>余额流水</h2><p>共 ' . (int)$ledger['total'] . ' 条 · 第 ' . (int)$ledger['page'] . '/' . (int)$ledger['pages'] . ' 页</p></div></div><div class="table-wrap"><table><thead><tr><th>流水 / 时间</th><th>类型</th><th>变动（元）</th><th>余额前后（元）</th><th>订单ID</th><th>管理员ID</th><th>原因</th></tr></thead><tbody>' . $rows . '</tbody></table></div><div class="product-form-actions">';
        if ($ledger['page'] > 1) $body .= '<a class="secondary-button" href="' . e($url . '?page=' . ($ledger['page'] - 1)) . '">上一页</a>';
        if ($ledger['page'] < $ledger['pages']) $body .= '<a class="secondary-button" href="' . e($url . '?page=' . ($ledger['page'] + 1)) . '">下一页</a>';
        page('用户余额', '<div class="admin-wallet-page">' . $body . '</div></section></div>', $config);
    });

    $router->post('/admin/users/{id}/wallet/adjust', function($id) use ($pdo) {
        $adminId = Security::requireAdmin();
        post_csrf();
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $id)) { http_response_code(404); return; }
        $url = '/admin/users/' . (int)$id . '/wallet';
        try {
            $token = isset($_POST['request_id']) ? $_POST['request_id'] : null;
            if (!is_string($token) || !isset($_SESSION['_wallet_adjust_tokens'][$token]) || $_SESSION['_wallet_adjust_tokens'][$token] !== (string)(int)$id) throw new InvalidArgumentException('调账请求已过期或用户不匹配，请刷新页面重试。');
            $direction = isset($_POST['direction']) ? $_POST['direction'] : '';
            if (!in_array($direction, array('credit','debit'), true)) throw new InvalidArgumentException('请选择正确的调账方向。');
            $amount = WalletService::parseAmount(isset($_POST['amount']) ? $_POST['amount'] : null);
            (new WalletService($pdo))->adjust((int)$id, $direction === 'debit' ? -$amount : $amount, isset($_POST['reason']) ? $_POST['reason'] : null, $token, $adminId);
            // 保留令牌以支持网络重试；不可变账本会拒绝同一令牌用于不同参数。
            flash('success', '余额调账已处理。相同请求不会重复入账。');
        } catch (PDOException $e) {
            flash('error', '调账未完成，请稍后重试；重复提交不会重复入账。');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect($url);
    });
}
