<?php

/** Read-only operational facts, not profit, bank receipts or a cash reconciliation. */
class OperatingMetricsService
{
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }
    private function rows($sql, $args = array())
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    private function scalar($sql, $args = array())
    {
        $rows = $this->rows($sql, $args);
        return (int)reset($rows[0]);
    }
    private static function date($value, $format)
    {
        if (!is_string($value)) throw new InvalidArgumentException('日期格式无效。');
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
        if (!$date || $date->format($format) !== $value) throw new InvalidArgumentException('日期格式无效。');
        return $date;
    }
    public static function range($query)
    {
        if (isset($query['start']) || isset($query['end'])) {
            $start = self::date(isset($query['start']) ? $query['start'] : '', 'Y-m-d');
            $last = self::date(isset($query['end']) ? $query['end'] : '', 'Y-m-d');
            $end = $last->modify('+1 day');
        } else {
            $days = isset($query['days']) ? $query['days'] : '14';
            if ((!is_int($days) && !is_string($days)) || !in_array((string)$days, array('14','30','90'), true)) throw new InvalidArgumentException('请选择14、30或90天。');
            $end = new DateTimeImmutable('tomorrow');
            $start = $end->modify('-'.(int)$days.' days');
            $last = $end->modify('-1 day');
        }
        if ($end <= $start || $start->diff($end)->days > 366) throw new InvalidArgumentException('日期区间须为1至366天。');
        return array('start'=>$start->format('Y-m-d H:i:s'), 'end'=>$end->format('Y-m-d H:i:s'), 'start_date'=>$start->format('Y-m-d'), 'end_date'=>$last->format('Y-m-d'));
    }
    private function mainPredicate()
    {
        return 'o.paid_at IS NOT NULL AND p.order_id=o.id AND p.status IN ("confirmed","refunded") AND p.amount_cents=o.total_amount_cents AND ((p.gateway_type IN ("alipay","wxpay","manual","balance") AND p.amount_cents>0) OR (p.gateway_type="zero" AND p.amount_cents=0))';
    }
    public function snapshot($start, $end)
    {
        $from = self::date($start, 'Y-m-d H:i:s');
        $until = self::date($end, 'Y-m-d H:i:s');
        if ($until <= $from || $from->diff($until)->days > 366) throw new InvalidArgumentException('统计区间须为正向且不超过366天。');
        $args = array($start, $end);
        $totals = array('online_cents'=>0,'manual_cents'=>0,'balance_cents'=>0,'zero_count'=>0,'settlement_cents'=>0,'settlement_count'=>0,'balance_refund_cents'=>0,'external_refund_cents'=>0,'feedback_reward_cents'=>0,'referral_credit_cents'=>0,'referral_reversal_cents'=>0,'admin_adjust_cents'=>0);
        $trend = array();
        for ($day=$from->setTime(0,0); $day<$until; $day=$day->modify('+1 day')) $trend[$day->format('Y-m-d')] = 0;
        // One order -> one explicitly linked primary payment. Other attempts never multiply sales.
        $sales = $this->rows('SELECT DATE(o.paid_at) day,p.gateway_type,SUM(p.amount_cents) cents,COUNT(*) n FROM orders o JOIN payment_records p ON p.id=o.paid_payment_record_id WHERE '.$this->mainPredicate().' AND o.paid_at>=? AND o.paid_at<? GROUP BY DATE(o.paid_at),p.gateway_type', $args);
        foreach ($sales as $r) {
            $amount = (int)$r['cents'];
            $key = in_array($r['gateway_type'], array('alipay','wxpay'), true) ? 'online_cents' : $r['gateway_type'].'_cents';
            if ($r['gateway_type']==='zero') $totals['zero_count'] += (int)$r['n'];
            else $totals[$key] += $amount;
            $totals['settlement_cents'] += $amount;
            $totals['settlement_count'] += (int)$r['n'];
            $trend[$r['day']] += $amount;
        }
        // Wallet ledger is the sole authority for balance refunds, including cancellations.
        foreach ($this->rows('SELECT type,SUM(amount_cents) cents FROM wallet_transactions WHERE created_at>=? AND created_at<? AND type IN ("order_refund","feedback_reward","referral_credit","referral_reversal","admin_adjust") GROUP BY type', $args) as $r) {
            if ($r['type']!=='order_refund') $totals[$r['type'].'_cents'] = (int)$r['cents'];
        }
        $totals['balance_refund_cents'] = $this->scalar('SELECT COALESCE(SUM(amount_cents),0) FROM wallet_transactions WHERE created_at>=? AND created_at<? AND type="order_refund" AND amount_cents>0', $args);
        // Only primary, matched external refunds enter this order refund metric. Late/extra receipts stay anomalies.
        $totals['external_refund_cents'] = $this->scalar('SELECT COALESCE(SUM(r.amount_cents),0) FROM group_payment_refunds r JOIN orders o ON o.id=r.order_id JOIN payment_records p ON p.id=r.payment_id WHERE r.payment_id=o.paid_payment_record_id AND '.$this->mainPredicate().' AND p.gateway_type IN ("alipay","wxpay","manual") AND r.amount_cents=p.amount_cents AND r.channel="external" AND r.status="completed" AND r.processed_at>=? AND r.processed_at<?', $args);
        // Group records own the refund fact in all periods, including pending registrations; never infer their completion from order status.
        $totals['external_refund_cents'] += $this->scalar('SELECT COALESCE(SUM(p.amount_cents),0) FROM orders o JOIN payment_records p ON p.id=o.paid_payment_record_id WHERE '.$this->mainPredicate().' AND p.gateway_type IN ("alipay","wxpay","manual") AND o.status="refunded" AND o.refund_processed_at>=? AND o.refund_processed_at<? AND NOT EXISTS(SELECT 1 FROM group_payment_refunds r WHERE r.payment_id=p.id AND r.channel="external")', $args);
        // Reconciliation backlog deliberately spans history, including records without a usable date.
        $anomalies = array();
        $anomalies['missing_primary'] = $this->scalar('SELECT COUNT(*) FROM orders WHERE (paid_at IS NOT NULL OR status IN ("paid_waiting_group","pending_delivery","delivered","refunding","refunded")) AND (paid_payment_record_id IS NULL OR paid_payment_record_id=0)');
        $anomalies['invalid_primary'] = $this->scalar('SELECT COUNT(*) FROM orders o LEFT JOIN payment_records p ON p.id=o.paid_payment_record_id WHERE o.paid_payment_record_id IS NOT NULL AND o.paid_payment_record_id<>0 AND (p.id IS NULL OR COALESCE(('.$this->mainPredicate().'),0)=0 OR o.paid_at IS NULL)');
        $anomalies['extra_payment'] = $this->scalar('SELECT COUNT(*) FROM payment_records p LEFT JOIN orders o ON o.id=p.order_id WHERE p.status IN ("confirmed","refunded","conflict") AND (o.id IS NULL OR o.paid_payment_record_id IS NULL OR p.id<>o.paid_payment_record_id OR p.status="conflict")');
        $anomalies['undated_refund'] = $this->scalar('SELECT COUNT(*) FROM orders o LEFT JOIN payment_records p ON p.id=o.paid_payment_record_id WHERE o.status="refunded" AND o.refund_processed_at IS NULL AND (p.id IS NULL OR p.gateway_type<>"balance")');
        $anomalies['invalid_group_refund'] = $this->scalar('SELECT COUNT(*) FROM group_payment_refunds r LEFT JOIN payment_records p ON p.id=r.payment_id LEFT JOIN orders o ON o.id=r.order_id WHERE r.channel="external" AND r.status="completed" AND (r.processed_at IS NULL OR p.id IS NULL OR o.id IS NULL OR COALESCE(p.order_id=r.order_id AND r.amount_cents=p.amount_cents AND p.gateway_type IN ("alipay","wxpay","manual") AND p.amount_cents>0 AND p.status IN ("confirmed","refunded"),0)=0 OR (r.payment_id=o.paid_payment_record_id AND (o.paid_at IS NULL OR COALESCE(p.amount_cents=o.total_amount_cents,0)=0)))');
        $anomalies['paid_cancelled_without_refund'] = $this->scalar('SELECT COUNT(*) FROM orders o JOIN payment_records p ON p.id=o.paid_payment_record_id WHERE o.status="cancelled" AND o.paid_at IS NOT NULL AND p.order_id=o.id AND p.gateway_type IN ("alipay","wxpay","manual") AND p.amount_cents>0 AND NOT EXISTS(SELECT 1 FROM group_payment_refunds r WHERE r.payment_id=p.id AND r.channel="external" AND r.status="completed" AND r.processed_at IS NOT NULL AND r.order_id=o.id AND r.amount_cents=p.amount_cents)');
        $anomalies['nonprimary_refund'] = $this->scalar('SELECT COUNT(*) FROM group_payment_refunds r LEFT JOIN orders o ON o.id=r.order_id WHERE r.channel="external" AND (o.id IS NULL OR o.paid_payment_record_id IS NULL OR r.payment_id<>o.paid_payment_record_id)');
        $todos = array();
        foreach (array('pending_delivery','pending_payment','refunding','paid_waiting_group') as $status) $todos[$status] = $this->scalar('SELECT COUNT(*) FROM orders WHERE status=?', array($status));
        $todos['group_external_refunds'] = $this->scalar('SELECT COUNT(*) FROM group_payment_refunds WHERE channel="external" AND status="pending"');
        $todos['tickets'] = $this->scalar('SELECT COUNT(*) FROM support_tickets WHERE status="open"');
        $todos['feedback'] = $this->scalar('SELECT COUNT(*) FROM feedback_submissions WHERE status="pending"');
        $todos['balance_cents'] = $this->scalar('SELECT COALESCE(SUM(balance_cents),0) FROM users');
        return array('totals'=>$totals, 'trend'=>$trend, 'anomalies'=>$anomalies, 'todos'=>$todos);
    }
}
