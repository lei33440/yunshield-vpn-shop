<?php

class RenewalTodoService
{
    private $pdo;
    private $zone;

    public function __construct($pdo, $config = array())
    {
        $this->pdo = $pdo;
        $this->zone = new DateTimeZone($config['app']['timezone'] ?? date_default_timezone_get());
    }

    public static function statuses()
    {
        return array('pending_delivery'=>'续费处理中', 'pending_payment'=>'待付款', 'delivered'=>'续费成功', 'refunding'=>'退款中', 'refunded'=>'已退款', 'cancelled'=>'已取消');
    }

    public static function pendingCount($pdo)
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE order_kind="renewal" AND status="pending_delivery" AND paid_at IS NOT NULL')->fetchColumn();
    }

    private function date($value, $format = 'Y-m-d H:i:s')
    {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $this->zone);
        return $date && $date->format($format) === $value && (int)$date->format('Y') >= 1000 && (int)$date->format('Y') <= 9999 ? $date : null;
    }

    public function listing($input)
    {
        $filters = array('status'=>'pending_delivery', 'q'=>'', 'from'=>'', 'to'=>'', 'page'=>'1');
        foreach ($filters as $key=>$default) {
            if (array_key_exists($key, $input)) {
                if (!is_string($input[$key])) throw new InvalidArgumentException('筛选条件无效。');
                $filters[$key] = trim($input[$key]);
            }
        }
        if (!isset(self::statuses()[$filters['status']]) || !preg_match('/\A[1-9][0-9]{0,8}\z/D', $filters['page'])) throw new InvalidArgumentException('筛选条件无效。');
        if (strlen($filters['q']) > 40 || ($filters['q'] !== '' && !preg_match('/\A[A-Za-z0-9_-]+\z/D', $filters['q']))) throw new InvalidArgumentException('订单号条件无效。');
        $dates = array();
        foreach (array('from','to') as $key) {
            if ($filters[$key] === '') continue;
            $dates[$key] = $this->date($filters[$key], 'Y-m-d');
            if (!$dates[$key] || ($key === 'to' && $filters[$key] === '9999-12-31')) throw new InvalidArgumentException('日期条件无效。');
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) throw new InvalidArgumentException('日期范围无效。');
        $where = 'o.order_kind="renewal" AND o.status=?';
        $args = array($filters['status']);
        if ($filters['status'] === 'pending_delivery') $where .= ' AND o.paid_at IS NOT NULL';
        if ($filters['q'] !== '') {
            $where .= " AND (o.order_no LIKE ? ESCAPE '!' OR p.order_no LIKE ? ESCAPE '!')";
            $keyword = '%'.str_replace('_', '!_', $filters['q']).'%';
            $args[] = $keyword; $args[] = $keyword;
        }
        if (isset($dates['from'])) { $where .= ' AND o.paid_at>=?'; $args[] = $dates['from']->format('Y-m-d H:i:s'); }
        if (isset($dates['to'])) { $where .= ' AND o.paid_at<?'; $args[] = $dates['to']->modify('+1 day')->format('Y-m-d H:i:s'); }
        $base = ' FROM orders o LEFT JOIN orders p ON p.id=o.parent_order_id';
        $s = $this->pdo->prepare('SELECT COUNT(*)'.$base.' WHERE '.$where); $s->execute($args);
        $total = (int)$s->fetchColumn(); $pages = max(1, (int)ceil($total / 50)); $page = min($pages, (int)$filters['page']);
        // 聚合只用于判断唯一性；有且仅有一条 current 时才关联其安全日期字段。
        $sql = 'SELECT o.id,o.order_no,o.status,o.total_amount_cents,o.duration_days_snapshot,o.paid_at,o.user_id,o.product_id,
            p.order_no AS parent_order_no,p.user_id AS parent_user_id,p.product_id AS parent_product_id,p.status AS parent_status,
            u.display_name,u.email,r.duration_days,r.price_cents,
            c.current_count,d.version_no,d.opened_at,d.expires_at'.$base.'
            LEFT JOIN users u ON u.id=o.user_id
            LEFT JOIN order_renewals r ON r.order_id=o.id
            LEFT JOIN (SELECT order_id,COUNT(*) AS current_count,MIN(id) AS current_id FROM delivery_records WHERE is_current=1 GROUP BY order_id) c ON c.order_id=p.id
            LEFT JOIN delivery_records d ON d.id=c.current_id AND c.current_count=1
            WHERE '.$where.' ORDER BY o.paid_at ASC,o.id ASC LIMIT 50 OFFSET '.(($page-1)*50);
        $s = $this->pdo->prepare($sql); $s->execute($args);
        $now = new DateTimeImmutable('now', $this->zone); $items = array();
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $items[] = $this->present($row, $now);
        return array('filters'=>$filters, 'items'=>$items, 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'as_of'=>$now->format('Y-m-d H:i:s'));
    }

    private function present($row, $now)
    {
        $issues = array();
        if (!$row['parent_order_no'] || (string)$row['user_id'] !== (string)$row['parent_user_id'] || (string)$row['product_id'] !== (string)$row['parent_product_id']) $issues[] = '原订单关联异常';
        if ($row['parent_status'] !== 'delivered') $issues[] = '原订单非已交付状态';
        if ($row['email'] === null) $issues[] = '客户资料缺失';
        if ($row['price_cents'] === null || (string)$row['price_cents'] !== (string)$row['total_amount_cents']) $issues[] = '续费金额元数据异常';
        $days = filter_var($row['duration_days'], FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1, 'max_range'=>intdiv(PHP_INT_MAX,86400))));
        if ($days === false || (string)$row['duration_days'] !== (string)$row['duration_days_snapshot']) $issues[] = '续费时长元数据异常';
        $opened = $this->date($row['opened_at']); $expiry = $this->date($row['expires_at']);
        if ((int)$row['current_count'] !== 1) $issues[] = '当前交付缺失或不唯一';
        elseif (!$opened || !$expiry || $expiry <= $opened || $opened > $now) $issues[] = '当前交付日期异常';
        $waiting = '不适用'; $preview = '不适用';
        if ($row['status'] === 'pending_delivery') {
            $paid = $this->date($row['paid_at']);
            if (!$paid || $paid > $now) { $waiting = '无法计算，请核对付款时间'; $issues[] = '付款时间异常'; }
            else {
                $seconds = $now->getTimestamp() - $paid->getTimestamp();
                $waiting = intdiv($seconds,86400).' 天 '.intdiv($seconds%86400,3600).' 小时 '.intdiv($seconds%3600,60).' 分钟';
            }
            $preview = '无法预览，请人工核对';
            if (!$issues) {
                $start = $expiry > $now ? $expiry : $now;
                $timestamp = $start->getTimestamp() + $days*86400;
                if (is_int($timestamp) && $timestamp > $start->getTimestamp()) {
                    $end = $start->setTimestamp($timestamp);
                    if ((int)$end->format('Y') <= 9999) $preview = $end->format('Y-m-d H:i:s');
                }
                if ($preview === '无法预览，请人工核对') $issues[] = '预计到期超出范围';
            }
        }
        return array('order_no'=>$row['order_no'], 'parent_order_no'=>$row['parent_order_no'], 'customer_name'=>$row['display_name'], 'customer_email'=>$row['email'],
            'status_label'=>self::statuses()[$row['status']], 'amount_cents'=>$row['total_amount_cents'], 'duration_days'=>$days === false ? null : $days,
            'paid_at'=>$row['paid_at'], 'waiting'=>$waiting, 'expires_at'=>$expiry ? $expiry->format('Y-m-d H:i:s') : null,
            'preview'=>$preview, 'issues'=>implode('；', $issues));
    }
}
