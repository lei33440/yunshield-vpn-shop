<?php

/**
 * 优惠活动只限制绑定的公共券，不修改订单、核销或退款。
 * 写入顺序固定为 coupon -> campaign；结算调用方先锁券再调用 assertCouponAllowed。
 * 当前 vpn_categories schema 没有 status，存在的分类即为有效分类。
 */
class PromotionCampaignService
{
    private $pdo;

    public function __construct($pdo) { $this->pdo = $pdo; }

    private function now()
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }

    public static function parseId($value, $allowZero = false)
    {
        if (is_int($value)) $value = (string)$value;
        $max = (string)PHP_INT_MAX;
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) || strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0) || (!$allowZero && $value === '0')) {
            throw new InvalidArgumentException('活动、优惠券或方案编号无效。');
        }
        return (int)$value;
    }

    /** 输入及数据库均按北京墙钟解析，禁止依赖 PHP 默认时区和宽松日期修正。 */
    public static function dateValue($value)
    {
        if (!is_string($value) || strlen($value) > 32) throw new InvalidArgumentException('请填写有效的北京时间。');
        $value = str_replace('T', ' ', trim($value));
        if (substr($value, -6) === '+08:00') $value = substr($value, 0, -6);
        $format = strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format($format) !== $value || $date->format('Y') < '1970' || $date->format('Y') > '2099') {
            throw new InvalidArgumentException('请填写1970至2099年内的有效北京时间。');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private static function iso($value)
    {
        return $value ? str_replace(' ', 'T', self::dateValue($value)).'+08:00' : null;
    }

    private function text($input, $key, $limit, $required = false)
    {
        $value = array_key_exists($key, $input) ? $input[$key] : '';
        if (!is_string($value) || strlen($value) > $limit * 4 || !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('活动文字参数无效。');
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $limit) throw new InvalidArgumentException('请检查活动文字长度及必填项。');
        return $value;
    }

    private function queryRow($sql, $params)
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** 返回平铺方案行，id 始终是 product_plans.id，不是商品编号。 */
    public function catalog()
    {
        return $this->pdo->query($this->planSql().' ORDER BY c.sort_order,c.id,p.sort_order,p.id,pp.sort_order,pp.id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function planSql()
    {
        // 与 OrderService::create 的 VPN 规格校验保持一致，目录、发布及结算共用。
        return 'SELECT pp.id,pp.product_id,p.name product_name,pp.name plan_name,pp.price_cents,COALESCE(p.vpn_category_id,0) category_id,COALESCE(c.name,"未分类") category_name,COALESCE(c.description,"") category_description FROM product_plans pp JOIN products p ON p.id=pp.product_id LEFT JOIN vpn_categories c ON c.id=p.vpn_category_id WHERE pp.status="active" AND p.status="active" AND p.product_type="vpn" AND pp.price_cents>0 AND TRIM(p.traffic)<>"" AND TRIM(p.region)<>"" AND p.device_limit>=1 AND p.duration_days>=1 AND pp.duration_days>=1 AND (COALESCE(p.vpn_category_id,0)=0 OR c.id IS NOT NULL)';
    }

    private function selectedPlans($id, $lock = false)
    {
        $s = $this->pdo->prepare($this->planSql().' AND pp.id IN (SELECT product_plan_id FROM promotion_campaign_plans WHERE campaign_id=?) ORDER BY c.sort_order,c.id,p.sort_order,p.id,pp.sort_order,pp.id'.($lock ? ' LOCK IN SHARE MODE' : ''));
        $s->execute(array($id));
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function discount($coupon, $amount)
    {
        $amount = (int)$amount;
        if ($coupon['coupon_type'] === 'percent') {
            $percent = (int)$coupon['discount_percent'];
            if ($percent < 1 || $percent > 100) return 0;
            $discount = intdiv($amount, 100) * $percent + intdiv(($amount % 100) * $percent, 100);
        } elseif ($coupon['coupon_type'] === 'fixed') {
            $discount = (int)$coupon['amount_cents'];
        } else return 0;
        if ((int)$coupon['max_discount_cents'] > 0) $discount = min($discount, (int)$coupon['max_discount_cents']);
        return min($amount, max(0, $discount));
    }

    private function couponValid($coupon, $row, $current = false)
    {
        if (!$coupon || $coupon['scope'] !== 'public' || $coupon['status'] !== 'active' || !empty($coupon['user_id']) || (int)$coupon['per_user_limit'] < 1 || (int)$coupon['total_quantity'] < -1 || (int)$coupon['used_quantity'] < 0 || (int)$coupon['min_amount_cents'] < 0 || (int)$coupon['max_discount_cents'] < 0) return false;
        if ((int)$coupon['total_quantity'] >= 0 && (int)$coupon['used_quantity'] >= (int)$coupon['total_quantity']) return false;
        $start = self::dateValue($row['starts_at']);
        $end = self::dateValue($row['ends_at']);
        if ($start >= $end) return false;
        if (!empty($coupon['starts_at']) && self::dateValue($coupon['starts_at']) > $start) return false;
        if (!empty($coupon['expires_at']) && self::dateValue($coupon['expires_at']) < $end) return false;
        if ($current) {
            $now = $this->now();
            if ((!empty($coupon['starts_at']) && self::dateValue($coupon['starts_at']) > $now) || (!empty($coupon['expires_at']) && self::dateValue($coupon['expires_at']) <= $now)) return false;
        }
        return true;
    }

    private function compatible($coupon, $plan, $amount = null)
    {
        $amount = $amount === null ? (int)$plan['price_cents'] : $amount;
        return (empty($coupon['product_plan_id']) || (int)$coupon['product_plan_id'] === (int)$plan['id']) && (int)$coupon['min_amount_cents'] <= $amount && $this->discount($coupon, $amount) > 0;
    }

    /** 展示和结算唯一适用集合：只读显式勾选的方案，不按分类动态扩展。 */
    private function eligiblePlans($row, $coupon, $lock = false)
    {
        if (!$this->couponValid($coupon, $row)) return array();
        $result = array();
        foreach ($this->selectedPlans($row['id'], $lock) as $plan) {
            if (!$this->compatible($coupon, $plan)) continue;
            $plan['discount_cents'] = $this->discount($coupon, (int)$plan['price_cents']);
            $plan['payable_cents'] = (int)$plan['price_cents'] - $plan['discount_cents'];
            $result[] = $plan;
        }
        return $result;
    }

    private function document($row, $coupon, $plans)
    {
        $s = $this->pdo->prepare('SELECT category_id,description FROM promotion_campaign_categories WHERE campaign_id=?');
        $s->execute(array($row['id']));
        $descriptions = array();
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $category) $descriptions[(int)$category['category_id']] = $category['description'];
        $categories = array();
        foreach ($plans as $plan) {
            $id = (int)$plan['category_id'];
            $categories[$id] = array('id'=>$id, 'name'=>$plan['category_name'], 'description'=>array_key_exists($id, $descriptions) ? $descriptions[$id] : $plan['category_description']);
        }
        $row['state'] = $this->state($row);
        $row['starts_at_iso'] = self::iso($row['starts_at']);
        $row['ends_at_iso'] = self::iso($row['ends_at']);
        $row['server_now'] = self::iso($this->now());
        foreach (array('starts_at','ends_at','created_at','updated_at') as $key) if (isset($row[$key])) $row[$key] = self::iso($row[$key]);
        if ($coupon) foreach (array('starts_at','expires_at','created_at','updated_at') as $key) if (isset($coupon[$key])) $coupon[$key] = self::iso($coupon[$key]);
        $row['coupon'] = $coupon;
        $row['plans'] = $plans;
        $row['categories'] = array_values($categories);
        return $row;
    }

    public function get($id)
    {
        $id = self::parseId($id);
        $row = $this->queryRow('SELECT * FROM promotion_campaigns WHERE id=?', array($id));
        if (!$row) return null;
        $coupon = $this->queryRow('SELECT * FROM coupons WHERE id=?', array($row['coupon_id']));
        // 草稿仍可编辑、预览当前有效勾选项；不可用优惠的发布和结算另行阻断。
        return $this->document($row, $coupon, $this->selectedPlans($id));
    }

    public function state($row)
    {
        if ($row['status'] === 'disabled') return 'disabled';
        if ($row['status'] !== 'published') return 'draft';
        $now = $this->now();
        if (self::dateValue($row['ends_at']) <= $now) return 'ended';
        if (self::dateValue($row['starts_at']) > $now) return 'scheduled';
        return 'active';
    }

    private function audit($admin, $action, $id, $details, $target = 'promotion_campaign')
    {
        $this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($admin,$action,$target,$id,json_encode($details,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),$this->now()));
    }

    private function assertOwnTransaction()
    {
        if ($this->pdo->inTransaction()) throw new LogicException('活动管理必须使用独立事务。');
    }

    /** 新券复用现有写入列及校验，日期先显式转为北京墙钟。 */
    private function createCoupon($input, $row, $admin)
    {
        if (!function_exists('admin_coupon_input')) throw new LogicException('请先加载后台优惠券参数校验模块。');
        $new = isset($input['new_coupon']) ? $input['new_coupon'] : array();
        if (!is_array($new) || count($new) > 16) throw new InvalidArgumentException('新优惠码参数无效。');
        $paid = isset($new['paid_percent']) ? $new['paid_percent'] : '80';
        if (!is_string($paid) || !preg_match('/\A(?:0|[1-9][0-9]?)\z/D', $paid)) throw new InvalidArgumentException('实付百分比须为0至99的整数；80表示八折。');
        $couponInput = array('code'=>$this->text($new,'code',100,true), 'name'=>$this->text($new,'name',160) ?: $row['name'], 'scope'=>'public', 'coupon_type'=>'percent', 'status'=>'active', 'product_plan_id'=>'0', 'amount'=>'0', 'discount_percent'=>(string)(100-(int)$paid));
        foreach (array('total_quantity'=>'-1','per_user_limit'=>'1','min_amount'=>'0','max_discount'=>'0') as $key=>$default) {
            $couponInput[$key] = isset($new[$key]) ? $new[$key] : $default;
            if (!is_string($couponInput[$key]) || strlen($couponInput[$key]) > 32) throw new InvalidArgumentException('新优惠码数量或金额参数无效。');
        }
        $dates = array();
        foreach (array('starts_at'=>'starts_at','expires_at'=>'ends_at') as $key=>$campaignKey) {
            $value = isset($new[$key]) ? $new[$key] : '';
            if (!is_string($value)) throw new InvalidArgumentException('新优惠码日期参数无效。');
            $dates[$key] = $value === '' ? $row[$campaignKey] : self::dateValue($value);
            // 旧校验器日期依赖全局时区；日期改由本服务显式解析，其他规则仍全部复用。
            $couponInput[$key] = '';
        }
        if ($dates['expires_at'] < $dates['starts_at']) throw new InvalidArgumentException('优惠码结束时间不得早于开始时间。');
        $coupon = array_replace(admin_coupon_input($this->pdo, $couponInput), $dates);
        $columns = array_keys($coupon);
        $values = array_values($coupon);
        $values[] = $admin; $values[] = $admin; $values[] = $this->now(); $values[] = $this->now();
        $this->pdo->prepare('INSERT INTO coupons('.implode(',',$columns).',created_by,updated_by,created_at,updated_at) VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute($values);
        $id = (int)$this->pdo->lastInsertId();
        $this->audit($admin, 'create_coupon', $id, array('after'=>$coupon), 'coupon');
        return $id;
    }

    public function save($input, $adminId, $id = 0)
    {
        $id = self::parseId($id, true);
        $adminId = self::parseId($adminId);
        if (!is_array($input) || count($input) > 32) throw new InvalidArgumentException('活动表单参数无效。');
        $row = array('name'=>$this->text($input,'name',160,true), 'title'=>$this->text($input,'title',160,true), 'subtitle'=>$this->text($input,'subtitle',255), 'description'=>$this->text($input,'description',10000));
        foreach (array('starts_at','ends_at') as $key) $row[$key] = self::dateValue(isset($input[$key]) ? $input[$key] : null);
        if ($row['ends_at'] <= $row['starts_at']) throw new InvalidArgumentException('活动结束时间必须晚于开始时间。');
        $plans = isset($input['plan_ids']) ? $input['plan_ids'] : array();
        if (!is_array($plans) || count($plans) > 1000) throw new InvalidArgumentException('最多选择1000个方案。');
        $ids = array();
        foreach ($plans as $plan) $ids[self::parseId($plan)] = true;
        $descriptions = isset($input['category_descriptions']) ? $input['category_descriptions'] : array();
        if (!is_array($descriptions) || count($descriptions) > 1000) throw new InvalidArgumentException('分类介绍参数无效。');
        $categoryText = array();
        foreach ($descriptions as $category=>$description) $categoryText[self::parseId($category,true)] = $this->text(array('description'=>$description),'description',255);
        $mode = isset($input['coupon_mode']) ? $input['coupon_mode'] : 'existing';
        if (!is_string($mode) || !in_array($mode,array('existing','new'),true)) throw new InvalidArgumentException('请选择已有公共码或新建公共码。');
        $version = $id ? self::parseId(isset($input['version_no']) ? $input['version_no'] : null) : 0;
        $before = $id ? $this->queryRow('SELECT * FROM promotion_campaigns WHERE id=?',array($id)) : null;
        if ($id && !$before) throw new InvalidArgumentException('活动不存在。');
        if ($id && $mode !== 'existing') throw new InvalidArgumentException('已有活动的优惠券绑定不可更改。');
        $couponId = $mode === 'existing' ? self::parseId(isset($input['coupon_id']) ? $input['coupon_id'] : null) : 0;
        if ($id && $couponId !== (int)$before['coupon_id']) throw new InvalidArgumentException('已有活动的优惠券绑定不可更改。');
        $this->assertOwnTransaction();
        $this->pdo->beginTransaction();
        try {
            if ($mode === 'new') $couponId = $this->createCoupon($input,$row,$adminId);
            $coupon = $this->queryRow('SELECT * FROM coupons WHERE id=? FOR UPDATE',array($couponId));
            if (!$coupon || $coupon['scope'] !== 'public' || !empty($coupon['user_id'])) throw new InvalidArgumentException('活动只能绑定公共优惠码。');
            if ($id) {
                $before = $this->queryRow('SELECT * FROM promotion_campaigns WHERE id=? FOR UPDATE',array($id));
                if (!$before || (int)$before['coupon_id'] !== $couponId) throw new InvalidArgumentException('活动绑定已变化，请刷新。');
                if ((int)$before['version_no'] !== $version) throw new InvalidArgumentException('活动已被其他操作更新，请另开页面核对最新配置后重试；本次草稿已保留。');
                if ($version >= 2147483647) throw new InvalidArgumentException('活动版本已达上限。');
            }
            $other = $this->queryRow('SELECT id FROM promotion_campaigns WHERE coupon_id=? AND id<>? FOR UPDATE',array($couponId,$id));
            if ($other) throw new InvalidArgumentException('此优惠码已绑定其他活动，不能重复使用。');
            $valid = array();
            foreach ($this->catalog() as $plan) if (isset($ids[(int)$plan['id']])) $valid[(int)$plan['id']] = $plan;
            if (count($valid) !== count($ids)) throw new InvalidArgumentException('部分勾选方案已下架、分类已删除或不是有效VPN方案，请刷新后重新选择。');
            $values = array_values($row);
            $now = $this->now();
            if ($id) {
                $values[]=$adminId; $values[]=$now; $values[]=$id; $values[]=$version;
                $s=$this->pdo->prepare('UPDATE promotion_campaigns SET name=?,title=?,subtitle=?,description=?,starts_at=?,ends_at=?,status="draft",version_no=version_no+1,updated_by=?,updated_at=? WHERE id=? AND version_no=?');
                $s->execute($values);
                if ($s->rowCount() !== 1) throw new InvalidArgumentException('活动版本冲突，请刷新后重试。');
            } else {
                $values[]=$couponId; $values[]=$adminId; $values[]=$adminId; $values[]=$now; $values[]=$now;
                $this->pdo->prepare('INSERT INTO promotion_campaigns(name,title,subtitle,description,starts_at,ends_at,coupon_id,status,version_no,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,"draft",1,?,?,?,?)')->execute($values);
                $id=(int)$this->pdo->lastInsertId();
            }
            $this->pdo->prepare('DELETE FROM promotion_campaign_plans WHERE campaign_id=?')->execute(array($id));
            $this->pdo->prepare('DELETE FROM promotion_campaign_categories WHERE campaign_id=?')->execute(array($id));
            $groups=array();
            foreach ($valid as $plan) {
                $this->pdo->prepare('INSERT INTO promotion_campaign_plans(campaign_id,product_plan_id) VALUES(?,?)')->execute(array($id,$plan['id']));
                $groups[(int)$plan['category_id']]=true;
            }
            foreach ($groups as $category=>$unused) $this->pdo->prepare('INSERT INTO promotion_campaign_categories(campaign_id,category_id,description) VALUES(?,?,?)')->execute(array($id,$category,isset($categoryText[$category]) ? $categoryText[$category] : ''));
            $this->audit($adminId,$before ? 'update_promotion_campaign' : 'create_promotion_campaign',$id,array('before'=>$before,'after'=>$row + array('coupon_id'=>$couponId,'status'=>'draft','plan_ids'=>array_keys($ids),'category_descriptions'=>array_intersect_key($categoryText,$groups))));
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) throw new InvalidArgumentException('优惠码已存在或已绑定其他活动。');
            throw $e;
        }
    }

    private function transition($id, $adminId, $publish)
    {
        $id=self::parseId($id); $adminId=self::parseId($adminId);
        $before=$this->queryRow('SELECT * FROM promotion_campaigns WHERE id=?',array($id));
        if (!$before) throw new InvalidArgumentException('活动不存在。');
        $this->assertOwnTransaction();
        $this->pdo->beginTransaction();
        try {
            $coupon=$this->queryRow('SELECT * FROM coupons WHERE id=? FOR UPDATE',array($before['coupon_id']));
            $row=$this->queryRow('SELECT * FROM promotion_campaigns WHERE id=? FOR UPDATE',array($id));
            if (!$row || (int)$row['coupon_id'] !== (int)$before['coupon_id']) throw new InvalidArgumentException('活动配置已变化，请刷新。');
            if ((int)$row['version_no'] >= 2147483647) throw new InvalidArgumentException('活动版本已达上限。');
            if ($publish) {
                if (self::dateValue($row['ends_at']) <= $this->now() || !$this->couponValid($coupon,$row)) throw new InvalidArgumentException('发布失败：请检查活动时间，以及公共券启用状态、整段有效期、配额和使用限制。');
                $s=$this->pdo->prepare('SELECT product_plan_id FROM promotion_campaign_plans WHERE campaign_id=? LOCK IN SHARE MODE');
                $s->execute(array($id)); $selected=$s->fetchAll(PDO::FETCH_COLUMN);
                $eligible=$this->eligiblePlans($row,$coupon,true);
                if (!$selected || count($eligible) !== count($selected)) throw new InvalidArgumentException('发布失败：全部勾选方案都必须为上架VPN、分类有效，并符合优惠券指定方案、最低消费及有效优惠要求。');
            }
            $status=$publish ? 'published' : 'disabled';
            $this->pdo->prepare('UPDATE promotion_campaigns SET status=?,version_no=version_no+1,updated_by=?,updated_at=? WHERE id=?')->execute(array($status,$adminId,$this->now(),$id));
            $this->audit($adminId,$publish ? 'publish_promotion_campaign' : 'disable_promotion_campaign',$id,array('before'=>$row,'after_status'=>$status));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function publish($id, $adminId) { $this->transition($id,$adminId,true); }
    public function disable($id, $adminId) { $this->transition($id,$adminId,false); }

    public function active()
    {
        $now=$this->now();
        $s=$this->pdo->prepare('SELECT * FROM promotion_campaigns WHERE status="published" AND starts_at<=? AND ends_at>? ORDER BY id DESC');
        $s->execute(array($now,$now));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $coupon=$this->queryRow('SELECT * FROM coupons WHERE id=?',array($row['coupon_id']));
            if (!$this->couponValid($coupon,$row,true)) continue;
            $plans=$this->eligiblePlans($row,$coupon);
            if ($plans) return $this->document($row,$coupon,$plans);
        }
        return null;
    }

    /** 精确活动页面与状态接口使用；不回退到其他活动，也不泄露草稿范围。 */
    public function activeById($id)
    {
        $id=self::parseId($id);
        $row=$this->queryRow('SELECT * FROM promotion_campaigns WHERE id=?',array($id));
        if (!$row || $this->state($row) !== 'active') return null;
        $coupon=$this->queryRow('SELECT * FROM coupons WHERE id=?',array($row['coupon_id']));
        if (!$this->couponValid($coupon,$row,true)) return null;
        $plans=$this->eligiblePlans($row,$coupon);
        return $plans ? $this->document($row,$coupon,$plans) : null;
    }

    /** 返回是否绑定活动；绑定券的当前有效期已按北京时间校验，不能再按默认时区重算。 */
    public function assertCouponAllowed($coupon, $planId, $amount)
    {
        if (!is_array($coupon) || !isset($coupon['id'])) throw new InvalidArgumentException('优惠券参数无效。');
        $couponId=self::parseId($coupon['id']); $planId=self::parseId($planId);
        $amount=self::parseId($amount,true);
        // 活动绑定查询必须是当前读，防止结算事务旧快照漏过刚刚建立的绑定。
        $lock=$this->pdo->inTransaction();
        if ($lock) $coupon=$this->queryRow('SELECT * FROM coupons WHERE id=? FOR UPDATE',array($couponId));
        $row=$this->queryRow('SELECT * FROM promotion_campaigns WHERE coupon_id=?'.($lock ? ' LOCK IN SHARE MODE' : ''),array($couponId));
        if (!$row) return false;
        if ($this->state($row) !== 'active' || !$this->couponValid($coupon,$row,true)) throw new RuntimeException('此优惠码绑定的活动尚未开始、已结束、已停用或配置已失效。');
        foreach ($this->eligiblePlans($row,$coupon,$lock) as $plan) {
            if ((int)$plan['id'] === $planId && $this->compatible($coupon,$plan,$amount)) return true;
        }
        throw new RuntimeException('此优惠码不适用于当前方案或金额，请重新选择活动方案。');
    }
}
