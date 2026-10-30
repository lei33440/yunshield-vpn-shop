<?php

function admin_coupon_label($value)
{
    $labels=array('scope'=>'使用范围','coupon_type'=>'优惠类型','status'=>'状态','public'=>'公共码','personal'=>'个人券','active'=>'启用','inactive'=>'停用','disabled'=>'停用','used'=>'已使用','applied'=>'已占用','reversed'=>'已释放');
    return isset($labels[$value])?$labels[$value]:$value;
}
function admin_coupon_integer($value, $min, $max)
{
    if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]*|-1)\z/D', $value) || strlen($value)>10 || (int)$value<$min || (int)$value>$max) throw new InvalidArgumentException('整数参数不合法。');
    return (int)$value;
}
function admin_coupon_amount($value)
{
    if (!is_string($value) || !preg_match('/\A(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?\z/D', $value, $m)) throw new InvalidArgumentException('金额须为最多两位小数的非负金额，最高9999999.99元。');
    return (int)$m[1]*100+(isset($m[2])?(int)str_pad($m[2],2,'0'):0);
}
function admin_coupon_date($value)
{
    if ($value==='') return null;
    if (!is_string($value)) throw new InvalidArgumentException('日期格式不正确。');
    $value=str_replace('T',' ',$value);
    $format=strlen($value)===16?'Y-m-d H:i':'Y-m-d H:i:s';
    $d=DateTime::createFromFormat('!'.$format,$value);
    if (!$d || $d->format($format)!==$value || $d->format('Y')<'1970' || $d->format('Y')>'2099') throw new InvalidArgumentException('日期格式不正确。');
    return $d->format('Y-m-d H:i:s');
}
function admin_coupon_money($cents){return intdiv((int)$cents,100).'.'.str_pad((string)((int)$cents%100),2,'0',STR_PAD_LEFT);}
function admin_coupon_input($pdo, $input)
{
    $get=function($key,$default='')use($input){$v=isset($input[$key])?$input[$key]:$default;if(!is_string($v))throw new InvalidArgumentException('表单参数类型不正确。');return trim($v);};
    $r=array('code'=>strtoupper($get('code')),'name'=>$get('name'),'scope'=>$get('scope'),'coupon_type'=>$get('coupon_type'),'status'=>$get('status'));
    if(!preg_match('/\A[A-Z0-9_-]{1,100}\z/D',$r['code']) || $r['name']==='' || strlen($r['name'])>160 || !in_array($r['scope'],array('personal','public'),true) || !in_array($r['coupon_type'],array('fixed','percent'),true) || !in_array($r['status'],array('active','inactive'),true))throw new InvalidArgumentException('请检查编码、名称、范围、类型和状态。');
    $r['user_id']=$r['scope']==='personal'?admin_coupon_integer($get('user_id'),1,2147483647):null;
    if($r['user_id']){$s=$pdo->prepare('SELECT id FROM users WHERE id=? AND status="active"');$s->execute(array($r['user_id']));if(!$s->fetch())throw new InvalidArgumentException('绑定用户不存在或已停用。');}
    $r['product_plan_id']=admin_coupon_integer($get('product_plan_id','0'),0,2147483647);
    if($r['product_plan_id']){$s=$pdo->prepare('SELECT pp.id FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.id=? AND pp.status="active" AND p.status="active" AND p.product_type="vpn"');$s->execute(array($r['product_plan_id']));if(!$s->fetch())throw new InvalidArgumentException('指定VPN方案不存在或已下架，海外账号不参与优惠券。');}else $r['product_plan_id']=null;
    foreach(array('amount','max_discount','min_amount') as $key)$r[$key.'_cents']=admin_coupon_amount($get($key,'0'));
    $r['discount_percent']=admin_coupon_integer($get('discount_percent','0'),0,100);
    if(($r['coupon_type']==='fixed' && $r['amount_cents']===0)||($r['coupon_type']==='percent' && $r['discount_percent']===0))throw new InvalidArgumentException('优惠额度必须大于零。');
    $r['total_quantity']=admin_coupon_integer($get('total_quantity','-1'),-1,2147483647);$r['per_user_limit']=admin_coupon_integer($get('per_user_limit','1'),1,1000000);
    if($r['scope']==='personal'){$r['total_quantity']=1;$r['per_user_limit']=1;}
    $r['starts_at']=admin_coupon_date($get('starts_at'));$r['expires_at']=admin_coupon_date($get('expires_at'));
    if($r['starts_at']&&$r['expires_at']&&$r['expires_at']<$r['starts_at'])throw new InvalidArgumentException('结束时间不得早于开始时间。');
    return $r;
}
function admin_coupon_users($pdo,$query)
{
    if(!is_string($query)||mb_strlen($query,'UTF-8')>100)throw new InvalidArgumentException('搜索内容须为100字以内的文字。');
    $query=trim($query);$params=array();$where='status="active"';
    if($query!==''){
        $like='%'.strtr($query,array('!'=>'!!','%'=>'!%','_'=>'!_')).'%';
        $where.=" AND (email LIKE ? ESCAPE '!' OR display_name LIKE ? ESCAPE '!'";$params=array($like,$like);
        if(ctype_digit($query)&&strlen($query)<=10&&(int)$query<=2147483647){$where.=' OR id=?';$params[]=(int)$query;}
        $where.=')';
    }
    $s=$pdo->prepare('SELECT id,email,display_name FROM users WHERE '.$where.' ORDER BY id DESC LIMIT 20');$s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}
