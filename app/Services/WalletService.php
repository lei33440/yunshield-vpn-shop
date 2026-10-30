<?php

class WalletService
{
    const MAX_BALANCE_CENTS = 999999999;
    private $pdo;

    public function __construct($pdo) { $this->pdo = $pdo; }

    public static function parseAmount($amount)
    {
        if (!is_string($amount) || !preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/D', $amount)) {
            throw new InvalidArgumentException('金额须为非负十进制数字，最多两位小数。');
        }
        $parts = explode('.', $amount);
        if (strlen($parts[0]) > 7) throw new InvalidArgumentException('金额超过钱包上限。');
        $cents = ((int)$parts[0]) * 100 + (int)str_pad(isset($parts[1]) ? $parts[1] : '', 2, '0');
        if ($cents > self::MAX_BALANCE_CENTS) throw new InvalidArgumentException('金额超过钱包上限。');
        return $cents;
    }

    public static function formatAmount($cents)
    {
        if ((!is_int($cents) && !is_string($cents)) || !preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', (string)$cents)) {
            throw new InvalidArgumentException('金额必须使用整数分。');
        }
        // 累计金额展示不受单钱包余额上限约束，也不转换为浮点数。
        $digits = str_pad(ltrim((string)$cents, '-'), 3, '0', STR_PAD_LEFT);
        return (strpos((string)$cents, '-') === 0 && $digits !== '000' ? '-' : '') . substr($digits, 0, -2) . '.' . substr($digits, -2);
    }

