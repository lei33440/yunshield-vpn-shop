<?php

/** 后台模块由主入口统一加载，不在此 require 其他模块。 */
function admin_promotion_label($state)
{
    $labels=array('draft'=>'草稿','scheduled'=>'待开始','active'=>'进行中','ended'=>'已结束','disabled'=>'已停用','published'=>'已发布');
    return isset($labels[$state]) ? $labels[$state] : '未知状态';
}

function admin_promotion_assets()
{
    $file=dirname(__DIR__,2).'/public/assets/promotion-admin.css';
    return '<link rel="stylesheet" href="/assets/promotion-admin.css?v='.(int)filemtime($file).'">';
}

/** 仅保留活动白名单，不保存 CSRF、会话、密码等任意 POST 参数。 */
function admin_promotion_safe_draft($input)
{
    if (!is_array($input)) return array();
    $result=array();
    $limits=array('name'=>640,'title'=>640,'subtitle'=>1020,'description'=>40000,'starts_at'=>32,'ends_at'=>32,'coupon_mode'=>16,'coupon_id'=>20,'version_no'=>20);
    foreach ($limits as $key=>$limit) {
        if (isset($input[$key]) && is_string($input[$key]) && strlen($input[$key]) <= $limit && mb_check_encoding($input[$key],'UTF-8')) $result[$key]=$input[$key];
    }
    $result['plan_ids']=array();
    if (isset($input['plan_ids']) && is_array($input['plan_ids'])) {
        foreach (array_slice($input['plan_ids'],0,1000) as $id) {
            try { $result['plan_ids'][]=(string)PromotionCampaignService::parseId($id); } catch (InvalidArgumentException $e) { /* 畸形编号不进入会话。 */ }
        }
    }
    $result['category_descriptions']=array();
    if (isset($input['category_descriptions']) && is_array($input['category_descriptions'])) {
        foreach (array_slice($input['category_descriptions'],0,1000,true) as $id=>$text) {
            try { $id=PromotionCampaignService::parseId($id,true); } catch (InvalidArgumentException $e) { continue; }
            if (is_string($text) && strlen($text)<=1020 && mb_check_encoding($text,'UTF-8')) $result['category_descriptions'][$id]=$text;
        }
    }
    $result['new_coupon']=array();
    if (isset($input['new_coupon']) && is_array($input['new_coupon'])) {
        foreach (array('code'=>100,'name'=>640,'paid_percent'=>3,'starts_at'=>32,'expires_at'=>32,'total_quantity'=>16,'per_user_limit'=>16,'min_amount'=>32,'max_discount'=>32) as $key=>$limit) {
            if (isset($input['new_coupon'][$key]) && is_string($input['new_coupon'][$key]) && strlen($input['new_coupon'][$key])<=$limit && mb_check_encoding($input['new_coupon'][$key],'UTF-8')) $result['new_coupon'][$key]=$input['new_coupon'][$key];
        }
    }
    return $result;
}

function admin_promotion_row_input($pdo, $row)
{
    if (!$row) return array();
    $input=array_intersect_key($row,array_flip(array('name','title','subtitle','description','starts_at','ends_at','coupon_id','version_no')));
    $input['coupon_mode']='existing';
    $s=$pdo->prepare('SELECT product_plan_id FROM promotion_campaign_plans WHERE campaign_id=? ORDER BY product_plan_id');
    $s->execute(array($row['id'])); $input['plan_ids']=$s->fetchAll(PDO::FETCH_COLUMN);
    $s=$pdo->prepare('SELECT category_id,description FROM promotion_campaign_categories WHERE campaign_id=?');
    $s->execute(array($row['id'])); $input['category_descriptions']=array();
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $category) $input['category_descriptions'][(int)$category['category_id']]=$category['description'];
    return $input;
}