function admin_coupon_form($pdo,$r,$action)
{
    $r=$r?:array('code'=>'','name'=>'','scope'=>'public','user_id'=>'','coupon_type'=>'fixed','amount_cents'=>0,'discount_percent'=>0,'max_discount_cents'=>0,'min_amount_cents'=>0,'product_plan_id'=>0,'total_quantity'=>-1,'per_user_limit'=>1,'status'=>'inactive','starts_at'=>'','expires_at'=>'');
    $users=admin_coupon_users($pdo,'');
    if(!empty($r['user_id'])){
        $s=$pdo->prepare('SELECT id,email,display_name,status FROM users WHERE id=?');$s->execute(array($r['user_id']));$bound=$s->fetch(PDO::FETCH_ASSOC);
        $users=array_filter($users,function($u)use($r){return (string)$u['id']!==(string)$r['user_id'];});
        array_unshift($users,$bound?:array('id'=>$r['user_id'],'email'=>'用户不存在','display_name'=>'','status'=>'missing'));
    }
    $body='<section class="product-form-card admin-coupon-form"><form method="post" action="'.e($action).'">'.csrf_field().'<div class="product-form-grid"><div class="product-form-section">';
    $body.='<label>编码（字母、数字、_、-）<span class="admin-coupon-input-actions"><input name="code" value="'.e($r['code']).'" maxlength="100" required><button type="button" class="secondary-button" data-coupon-generate>自动生成</button></span></label><label>名称<input name="name" value="'.e($r['name']).'" maxlength="160" required></label>';
    $body.='<label>使用范围<select name="scope"><option value="public"'.($r['scope']==='public'?' selected':'').'>公共码（所有符合条件的用户可用）</option><option value="personal"'.($r['scope']==='personal'?' selected':'').'>个人券（绑定指定用户）</option></select></label>';
    $body.='<div class="admin-coupon-user-picker"'.($r['scope']==='personal'?'':' hidden').'><label>搜索绑定用户<input type="search" data-coupon-user-search maxlength="100" placeholder="搜索邮箱、昵称或用户ID" autocomplete="off"></label><label>个人券绑定用户<select name="user_id"'.($r['scope']==='personal'?' required':' disabled').'><option value="">请选择用户</option>';
    foreach($users as $u){$label=($u['display_name']!==''?$u['display_name'].' · ':'').$u['email'].' · #'.$u['id'];if(isset($u['status'])&&$u['status']!=='active')$label.='（已停用或不存在）';$body.='<option value="'.(int)$u['id'].'"'.((string)$r['user_id']===(string)$u['id']?' selected':'').'>'.e($label).'</option>';}
    $body.='</select></label><div class="admin-coupon-user-help"><span data-coupon-user-message role="status">可直接选择最近用户，或搜索后选择；公共码无需绑定。</span><button type="button" class="secondary-button" data-coupon-user-clear>清除选择</button></div></div>';
    foreach(array('coupon_type'=>array('fixed'=>'固定减免','percent'=>'百分比减免'),'status'=>array('inactive'=>'停用','active'=>'启用')) as $key=>$options){$body.='<label>'.e(admin_coupon_label($key)).'<select name="'.e($key).'">';foreach($options as $v=>$label)$body.='<option value="'.e($v).'"'.($r[$key]===$v?' selected':'').'>'.e($label).'</option>';$body.='</select></label>';}
    $body.='</div><div class="product-form-section">';
    foreach(array('amount'=>'固定减免（元）','max_discount'=>'最高减免（元，0不限）','min_amount'=>'最低消费（元）') as $key=>$label)$body.='<label>'.e($label).'<input name="'.e($key).'" value="'.e(admin_coupon_money($r[$key.'_cents'])).'" inputmode="decimal" required></label>';
    foreach(array('discount_percent'=>'减免百分比（100为全免，非折后比例）','total_quantity'=>'总配额（-1不限，0不允许使用）','per_user_limit'=>'每人有效占用次数') as $key=>$label)$body.='<label>'.e($label).'<input name="'.e($key).'" value="'.e($r[$key]).'" required></label>';
    $body.='<label>指定方案<select name="product_plan_id"><option value="0">所有VPN方案（不含海外账号）</option>';
    $plans=$pdo->query('SELECT pp.id,pp.name,p.name product_name FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE p.product_type="vpn" ORDER BY pp.id')->fetchAll();foreach($plans as $p)$body.='<option value="'.(int)$p['id'].'"'.((int)$r['product_plan_id']===(int)$p['id']?' selected':'').'>'.e($p['product_name'].' / '.$p['name']).'</option>';
    $body.='</select></label>';foreach(array('starts_at'=>'生效时间','expires_at'=>'失效时间') as $key=>$label)$body.='<label>'.e($label).'<input type="datetime-local" name="'.e($key).'" value="'.e($r[$key]?substr(str_replace(' ','T',$r[$key]),0,16):'').'"></label>';
    $body.='</div></div><p>下单即占用额度；取消或批准退款释放。个人券总量及每人次数固定为1。已有使用历史的编码、范围、绑定用户不可变更。</p><div class="product-form-actions"><button class="button">保存</button><a class="secondary-button" href="/admin/coupons">返回</a></div></form></section>';
    return $body. <<<'JS'
<script>
(function(){
    var root=document.querySelector('.admin-coupon-form');if(!root)return;
    var code=root.querySelector('[name=code]'),generate=root.querySelector('[data-coupon-generate]');
    var scope=root.querySelector('[name=scope]'),search=root.querySelector('[data-coupon-user-search]'),users=root.querySelector('[name=user_id]'),clear=root.querySelector('[data-coupon-user-clear]'),message=root.querySelector('[data-coupon-user-message]');
    generate.addEventListener('click',function(){
        if(code.value&&!confirm('重新生成将替换当前编码，是否继续？'))return;
        if(!window.crypto||!window.crypto.getRandomValues){message.textContent='当前浏览器不支持安全随机生成，请手动输入编码。';return;}
        var bytes=new Uint8Array(12);window.crypto.getRandomValues(bytes);
        code.value='CP_'+Array.from(bytes,function(b){return b.toString(16).padStart(2,'0');}).join('').toUpperCase();
    });
    var timer,sequence=0,controller;
    function cancel(){clearTimeout(timer);sequence++;if(controller)controller.abort();}
    function syncScope(){cancel();var personal=scope.value==='personal';root.querySelector('.admin-coupon-user-picker').hidden=!personal;users.required=personal;users.disabled=!personal;search.disabled=!personal;clear.disabled=!personal;message.textContent=personal?'可直接选择最近用户，或搜索邮箱、昵称、ID。':'公共码无需绑定用户。';}
    scope.addEventListener('change',syncScope);syncScope();
    search.addEventListener('input',function(){
        cancel();users.replaceChildren(new Option('请选择用户',''));var current=sequence;message.textContent='正在搜索…';
        timer=setTimeout(async function(){
            controller=new AbortController();
            try{
                var response=await fetch('/admin/coupons/users/search?q='+encodeURIComponent(search.value.trim()),{signal:controller.signal,headers:{Accept:'application/json'},cache:'no-store'});
                if(!response.ok)throw new Error('search');var data=await response.json();if(current!==sequence)return;
                users.replaceChildren(new Option('请选择用户',''));
                data.users.forEach(function(u){users.add(new Option((u.display_name?u.display_name+' · ':'')+u.email+' · #'+u.id,String(u.id)));});
                message.textContent=data.users.length?'找到 '+data.users.length+' 位用户，请从下方列表选择。':'没有找到匹配的活跃用户。';
            }catch(error){if(current!==sequence||error.name==='AbortError')return;message.textContent='搜索失败，请重新输入重试。';}
        },300);
    });
    clear.addEventListener('click',function(){cancel();users.value='';search.value='';message.textContent='已清除绑定用户，请重新选择。';});
})();
</script>
JS;

}
function register_admin_coupon_routes($router,$pdo,$config)
{
    $router->get('/admin/coupons/users/search',function()use($pdo){
        Security::requireAdmin();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        try{echo json_encode(array('users'=>admin_coupon_users($pdo,isset($_GET['q'])?$_GET['q']:'')),JSON_UNESCAPED_UNICODE);}
        catch(InvalidArgumentException $e){http_response_code(400);echo json_encode(array('error'=>$e->getMessage()),JSON_UNESCAPED_UNICODE);}
    });
    $router->get('/admin/coupons',function()use($pdo,$config){
        Security::requireAdmin();$q=isset($_GET['q'])&&is_string($_GET['q'])?substr(trim($_GET['q']),0,100):'';$status=isset($_GET['status'])&&is_string($_GET['status'])?$_GET['status']:'';$scope=isset($_GET['scope'])&&is_string($_GET['scope'])?$_GET['scope']:'';
        $where=array('1=1');$params=array();if($q!==''){$where[]='(code LIKE ? OR name LIKE ?)';$params[]='%'.$q.'%';$params[]='%'.$q.'%';}if(in_array($status,array('active','inactive','used'),true)){$where[]='status=?';$params[]=$status;}if(in_array($scope,array('personal','public'),true)){$where[]='scope=?';$params[]=$scope;}$where=implode(' AND ',$where);
        $s=$pdo->prepare('SELECT COUNT(*) FROM coupons WHERE '.$where);$s->execute($params);$total=(int)$s->fetchColumn();$pages=max(1,(int)ceil($total/20));$p=isset($_GET['page'])&&is_string($_GET['page'])&&ctype_digit($_GET['page'])?min($pages,max(1,(int)$_GET['page'])):1;
        $s=$pdo->prepare('SELECT * FROM coupons WHERE '.$where.' ORDER BY id DESC LIMIT 20 OFFSET '.(($p-1)*20));$s->execute($params);
        $body=admin_heading('优惠码管理','公共码与个人券；不提供硬删除。','<a class="button" href="/admin/coupons/new">创建优惠码</a>');
        $body.='<form method="get" class="product-form-actions"><input name="q" placeholder="编码或名称" value="'.e($q).'"><select name="scope"><option value="">所有范围</option>';foreach(array('public','personal')as$v)$body.='<option value="'.e($v).'"'.($scope===$v?' selected':'').'>'.e(admin_coupon_label($v)).'</option>';$body.='</select><select name="status"><option value="">所有状态</option>';foreach(array('active','inactive','used')as$v)$body.='<option value="'.e($v).'"'.($status===$v?' selected':'').'>'.e(admin_coupon_label($v)).'</option>';$body.='</select><button class="secondary-button">筛选</button></form><div class="table-wrap"><table><thead><tr><th>编码 / 名称</th><th>范围 / 用户</th><th>优惠</th><th>占用 / 总量</th><th>状态 / 有效期</th><th>操作</th></tr></thead><tbody>';
        foreach($s->fetchAll()as$r){$id=(int)$r['id'];$body.='<tr><td>'.e($r['code']).'<br>'.e($r['name']).'</td><td>'.e(admin_coupon_label($r['scope'])).' / '.e($r['user_id']?:'—').'</td><td>'.e($r['coupon_type']==='percent'?$r['discount_percent'].'%减免':'¥'.admin_coupon_money($r['amount_cents'])).'</td><td>'.(int)$r['used_quantity'].' / '.(int)$r['total_quantity'].'</td><td>'.e(admin_coupon_label($r['status'])).'<br>'.e($r['starts_at']?:'不限').' → '.e($r['expires_at']?:'不限').'</td><td><a href="/admin/coupons/'.$id.'/edit">编辑</a> · <a href="/admin/coupons/'.$id.'/usage">使用记录</a><form method="post" action="/admin/coupons/'.$id.'/toggle">'.csrf_field().'<input type="hidden" name="status" value="'.($r['status']==='active'?'inactive':'active').'"><button class="secondary-button">'.($r['status']==='active'?'停用':'启用').'</button></form></td></tr>';}
        if(!$total)$body.='<tr><td colspan="6" class="admin-module-empty">暂无符合条件的优惠码。</td></tr>';
        $body.='</tbody></table></div><p>共'.$total.'条，第'.$p.'/'.$pages.'页</p>';foreach(array($p-1=>'上一页',$p+1=>'下一页')as$n=>$label)if($n>=1&&$n<=$pages)$body.='<a class="secondary-button" href="'.e('/admin/coupons?'.http_build_query(array('q'=>$q,'scope'=>$scope,'status'=>$status,'page'=>$n))).'">'.e($label).'</a>';page('优惠码管理',$body,$config);
    });
    $router->get('/admin/coupons/new',function()use($pdo,$config){Security::requireAdmin();page('创建优惠码',admin_coupon_form($pdo,null,'/admin/coupons/save'),$config);});
    $router->get('/admin/coupons/{id}/edit',function($id)use($pdo,$config){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM coupons WHERE id=?');$s->execute(array(ctype_digit((string)$id)?$id:0));$r=$s->fetch();if(!$r){http_response_code(404);return;}page('编辑优惠码',admin_coupon_form($pdo,$r,'/admin/coupons/'.$r['id'].'/save'),$config);});
    $save=function($id=0)use($pdo){$admin=Security::requireAdmin();post_csrf();try{
        if($id!==0)$id=admin_coupon_integer((string)$id,1,2147483647);
        $r=admin_coupon_input($pdo,$_POST);$pdo->beginTransaction();$old=null;if($id){$s=$pdo->prepare('SELECT * FROM coupons WHERE id=? FOR UPDATE');$s->execute(array($id));$old=$s->fetch();if(!$old)throw new RuntimeException('优惠码不存在。');$s=$pdo->prepare('SELECT id FROM order_promotions WHERE coupon_id=? LIMIT 1');$s->execute(array($id));$history=$s->fetch();if(($history||(int)$old['used_quantity']>0||$old['used_order_id'])&&($r['code']!==$old['code']||$r['scope']!==$old['scope']||(string)$r['user_id']!==(string)$old['user_id']))throw new InvalidArgumentException('已有使用历史，不可变更编码、范围和绑定用户。');if($r['total_quantity']>=0&&$r['total_quantity']<(int)$old['used_quantity'])throw new InvalidArgumentException('总配额不得低于当前占用。');if($r['scope']==='personal'&&($old['used_order_id']||(int)$old['used_quantity']>0)&&$r['status']==='active')$r['status']=$old['status']==='used'?'used':'inactive';}
        $now=date('Y-m-d H:i:s');$columns=array_keys($r);$values=array_values($r);if($id){$set=array();foreach($columns as$c)$set[]=$c.'=?';$values[]=$admin;$values[]=$now;$values[]=$id;$pdo->prepare('UPDATE coupons SET '.implode(',',$set).',updated_by=?,updated_at=? WHERE id=?')->execute($values);}else{$values[]=$admin;$values[]=$admin;$values[]=$now;$values[]=$now;$pdo->prepare('INSERT INTO coupons('.implode(',',$columns).',created_by,updated_by,created_at,updated_at) VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute($values);$id=$pdo->lastInsertId();}
        $pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($admin,$old?'update_coupon':'create_coupon','coupon',$id,json_encode(array('before'=>$old,'after'=>$r),JSON_UNESCAPED_UNICODE),$now));$pdo->commit();flash('success','优惠码已保存。');
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e instanceof PDOException?'保存失败，编码可能已存在。':$e->getMessage());}redirect('/admin/coupons');};
    $router->post('/admin/coupons/save',$save);$router->post('/admin/coupons/{id}/save',$save);
    $router->post('/admin/coupons/{id}/toggle',function($id)use($pdo){$admin=Security::requireAdmin();post_csrf();try{$status=isset($_POST['status'])?$_POST['status']:'';if(!in_array($status,array('active','inactive'),true))throw new InvalidArgumentException('状态无效。');$pdo->beginTransaction();$s=$pdo->prepare('SELECT * FROM coupons WHERE id=? FOR UPDATE');$s->execute(array($id));$r=$s->fetch();if(!$r)throw new RuntimeException('优惠码不存在。');if($status==='active'&&!empty($r['product_plan_id'])){$plan=$pdo->prepare('SELECT pp.id FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.id=? AND pp.status="active" AND p.status="active" AND p.product_type="vpn"');$plan->execute(array($r['product_plan_id']));if(!$plan->fetch())throw new RuntimeException('指定VPN方案不存在或已下架，海外账号不参与优惠券。');}if($status==='active'&&$r['scope']==='personal'&&($r['used_order_id']||(int)$r['used_quantity']>0))throw new RuntimeException('已占用个人券不可重新启用。');if($status==='active'&&$r['scope']==='personal'&&!$r['user_id'])throw new RuntimeException('无绑定用户的历史券不可启用。');$now=date('Y-m-d H:i:s');$pdo->prepare('UPDATE coupons SET status=?,updated_by=?,updated_at=? WHERE id=?')->execute(array($status,$admin,$now,$id));$pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($admin,'toggle_coupon','coupon',$id,json_encode(array('before'=>$r['status'],'after'=>$status)),$now));$pdo->commit();flash('success','状态已更新。');}catch(Exception$e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e instanceof PDOException?'状态更新失败。':$e->getMessage());}redirect('/admin/coupons');});
    $router->get('/admin/coupons/{id}/usage',function($id)use($pdo,$config){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM coupons WHERE id=?');$s->execute(array($id));$c=$s->fetch();if(!$c){http_response_code(404);return;}$s=$pdo->prepare('SELECT COUNT(*) FROM order_promotions WHERE coupon_id=?');$s->execute(array($id));$total=(int)$s->fetchColumn();$pages=max(1,(int)ceil($total/20));$p=isset($_GET['page'])&&is_string($_GET['page'])&&ctype_digit($_GET['page'])?min($pages,max(1,(int)$_GET['page'])):1;$s=$pdo->prepare('SELECT op.*,o.order_no,o.user_id,u.email FROM order_promotions op JOIN orders o ON o.id=op.order_id LEFT JOIN users u ON u.id=o.user_id WHERE op.coupon_id=? ORDER BY op.id DESC LIMIT 20 OFFSET '.(($p-1)*20));$s->execute(array($id));$body=admin_heading('优惠使用记录',$c['code'],'<a class="secondary-button" href="/admin/coupons">返回</a>').'<div class="table-wrap"><table><thead><tr><th>订单</th><th>用户</th><th>优惠快照</th><th>状态</th><th>占用 / 更新时间</th></tr></thead><tbody>';foreach($s->fetchAll()as$r)$body.='<tr><td>'.e($r['order_no']).'</td><td>#'.(int)$r['user_id'].' '.e($r['email']).'</td><td>'.e($r['promotion_code']).' ¥'.e(admin_coupon_money($r['discount_amount_cents'])).'</td><td>'.e(admin_coupon_label($r['status'])).'</td><td>'.e($r['created_at']).'<br>'.e($r['updated_at']).'</td></tr>';if(!$total)$body.='<tr><td colspan="5" class="admin-module-empty">暂无使用记录。</td></tr>';$body.='</tbody></table></div><p>共'.$total.'条，第'.$p.'/'.$pages.'页</p>';for($n=max(1,$p-1);$n<=min($pages,$p+1);$n++)$body.='<a class="secondary-button" href="/admin/coupons/'.(int)$id.'/usage?page='.$n.'">'.$n.'</a>';page('优惠使用记录',$body,$config);});
}