    private static function integerCents($value, $signed = false)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match($signed ? '/\A-?(?:0|[1-9][0-9]*)\z/D' : '/\A(?:0|[1-9][0-9]*)\z/D', (string)$value)) {
            throw new InvalidArgumentException('金额必须使用整数分。');
        }
        $digits = ltrim((string)$value, '-');
        if (strlen($digits) > 9 || (int)$digits > self::MAX_BALANCE_CENTS) throw new InvalidArgumentException('金额超过钱包上限。');
        return (int)$value;
    }

    private static function positiveId($value)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$value) || strlen((string)$value) > strlen((string)PHP_INT_MAX) || (strlen((string)$value) === strlen((string)PHP_INT_MAX) && strcmp((string)$value, (string)PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException('记录编号无效。');
        }
        return (int)$value;
    }

    public function balance($userId)
    {
        $s = $this->pdo->prepare('SELECT balance_cents FROM users WHERE id=?');
        $s->execute(array(self::positiveId($userId)));
        $value = $s->fetchColumn();
        if ($value === false) throw new RuntimeException('用户不存在。');
        return self::integerCents($value);
    }

    public function transactions($userId, $page = 1, $perPage = 20)
    {
        $userId = self::positiveId($userId);
        $page = max(1, min(1000000, (int)$page));
        $perPage = max(1, min(100, (int)$perPage));
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?');
        $s->execute(array($userId));
        $total = (int)$s->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $s = $this->pdo->prepare('SELECT id,user_id,amount_cents,balance_before_cents,balance_after_cents,type,order_id,payment_id,admin_id,reason,created_at FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $s->execute(array($userId));
        return array('items'=>$s->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total, 'page'=>$page, 'per_page'=>$perPage, 'pages'=>$pages);
    }

    // Feedback rewards are called inside the caller's transaction after the feedback row is locked.
    public function rewardFeedback($userId, $feedbackId, $rewardCents, $adminId, $reason)
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('反馈奖励必须处于调用方事务内。');
        $userId = self::positiveId($userId);
        $feedbackId = self::positiveId($feedbackId);
        $adminId = self::positiveId($adminId);
        $rewardCents = self::integerCents($rewardCents);
        if ($rewardCents <= 0) throw new InvalidArgumentException('奖励金额必须大于零。');
        if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason), 'UTF-8') > 500) throw new InvalidArgumentException('请填写不超过500字的奖励说明。');
        // Re-read the locked feedback as the authority; callers cannot mint a reward by supplying arbitrary IDs.
        $s = $this->pdo->prepare('SELECT user_id,status,reward_cents FROM feedback_submissions WHERE id=? FOR UPDATE');
        $s->execute(array($feedbackId));
        $feedback = $s->fetch(PDO::FETCH_ASSOC);
        if (!$feedback || (int)$feedback['user_id'] !== $userId || $feedback['status'] !== 'pending' || (int)$feedback['reward_cents'] !== 0) throw new RuntimeException('反馈状态、归属或奖励金额不允许发放。');
        $balance = $this->lockUser($userId);
        return $this->apply($userId, $rewardCents, $balance, 'feedback_reward', null, null, $adminId, trim($reason), 'feedback_reward:' . $feedbackId);
    }

    public function adjust($userId, $signedCents, $reason, $requestId, $adminId)
    {
        $userId = self::positiveId($userId);
        $adminId = self::positiveId($adminId);
        $signedCents = self::integerCents($signedCents, true);
        if ($signedCents === 0) throw new InvalidArgumentException('调账金额必须大于零。');
        if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason), 'UTF-8') > 500) throw new InvalidArgumentException('请填写不超过500字的调账原因。');
        $reason = trim($reason);
        if (!is_string($requestId) || !preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D', $requestId)) throw new InvalidArgumentException('调账请求标识无效，请刷新页面。');
        if ($this->pdo->inTransaction()) throw new LogicException('管理员调账不能嵌套事务。');
        $this->pdo->beginTransaction();
        try {
            $balance = $this->lockUser($userId);
            $row = $this->apply($userId, $signedCents, $balance, 'admin_adjust', null, null, $adminId, $reason, 'admin_adjust:' . $adminId . ':' . $requestId);
            $this->pdo->commit();
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    // 调用方负责事务，须先锁定订单，再按用户 ID 升序锁定所有相关用户。
    public function debitOrder($order, $paymentId)
    {
        return $this->orderMutation($order, $paymentId, false, null);
    }

    public function refundOrder($order, $paymentId, $adminId = null)
    {
        return $this->orderMutation($order, $paymentId, true, $adminId);
    }

    private function orderMutation($order, $paymentId, $refund, $adminId)
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('余额支付及退款必须处于调用方事务内。');
        if (!is_array($order) || !isset($order['id'], $order['user_id'], $order['total_amount_cents'])) throw new InvalidArgumentException('订单参数无效。');
        $orderId = self::positiveId($order['id']);
        $userId = self::positiveId($order['user_id']);
        $paymentId = self::positiveId($paymentId);
        $amount = self::integerCents($order['total_amount_cents']);
        if ($amount === 0) throw new InvalidArgumentException('零元订单不应写入余额账本。');
        $adminId = $adminId === null ? null : self::positiveId($adminId);
        $balance = $this->lockUser($userId);
        $s = $this->pdo->prepare('SELECT id,order_id,amount_cents,gateway_type,status FROM payment_records WHERE id=? FOR UPDATE');
        $s->execute(array($paymentId));
        $payment = $s->fetch(PDO::FETCH_ASSOC);
        if (!$payment || (int)$payment['order_id'] !== $orderId || self::integerCents($payment['amount_cents']) !== $amount || $payment['gateway_type'] !== 'balance') throw new RuntimeException('余额付款记录与订单不一致。');
        if (!in_array($payment['status'], $refund ? array('confirmed','refunded') : array('pending','confirmed'), true)) throw new RuntimeException('付款记录状态不允许此余额操作。');
        if ($refund) {
            $s = $this->pdo->prepare('SELECT * FROM wallet_transactions WHERE idempotency_key=? FOR UPDATE');
            $s->execute(array('order_payment:' . $orderId));
            $debit = $s->fetch(PDO::FETCH_ASSOC);
            if (!$debit || (int)$debit['user_id'] !== $userId || (int)$debit['order_id'] !== $orderId || (int)$debit['payment_id'] !== $paymentId || (int)$debit['amount_cents'] !== -$amount || $debit['type'] !== 'order_payment') throw new RuntimeException('未找到匹配的原余额扣款，不可退款。');
        }
        return $this->apply($userId, $refund ? $amount : -$amount, $balance, $refund ? 'order_refund' : 'order_payment', $orderId, $paymentId, $adminId, $refund ? '余额支付订单退款' : '订单余额支付', ($refund ? 'order_refund:' . $paymentId : 'order_payment:' . $orderId));
    }

    // 调用方须先锁定订单，再按用户 ID 升序锁定全部相关用户，最后锁佣金。
    // 这里只重读已持有的用户锁以取得当前余额，不负责扩大用户锁集合或管理事务。
    public function creditReferralCommission($commission)
    {
        return $this->referralMutation($commission, false);
    }

    public function reverseReferralCommission($commission)
    {
        return $this->referralMutation($commission, true);
    }

    private function referralMutation($commission, $reverse)
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('返佣入账及追回必须处于调用方事务内。');
        if (!is_array($commission) || !isset($commission['id'], $commission['referrer_user_id'], $commission['order_id'], $commission['amount_cents'], $commission['settlement_mode'])) throw new InvalidArgumentException('佣金参数无效。');
        $commissionId = self::positiveId($commission['id']);
        $userId = self::positiveId($commission['referrer_user_id']);
        $orderId = self::positiveId($commission['order_id']);
        $amount = self::integerCents($commission['amount_cents']);
        if ($amount === 0 || $commission['settlement_mode'] !== 'wallet') throw new InvalidArgumentException('仅正金额余额佣金可以操作钱包。');
        $s = $this->pdo->prepare('SELECT * FROM referral_commissions WHERE id=? FOR UPDATE');
        $s->execute(array($commissionId));
        $stored = $s->fetch(PDO::FETCH_ASSOC);
        if (!$stored || self::positiveId($stored['referrer_user_id']) !== $userId || self::positiveId($stored['order_id']) !== $orderId || self::integerCents($stored['amount_cents']) !== $amount || $stored['settlement_mode'] !== 'wallet') throw new RuntimeException('佣金受益人、金额或订单与原记录不一致。');
        if (!in_array($stored['status'], $reverse ? array('credited', 'reversed') : array('pending_credit', 'credited'), true)) throw new RuntimeException('佣金状态不允许此钱包操作。');
        $creditKey = 'referral_credit:' . $commissionId;
        $s = $this->pdo->prepare('SELECT * FROM wallet_transactions WHERE idempotency_key=? FOR UPDATE');
        $s->execute(array($creditKey));
        $credit = $s->fetch(PDO::FETCH_ASSOC);
        if ($credit) $this->assertReferralCredit($credit, $userId, $orderId, $amount);
        if (($reverse || $stored['status'] === 'credited') && !$credit) throw new RuntimeException('未找到匹配的原返佣入账流水。');
        $type = $reverse ? 'referral_reversal' : 'referral_credit';
        $reason = $reverse ? '好友订单退款返佣追回' : '好友订单付款返佣入余额';
        $key = $reverse ? 'referral_reversal:' . $commissionId : $creditKey;
        $existing = $credit;
        if ($reverse) {
            $s = $this->pdo->prepare('SELECT * FROM wallet_transactions WHERE idempotency_key=? FOR UPDATE');
            $s->execute(array($key));
            $existing = $s->fetch(PDO::FETCH_ASSOC);
        }
        // 必须是当前读；调用方已按升序持有此用户锁，不能在这里新增锁定用户。
        $balance = $this->lockUser($userId);
        // 已存在的幂等流水仍交给 apply 校验不可变参数，不受当前余额或容量影响。
        if (!$existing) {
            if ($reverse && $balance < $amount) return array('status'=>'pending_recovery', 'transaction'=>null);
            if (!$reverse && $balance > self::MAX_BALANCE_CENTS - $amount) return array('status'=>'pending_credit', 'transaction'=>null);
        }
        $transaction = $this->apply($userId, $reverse ? -$amount : $amount, $balance, $type, $orderId, null, null, $reason, $key);
        return array('status'=>$reverse ? 'recovered' : 'credited', 'transaction'=>$transaction);
    }

    private function assertReferralCredit($credit, $userId, $orderId, $amount)
    {
        foreach (array('user_id'=>$userId, 'amount_cents'=>$amount, 'type'=>'referral_credit', 'order_id'=>$orderId, 'payment_id'=>null, 'admin_id'=>null, 'reason'=>'好友订单付款返佣入余额') as $field=>$value) {
            if (!array_key_exists($field, $credit) || ($credit[$field] === null) !== ($value === null) || (string)$credit[$field] !== (string)$value) throw new RuntimeException('原返佣入账流水与佣金不一致。');
        }
    }

    private function lockUser($userId)
    {
        $s = $this->pdo->prepare('SELECT balance_cents FROM users WHERE id=? FOR UPDATE');
        $s->execute(array($userId));
        $balance = $s->fetchColumn();
        if ($balance === false) throw new RuntimeException('用户不存在。');
        return self::integerCents($balance);
    }

    private function apply($userId, $amount, $before, $type, $orderId, $paymentId, $adminId, $reason, $key)
    {
        $s = $this->pdo->prepare('SELECT * FROM wallet_transactions WHERE idempotency_key=? FOR UPDATE');
        $s->execute(array($key));
        $existing = $s->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            foreach (array('user_id'=>$userId, 'amount_cents'=>$amount, 'type'=>$type, 'order_id'=>$orderId, 'payment_id'=>$paymentId, 'admin_id'=>$adminId, 'reason'=>$reason) as $field=>$value) {
                if (($existing[$field] === null) !== ($value === null) || (string)$existing[$field] !== (string)$value) throw new RuntimeException('此幂等请求已用于不同参数，拒绝重复入账。');
            }
            return $existing;
        }
        if ($amount < 0 && $before < -$amount) throw new RuntimeException('余额不足。');
        if ($amount > 0 && $before > self::MAX_BALANCE_CENTS - $amount) throw new RuntimeException('操作后余额超过钱包上限。');
        $after = $before + $amount;
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE users SET balance_cents=? WHERE id=?')->execute(array($after, $userId));
        $this->pdo->prepare('INSERT INTO wallet_transactions(user_id,amount_cents,balance_before_cents,balance_after_cents,type,order_id,payment_id,admin_id,reason,idempotency_key,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(array($userId,$amount,$before,$after,$type,$orderId,$paymentId,$adminId,$reason,$key,$now));
        $id = $this->pdo->lastInsertId();
        if ($adminId !== null) {
            $details = array('wallet_transaction_id'=>$id, 'amount_cents'=>$amount, 'balance_before_cents'=>$before, 'balance_after_cents'=>$after, 'order_id'=>$orderId, 'payment_id'=>$paymentId, 'reason'=>$reason, 'idempotency_key'=>$key);
            $this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($adminId, $type, 'user', $userId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now));
        }
        return array('id'=>$id,'user_id'=>$userId,'amount_cents'=>$amount,'balance_before_cents'=>$before,'balance_after_cents'=>$after,'type'=>$type,'order_id'=>$orderId,'payment_id'=>$paymentId,'admin_id'=>$adminId,'reason'=>$reason,'idempotency_key'=>$key,'created_at'=>$now);
    }
}