function admin_promotion_datetime($value)
{
    if (!is_string($value) || $value==='') return '';
    try { return str_replace(' ','T',PromotionCampaignService::dateValue($value)); }
    catch (InvalidArgumentException $e) { return $value; }
}

function admin_promotion_form($pdo, $service, $row, $input)
{
    $id=$row ? (int)$row['id'] : 0;
    $defaults=array('name'=>'','title'=>'','subtitle'=>'','description'=>'','starts_at'=>'','ends_at'=>'','version_no'=>'1','coupon_id'=>'','coupon_mode'=>'existing','plan_ids'=>array(),'category_descriptions'=>array(),'new_coupon'=>array());
    $input=array_replace($defaults,$input);
    $new=array_replace(array('code'=>'','name'=>'','paid_percent'=>'80','starts_at'=>'','expires_at'=>'','total_quantity'=>'-1','per_user_limit'=>'1','min_amount'=>'0','max_discount'=>'0'),$input['new_coupon']);
    $selected=array();
    foreach ($input['plan_ids'] as $planId) $selected[(int)$planId]=true;
    $body=admin_promotion_assets().'<div class="promotion-editor">'.admin_heading($id ? '编辑优惠活动' : '创建优惠活动','所有时间均为北京时间（Asia/Shanghai，UTC+08:00）。保存只产生草稿，发布与停用需独立操作。','<a class="secondary-button" href="/admin/promotions">返回活动列表</a>');
    $body.='<section class="promotion-current-config promotion-editor-panel"><h2>当前配置<span class="promotion-section-caption">已保存内容</span></h2>';
    if ($row) {
        $coupon=$row['coupon'];
        $body.='<dl class="promotion-config-grid"><div><dt>活动状态</dt><dd><span class="promotion-status promotion-status-'.e($service->state($row)).'">'.e(admin_promotion_label($service->state($row))).'</span><small>#'.$id.' · 版本 v'.(int)$row['version_no'].'</small></dd></div><div><dt>绑定优惠码</dt><dd><code>'.e($coupon ? $coupon['code'] : '优惠券已不存在').'</code><small>'.e($coupon ? $coupon['name'] : '请检查优惠券配置').'</small></dd></div><div><dt>已保存时段 · 北京时间</dt><dd><time>'.e($row['starts_at']).'</time><span class="promotion-date-until">至</span><time>'.e($row['ends_at']).'</time></dd></div></dl>';
        if ($row['status']==='published') $body.='<p class="promotion-warning">此活动已发布。任何保存都会退回草稿并立即停止活动优惠，核对配置后须重新发布。</p>';
    } else $body.='<p>尚未保存。先保存草稿，再核对配置并发布。新优惠码与活动、适用范围及审计记录将一次性提交。</p>';
    $body.='<p>优惠券后续修改会实时影响活动有效性。前台和结算仅允许明确勾选的有效方案，不会自动加入同分类的新方案。</p></section>';
    $body.='<section class="product-form-card promotion-admin-form"><form method="post" action="'.e($id ? '/admin/promotions/'.$id.'/save' : '/admin/promotions/save').'">'.csrf_field();
    if ($id) $body.='<input type="hidden" name="version_no" value="'.e($input['version_no']).'">';
    $body.='<div class="product-form-grid"><section class="product-form-section promotion-editor-panel"><h2><span class="promotion-section-number">01</span>活动内容</h2><p>设置展示文案与活动有效期，修改后请先保存草稿。</p>';
    foreach (array('name'=>array('内部活动名称',160),'title'=>array('前台主标题',160),'subtitle'=>array('前台副标题',255)) as $key=>$field) $body.='<label>'.e($field[0]).'<input name="'.e($key).'" maxlength="'.$field[1].'" value="'.e($input[$key]).'"'.($key==='subtitle' ? '' : ' required').'></label>';
    $body.='<label>活动说明<textarea name="description" maxlength="10000" rows="5">'.e($input['description']).'</textarea></label>';
    $body.='<div class="promotion-editor-dates">';
    foreach (array('starts_at'=>'活动开始（北京时间）','ends_at'=>'活动结束（北京时间，不含该时刻）') as $key=>$label) $body.='<label>'.e($label).'<input type="datetime-local" step="1" name="'.e($key).'" value="'.e(admin_promotion_datetime($input[$key])).'" required></label>';
    $body.='</div></section><section class="product-form-section promotion-editor-panel promotion-coupon-panel"><h2><span class="promotion-section-number">02</span>公共优惠码</h2>';
    if ($id) {
        // 固定绑定值来自已保存活动，而非失败草稿的可篡改字段。
        $input['coupon_mode']='existing'; $input['coupon_id']=(string)$row['coupon_id'];
        $body.='<input type="hidden" name="coupon_mode" value="existing"><input type="hidden" name="coupon_id" value="'.(int)$row['coupon_id'].'"><div class="promotion-bound-coupon"><span>已绑定公共优惠码</span><code>'.e($row['coupon'] ? $row['coupon']['code'] : '优惠券已不存在').'</code><strong>'.e($row['coupon'] ? $row['coupon']['name'] : '请检查优惠券配置').'</strong><small>绑定后不可更换</small></div><p>请在优惠码管理中维护优惠券自身配置。停用活动或保存草稿不会解除绑定及适用范围。</p><div class="promotion-coupon-reminder"><strong>发布前请确认</strong><p>优惠码须已启用、配额可用，且有效期覆盖整个活动时段。优惠券后续修改会实时影响活动有效性。</p></div>';
    } else {
        $body.='<label>优惠码来源<select name="coupon_mode" data-promotion-coupon-mode><option value="existing"'.($input['coupon_mode']==='existing' ? ' selected' : '').'>选择已有公共优惠码</option><option value="new"'.($input['coupon_mode']==='new' ? ' selected' : '').'>新建公共百分比优惠码</option></select></label>';
        $body.='<div data-promotion-existing><label>已有公共码<select name="coupon_id"><option value="">请选择未绑定活动的公共优惠码</option>';
        $coupons=$pdo->query('SELECT c.* FROM coupons c WHERE c.scope="public" AND NOT EXISTS (SELECT 1 FROM promotion_campaigns pc WHERE pc.coupon_id=c.id) ORDER BY c.id DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
        $found=false;
        foreach ($coupons as $coupon) {
            if ((string)$coupon['id']===(string)$input['coupon_id']) $found=true;
            $discount=$coupon['coupon_type']==='percent' ? '减免'.$coupon['discount_percent'].'%' : '减免¥'.admin_coupon_money($coupon['amount_cents']);
            $body.='<option value="'.(int)$coupon['id'].'"'.((string)$coupon['id']===(string)$input['coupon_id'] ? ' selected' : '').'>'.e('#'.$coupon['id'].' '.$coupon['code'].' / '.$coupon['name'].' · '.$discount.' · '.admin_coupon_label($coupon['status'])).'</option>';
        }
        if (!$found && $input['coupon_id']!=='') $body.='<option value="'.e($input['coupon_id']).'" selected>'.e('此前选择 #'.$input['coupon_id'].'（请确认未被其他活动绑定且为公共码）').'</option>';
        $body.='</select></label><p>显示最近1000个未绑定公共码；停用码可存草稿，但不能发布。</p></div><div data-promotion-new><p>新券默认启用、公共、百分比减免；80为实付80%，即八折（减免20%）。</p>';
        foreach (array('code'=>array('优惠码（字母、数字、_、-）',100),'name'=>array('优惠券名称（留空使用活动名称）',160),'paid_percent'=>array('实付百分比（0至99，80为八折）',2)) as $key=>$field) $body.='<label>'.e($field[0]).'<input name="new_coupon['.e($key).']" maxlength="'.$field[1].'" value="'.e($new[$key]).'"></label>';
        $body.='<details><summary>额外设置（新券日期留空默认活动起止）</summary>';
        foreach (array('starts_at'=>'优惠码生效（北京时间）','expires_at'=>'优惠码失效（北京时间，须覆盖整个活动）') as $key=>$label) $body.='<label>'.e($label).'<input type="datetime-local" step="1" name="new_coupon['.e($key).']" value="'.e(admin_promotion_datetime($new[$key])).'"></label>';
        foreach (array('total_quantity'=>'总配额（-1不限，0不能发布）','per_user_limit'=>'每人有效占用次数（1至1000000）','min_amount'=>'最低消费（元）','max_discount'=>'最高减免（元，0不限）') as $key=>$label) $body.='<label>'.e($label).'<input name="new_coupon['.e($key).']" value="'.e($new[$key]).'" maxlength="32" inputmode="'.(in_array($key,array('min_amount','max_discount'),true) ? 'decimal' : 'numeric').'"></label>';
        $body.='</details></div>';
    }
    $body.='</section></div><section class="promotion-plan-picker promotion-editor-panel"><h2><span class="promotion-section-number">03</span>明确勾选活动方案</h2><p>分类筛选只隐藏分组，不会取消已选方案；全选只勾选该分组当前列出的方案，不表示未来全类适用。最多1000项。</p>';
    $groups=array(); $known=array();
    foreach ($service->catalog() as $plan) {
        $category=(int)$plan['category_id']; $known[(int)$plan['id']]=true;
        if (!isset($groups[$category])) $groups[$category]=array('name'=>$plan['category_name'],'description'=>$plan['category_description'],'plans'=>array());
        $groups[$category]['plans'][]=$plan;
    }
    $body.='<label>分类筛选<select data-promotion-category-filter><option value="">全部分类</option>';
    foreach ($groups as $category=>$group) $body.='<option value="'.$category.'">'.e($group['name']).'</option>';
    $body.='</select></label><p data-promotion-selected-count role="status"></p>';
    foreach ($groups as $category=>$group) {
        // 活动介绍最长255字，不把分类表的长描述直接写进活动字段。
        $description=array_key_exists($category,$input['category_descriptions']) ? $input['category_descriptions'][$category] : mb_substr((string)$group['description'],0,255,'UTF-8');
        $body.='<section class="promotion-plan-group" data-promotion-group="'.$category.'"><h3>'.e($group['name']).'</h3><label>本活动分类介绍（最多255字）<textarea name="category_descriptions['.$category.']" maxlength="255" rows="2">'.e($description).'</textarea></label><div class="product-form-actions"><button class="secondary-button" type="button" data-promotion-select-group>全选当前组</button><button class="secondary-button" type="button" data-promotion-clear-group>清空当前组</button></div><div class="promotion-plan-options">';
        foreach ($group['plans'] as $plan) $body.='<label class="promotion-plan-option"><input type="checkbox" name="plan_ids[]" value="'.(int)$plan['id'].'"'.(isset($selected[(int)$plan['id']]) ? ' checked' : '').'><span>'.e($plan['product_name'].' / '.$plan['plan_name']).' <small>'.e('¥'.admin_coupon_money($plan['price_cents']).' · 方案 #'.$plan['id']).'</small></span></label>';
        $body.='</div></section>';
    }
    $missing=array_diff_key($selected,$known);
    if ($missing) {
        $body.='<section class="promotion-plan-group promotion-warning"><h3>已不可用的勾选项</h3><p>以下方案已下架、分类缺失或不存在。请取消勾选后保存；系统不会自动替换成其他方案。</p>';
        foreach ($missing as $planId=>$unused) $body.='<label><input type="checkbox" name="plan_ids[]" value="'.$planId.'" checked>'.e('方案 #'.$planId.'（不可用）').'</label>';
        $body.='</section>';
    }
    if (!$groups) $body.='<p>当前没有可选择的有效VPN方案。可以先保存空范围草稿，但不能发布。</p>';
    $body.='</section><div class="product-form-actions promotion-save-actions"><span class="promotion-save-hint">保存仅生成草稿，不会自动发布</span><button class="button" type="submit">保存草稿'.($row && $row['status']==='published' ? '（停止发布，需重新发布）' : '').'</button><a class="secondary-button" href="/admin/promotions">返回列表</a></div></form></section>';
    if ($row) {
        $body.='<section class="promotion-publish-panel promotion-editor-panel"><h2>独立发布操作</h2><p>发布前将重新校验北京时间、优惠券整段有效期、配额和全部已选方案。请先保存需要生效的修改。</p><div class="product-form-actions">';
        foreach (array('publish'=>'发布已保存配置','disable'=>'停用活动') as $action=>$label) $body.='<form method="post" action="/admin/promotions/'.$id.'/'.$action.'">'.csrf_field().'<button class="secondary-button" type="submit" data-confirm="'.e($action==='publish' ? '仅发布已保存配置，未保存的输入不会生效。确认继续？' : '确认停用此活动？绑定优惠码也将无法用于新订单。').'">'.e($label).'</button></form>';
        $body.='</div></section>';
    }
    return $body.'</div>'. <<<'JS'
<script>
(function(){
    var root=document.querySelector('.promotion-admin-form');if(!root)return;
    var mode=root.querySelector('[data-promotion-coupon-mode]');
    function syncCoupon(){
        if(!mode)return;
        var fresh=mode.value==='new';
        [['[data-promotion-existing]',fresh],['[data-promotion-new]',!fresh]].forEach(function(pair){
            var section=root.querySelector(pair[0]);section.hidden=pair[1];
            section.querySelectorAll('input,select,textarea').forEach(function(field){field.disabled=pair[1];});
        });
    }
    if(mode)mode.addEventListener('change',syncCoupon);syncCoupon();
    var filter=root.querySelector('[data-promotion-category-filter]');
    filter.addEventListener('change',function(){
        root.querySelectorAll('[data-promotion-group]').forEach(function(group){group.hidden=filter.value!==''&&group.dataset.promotionGroup!==filter.value;});
    });
    function count(){root.querySelector('[data-promotion-selected-count]').textContent='已明确勾选 '+root.querySelectorAll('input[name="plan_ids[]"]:checked').length+' 个方案（含被筛选隐藏的选择）。';}
    root.querySelectorAll('[data-promotion-select-group],[data-promotion-clear-group]').forEach(function(button){
        button.addEventListener('click',function(){
            var selected=button.hasAttribute('data-promotion-select-group');
            button.closest('[data-promotion-group]').querySelectorAll('input[name="plan_ids[]"]').forEach(function(field){field.checked=selected;});count();
        });
    });
    root.addEventListener('change',count);count();
})();
</script>
JS;
}

function register_admin_promotion_routes($router, $pdo, $config)
{
    $service=new PromotionCampaignService($pdo);
    $router->get('/admin/promotions',function()use($pdo,$config,$service){
        Security::requireAdmin();
        $total=(int)$pdo->query('SELECT COUNT(*) FROM promotion_campaigns')->fetchColumn();
        $pages=max(1,(int)ceil($total/20)); $p=1;
        if (isset($_GET['page'])) {
            try { $p=min($pages,PromotionCampaignService::parseId($_GET['page'])); }
            catch (InvalidArgumentException $e) { http_response_code(400); page('优惠活动','<p>分页参数无效。</p>',$config); return; }
        }
        $rows=$pdo->query('SELECT pc.*,c.code coupon_code FROM promotion_campaigns pc LEFT JOIN coupons c ON c.id=pc.coupon_id ORDER BY pc.id DESC LIMIT 20 OFFSET '.(($p-1)*20))->fetchAll(PDO::FETCH_ASSOC);
        $body=admin_promotion_assets().'<div class="promotion-list-page">'.admin_heading('优惠活动','统一管理活动内容、优惠码与发布状态。','<a class="button" href="/admin/promotions/new">创建优惠活动</a>');
        $body.='<section class="promotion-list-panel" aria-label="优惠活动列表"><div class="promotion-list-toolbar"><h2>全部活动 <span>'.(int)$total.'</span></h2><span class="promotion-timezone">时间均为北京时间 · UTC+8</span></div><div class="table-wrap promotion-admin-list"><table><thead><tr><th>活动名称</th><th>公共优惠码</th><th>活动状态</th><th>活动时间</th><th>操作</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id=(int)$row['id']; $state=$service->state($row);
            $body.='<tr><td><div class="promotion-list-identity"><strong>'.e($row['name']).'</strong><span>'.e($row['title']).'</span><small>活动 #'.$id.'</small></div></td><td><code class="promotion-list-code">'.e($row['coupon_code'] ?: '优惠券不存在').'</code></td><td><div class="promotion-list-state"><span class="promotion-status promotion-status-'.e($state).'">'.e(admin_promotion_label($state)).'</span><small>版本 v'.(int)$row['version_no'].'</small></div></td><td><div class="promotion-list-dates"><span><small>开始</small><time>'.e($row['starts_at']).'</time></span><span><small>结束</small><time>'.e($row['ends_at']).'</time></span></div></td><td><div class="promotion-list-actions"><a class="secondary-button promotion-action-edit" href="/admin/promotions/'.$id.'/edit">编辑</a>';
            foreach (array('publish'=>'发布','disable'=>'停用') as $action=>$label) $body.='<form method="post" action="/admin/promotions/'.$id.'/'.$action.'">'.csrf_field().'<button class="secondary-button promotion-action-'.$action.'" type="submit" data-confirm="'.e($action==='publish' ? '确认发布此活动已保存的配置？' : '确认停用此活动？').'">'.e($label).'</button></form>';
            $body.='</div></td></tr>';
        }
        if (!$rows) $body.='<tr><td colspan="5">暂无优惠活动，请先创建草稿。</td></tr>';
        $body.='</tbody></table></div><footer class="promotion-list-footer"><span>共 '.$total.' 条活动 · 第 '.$p.' / '.$pages.' 页</span><nav aria-label="活动分页">';
        foreach (array($p-1=>'上一页',$p+1=>'下一页') as $number=>$label) if ($number>=1 && $number<=$pages) $body.='<a class="secondary-button" href="/admin/promotions?page='.$number.'">'.e($label).'</a> ';
        $body.='</nav></footer></section><aside class="promotion-list-note"><strong>展示规则</strong><p>只有已发布、在有效期内且优惠码与适用方案有效的活动才会展示。优惠码被编辑、停用或耗尽后，前台及结算会实时阻止无效优惠。</p></aside></div>';
        page('优惠活动',$body,$config);
    });

    $form=function($id=0)use($pdo,$config,$service){
        Security::requireAdmin();
        try { $id=PromotionCampaignService::parseId($id,true); }
        catch (InvalidArgumentException $e) { http_response_code(404); return; }
        $row=$id ? $service->get($id) : null;
        if ($id && !$row) { http_response_code(404); return; }
        $input=admin_promotion_row_input($pdo,$row);
        $draft=isset($_SESSION['promotion_form_draft']) ? $_SESSION['promotion_form_draft'] : null;
        if (is_array($draft) && isset($draft['form_id'],$draft['input']) && (int)$draft['form_id']===$id && is_array($draft['input'])) {
            $input=array_replace($input,admin_promotion_safe_draft($draft['input']));
            unset($_SESSION['promotion_form_draft']);
        }
        page($id ? '编辑优惠活动' : '创建优惠活动',admin_promotion_form($pdo,$service,$row,$input),$config);
    };
    $router->get('/admin/promotions/new',$form);
    $router->get('/admin/promotions/{id}/edit',$form);

    $save=function($id=0)use($service){
        $admin=Security::requireAdmin(); post_csrf();
        try { $id=PromotionCampaignService::parseId($id,true); }
        catch (InvalidArgumentException $e) { flash('error','活动编号无效。'); redirect('/admin/promotions'); return; }
        try {
            $saved=$service->save($_POST,$admin,$id);
            unset($_SESSION['promotion_form_draft']);
            flash('success','活动已保存为草稿；如原来已发布，现已停止发布。请核对已保存配置后重新发布。');
            redirect('/admin/promotions/'.$saved.'/edit');
        } catch (Throwable $e) {
            $_SESSION['promotion_form_draft']=array('form_id'=>$id,'input'=>admin_promotion_safe_draft($_POST));
            flash('error',$e instanceof InvalidArgumentException ? $e->getMessage() : '活动保存失败，请检查数据后重试。草稿已保留，新建优惠码不会单独写入。');
            redirect($id ? '/admin/promotions/'.$id.'/edit' : '/admin/promotions/new');
        }
    };
    $router->post('/admin/promotions/save',$save);
    $router->post('/admin/promotions/{id}/save',$save);

    $router->get('/admin/promotions/{id}/preview',function($id)use($config,$service){
        Security::requireAdmin();
        header('Cache-Control: no-store, private'); header('X-Robots-Tag: noindex, nofollow');
        try {
            $id=PromotionCampaignService::parseId($id);
            $device=isset($_GET['device']) ? $_GET['device'] : 'desktop';
            if (!is_string($device) || !in_array($device,array('mobile','desktop'),true)) throw new InvalidArgumentException('请选择手机或桌面预览。');
            $row=$service->get($id);
            if (!$row) { http_response_code(404); return; }
            // 所有已保存状态均可只读预览；不保存表单，也不调用发布或停用操作。
            if (!function_exists('promotion_campaign_preview_document')) throw new RuntimeException('预览模板尚未接入。');
            header('Content-Type: text/html; charset=utf-8');
            echo promotion_campaign_preview_document($row,$config,$device);
        } catch (InvalidArgumentException $e) {
            http_response_code(400); page('活动预览','<p>'.e($e->getMessage()).'</p><a href="/admin/promotions">返回活动列表</a>',$config);
        } catch (Throwable $e) {
            http_response_code(503); page('活动预览','<p>预览暂时不可用，请确认完整预览模板已接入。</p>',$config);
        }
    });

    foreach (array('publish','disable') as $action) {
        $router->post('/admin/promotions/{id}/'.$action,function($id)use($pdo,$service,$action){
            $admin=Security::requireAdmin(); post_csrf();
            try { $id=PromotionCampaignService::parseId($id); }
            catch (InvalidArgumentException $e) { flash('error','活动编号无效。'); redirect('/admin/promotions'); return; }
            try {
                $service->$action($id,$admin);
                flash('success',$action==='publish' ? '活动已发布，未到开始时间的活动将按北京时间生效。' : '活动已停用，绑定优惠码不再接受新订单；已有订单金额、核销与退款不受影响。');
            } catch (Throwable $e) {
                // 独立状态操作没有活动输入，保留已保存配置；不覆盖之前失败的表单草稿。
                if (!isset($_SESSION['promotion_form_draft'])) {
                    try {
                        $row=$service->get($id);
                        if ($row) $_SESSION['promotion_form_draft']=array('form_id'=>$id,'input'=>admin_promotion_safe_draft(admin_promotion_row_input($pdo,$row)));
                    } catch (Throwable $ignored) { /* 数据库故障时仍正常返回中文提示。 */ }
                }
                flash('error',$e instanceof InvalidArgumentException ? $e->getMessage() : '活动状态更新失败，请稍后重试。');
            }
            redirect('/admin/promotions/'.$id.'/edit');
        });
    }
}
