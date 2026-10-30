<?php
function register_vpn_category_routes($router,$pdo,$config)
{
    $form=function($row,$action){
        $row=$row?:array('name'=>'','sort_order'=>0);
        return '<section class="product-form-card admin-vpn-category-page"><div class="product-form-heading"><div><span class="admin-kicker">CATALOG / 订阅分类</span><h1>'.($row['name']!==''?'编辑分类':'新建分类').'</h1><p>分类仅用于整理VPN订阅，不改变期限方案、售价或成本。</p></div><a class="secondary-button" href="/admin/vpn-categories">返回分类列表</a></div><form class="vpn-category-form" method="post" action="'.e($action).'">'.csrf_field().'<div class="product-form-section"><label>分类名称<input name="name" value="'.e($row['name']).'" maxlength="100" placeholder="例如：优秀直连、AI住宅IP" required></label><label>排序值<input name="sort_order" type="number" min="0" max="999999999" value="'.(int)$row['sort_order'].'" required><small class="field-help">数值越小，展示越靠前；相同排序按创建顺序排列。</small></label><label>分组介绍<textarea name="description" rows="6" maxlength="2000" placeholder="介绍线路特点、适用场景与注意事项">'.e(isset($row['description'])?$row['description']:'').'</textarea><small class="field-help">选填，最多2000字，支持换行。将展示给用户，可介绍线路特点、适用场景与注意事项。</small></label></div><div class="product-form-actions"><a class="secondary-button" href="/admin/vpn-categories">取消</a><button class="button" type="submit">保存分类</button></div></form></section>';
    };
    $router->get('/admin/vpn-categories',function()use($pdo,$config){
        Security::requireAdmin();
        $rows=$pdo->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.vpn_category_id=c.id AND p.product_type="vpn") AS product_count FROM vpn_categories c ORDER BY c.sort_order,c.id')->fetchAll();
        $body='<div class="admin-vpn-category-page">'.admin_heading('订阅分类','维护VPN订阅分类；使用中的分类不能删除，旧套餐可保持未分类。','<a class="secondary-button" href="/admin/products">订阅套餐</a> <a class="button" href="/admin/vpn-categories/new">＋ 新建分类</a>');
        $body.='<div class="admin-product-grid">';
        foreach($rows as $row){$id=(int)$row['id'];$body.='<article class="admin-product-card vpn-category-card"><h3>'.e($row['name']).'</h3><p>排序 '.(int)$row['sort_order'].' · '.(int)$row['product_count'].' 个订阅套餐（含草稿、下架）</p><div class="admin-product-actions"><a href="/admin/vpn-categories/'.$id.'/edit">编辑</a><a href="/admin/products?category='.$id.'">查看套餐</a><form method="post" action="/admin/vpn-categories/'.$id.'/delete">'.csrf_field().'<button type="submit" data-confirm="确认删除此分类？使用中的分类无法删除。">删除</button></form></div></article>';}
        if(!$rows)$body.='<div class="admin-info-banner">暂无订阅分类。创建分类后，可在套餐编辑页选择归属；未分类套餐不受影响。</div>';
        page('订阅分类',$body.'</div></div>',$config);
    });
    $router->get('/admin/vpn-categories/new',function()use($config,$form){Security::requireAdmin();page('新建订阅分类',$form(null,'/admin/vpn-categories/save'),$config);});
    $router->get('/admin/vpn-categories/{id}/edit',function($id)use($pdo,$config,$form){
        Security::requireAdmin();
        try{$row=(new VpnCategoryService($pdo))->requireId($id);}catch(InvalidArgumentException $e){http_response_code(404);return page('分类不存在','<div class="empty">订阅分类不存在。<a href="/admin/vpn-categories">返回分类管理</a></div>',$config);}
        page('编辑订阅分类',$form($row,'/admin/vpn-categories/'.$row['id'].'/save'),$config);
    });
    $save=function($id=0)use($pdo){
        $admin=Security::requireAdmin();post_csrf();
        try{(new VpnCategoryService($pdo))->save($id,$_POST,$admin);flash('success','订阅分类已保存。');}catch(Exception $e){flash('error',$e->getMessage());}
        redirect('/admin/vpn-categories');
    };
    $router->post('/admin/vpn-categories/save',$save);
    $router->post('/admin/vpn-categories/{id}/save',$save);
    $router->post('/admin/vpn-categories/{id}/delete',function($id)use($pdo){
        $admin=Security::requireAdmin();post_csrf();
        try{(new VpnCategoryService($pdo))->delete($id,$admin);flash('success','订阅分类已删除。');}catch(Exception $e){flash('error',$e->getMessage());}
        redirect('/admin/vpn-categories');
    });
}
