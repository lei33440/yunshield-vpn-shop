<?php
function help_icon($name) {
    $paths=array('book'=>'<path d="M12 5C8 2 4 3 2 4v16c3-2 7-2 10 0 3-2 7-2 10 0V4c-3-1-6-2-10 1Zm0 0v15"/>','receipt'=>'<path d="M5 3h14v19l-3-2-4 2-4-2-3 2V3Z"/><path d="M8 7h8M8 11h8M8 15h5"/>','shield'=>'<path d="m12 2 9 4v6c0 5-5 9-9 11-4-2-9-6-9-11V6l9-4Z"/>','globe'=>'<circle cx="12" cy="12" r="10"/><ellipse cx="12" cy="12" rx="4" ry="10"/><path d="M2 12h20M4 6h16M4 18h16"/>','monitor'=>'<rect x="2" y="3" width="20" height="15" rx="1"/><path d="M8 22h8M10 18l-1 4m5-4 1 4"/>','user'=>'<circle cx="12" cy="7" r="5"/><path d="M2 22v-2c0-5 4-8 10-8s10 3 10 8v2H2Z"/>','cloud'=>'<path d="M6 19a4 4 0 0 1-.7-7.9A6.5 6.5 0 0 1 18 9a5 5 0 0 1 0 10H6Z"/>','article'=>'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>','question'=>'<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 2-2.5 2-2.5 4m0 3h.01"/>','gift'=>'<path d="M4 10h16v11H4zM3 6h18v4H3zM12 6v15"/><path d="M12 6C5 6 6 0 9 2l3 4c7 0 6-6 3-4z"/>','heart'=>'<path d="M12 21S2 15 2 8a5 5 0 0 1 10-1A5 5 0 0 1 22 8c0 7-10 13-10 13Z"/>','notice'=>'<path d="m3 10 16-6v16L3 14zm4 5 2 6h4l-2-5M22 9v6"/>','download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>','search'=>'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>');
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.(isset($paths[$name])?$paths[$name]:$paths['article']).'</svg>';
}
function help_categories() { return array('使用教程'=>'教程','常见问题'=>'常见问题','活动公告'=>'活动说明','公益节点'=>'公益项目'); }
function help_category_icon($category) { $icons=array('使用教程'=>'article','常见问题'=>'question','活动公告'=>'gift','公益节点'=>'heart');return isset($icons[$category])?$icons[$category]:'article'; }
function help_nav($active) {
    $links='';foreach(help_categories() as $category=>$label){$links.='<a href="/articles?category='.rawurlencode($category).'"'.($active===$category?' class="is-active" aria-current="page"':'').'>'.help_icon(help_category_icon($category)).'<span>'.e($label).'</span></a>';}
    foreach(array('announcements'=>array('/announcements','notice','公告中心'),'downloads'=>array('/downloads','download','软件下载')) as $key=>$item)$links.='<a href="'.$item[0].'"'.($active===$key?' class="is-active" aria-current="page"':'').'>'.help_icon($item[1]).'<span>'.$item[2].'</span></a>';
    return '<aside class="help-reference-sidebar"><h2><a href="/articles">帮助中心</a></h2><nav aria-label="帮助中心">'.$links.'</nav><a class="help-support-link" href="/tickets#new-ticket">需要帮助？提交工单 →</a></aside>';
}
function help_query($name,$max=100) {
    if(!isset($_GET[$name]))return '';
    $value=$_GET[$name];if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>$max)throw new InvalidArgumentException('筛选参数无效，请重新选择。');return trim($value);
}
function help_article_list($pdo, $config=array()) {
    try {
        $q=help_query('q'); $category=help_query('category',30); $view=help_query('view',10);
        if ($category!=='' && !isset(help_categories()[$category])) throw new InvalidArgumentException('文章分类无效。');
        if ($view!=='' && $view!=='all') throw new InvalidArgumentException('文章视图无效。');
    } catch (InvalidArgumentException $ex) {
        http_response_code(400);
        return '<div class="ep-page hc-page"><section class="hc-empty"><h1>查询参数无效</h1><p>'.e($ex->getMessage()).'</p><a href="/articles">返回帮助中心 →</a></section></div>';
    }
    $all=$pdo->query('SELECT * FROM articles WHERE status="published" ORDER BY published_at DESC,id DESC')->fetchAll();
    $matched=array(); $counts=array_fill_keys(array_keys(help_categories()),0);
    foreach ($all as $a) {
        if ($q!=='' && mb_stripos($a['title'].' '.$a['summary'],$q,0,'UTF-8')===false) continue;
        $matched[]=$a;
        if (isset($counts[$a['category']])) $counts[$a['category']]++;
    }
    $filtered=$q!=='' || $category!=='' || $view==='all';
    $tabs='<a href="/articles?'.e(http_build_query(array('view'=>'all','q'=>$q),'','&',PHP_QUERY_RFC3986)).'#help-results"'.($category===''?' aria-current="page"':'').'>全部 <span>('.count($matched).')</span></a>';
    foreach (help_categories() as $key=>$label) $tabs.='<a href="/articles?'.e(http_build_query(array('category'=>$key,'q'=>$q),'','&',PHP_QUERY_RFC3986)).'#help-results"'.($category===$key?' aria-current="page"':'').'>'.e($label).' <span>('.$counts[$key].')</span></a>';
    $articles=''; $resultCount=0;
    foreach ($matched as $a) {
        if ($category!=='' && $a['category']!==$category) continue;
        $resultCount++;
        $articles.='<article class="hc-article"><div><h3><a href="/articles/'.(int)$a['id'].'">'.e($a['title']).'</a></h3>'.($filtered?'<p>'.e($a['summary']).'</p>':'').'</div><span>'.e($a['category']).'</span></article>';
    }
    if ($articles==='') $articles='<div class="hc-empty"><h3>暂无匹配文章</h3><p>请尝试其他关键词或分类，或提交工单获取帮助。</p><a href="/articles?view=all#help-results">查看全部文章 →</a></div>';
    $html='<div class="ep-page hc-page"><nav class="ep-crumb" aria-label="面包屑"><a href="/">首页</a><span>/</span><span>帮助中心</span></nav><header class="ep-intro hc-intro"><div><span class="ep-kicker">HELP &amp; SUPPORT</span><h1><span>需要帮助？</span><span>从这里找到答案。</span></h1><p>从购买、付款到交付与使用，按问题找到对应指引。</p></div></header><div class="hc-search-block"><form class="hc-search" method="get" action="/articles" role="search"><div>'.help_icon('search').'<input type="search" name="q" value="'.e($q).'" maxlength="100" aria-label="搜索文章标题或摘要" aria-describedby="help-search-scope" placeholder="搜索问题、教程或关键词…"></div>'.($category!==''?'<input type="hidden" name="category" value="'.e($category).'">':'').'<input type="hidden" name="view" value="all"><button type="submit">搜索</button></form><nav class="hc-hot" aria-label="热门搜索"><span>热门搜索：</span><a href="/articles?q='.rawurlencode('交付').'#help-results">查看交付内容</a><a href="/articles?q='.rawurlencode('订阅').'#help-results">导入订阅</a><a href="/articles?q='.rawurlencode('余额').'#help-results">余额支付</a><a href="/tickets#new-ticket">提交工单</a></nav><small id="help-search-scope">搜索已发布文章的标题和摘要，不搜索正文。</small></div>';
    if ($filtered) {
        $html.='<section class="hc-results" id="help-results"><header class="hc-section-head"><h2>'.($q!==''?'搜索结果':'全部文章').'</h2><a href="/articles">返回帮助首页 ↗</a></header><p class="hc-results-note">'.($q!==''?'关键词「'.e($q).'」 · ':'').'共 '.$resultCount.' 篇'.($category!==''?' · '.e($category):'').'</p><nav class="hc-article-tabs" aria-label="文章分类">'.$tabs.'</nav><div>'.$articles.'</div></section>';
    } else {
        $html.='<section class="hc-topics"><header class="hc-section-head"><h2>分类查找</h2><a href="/articles?view=all#help-results">查看全部文章 ↗</a></header><div class="hc-topic-grid">';
        foreach (array(array('book','新手入门','了解注册、选购与购买流程。','购买'),array('receipt','订单与支付','查看订单状态与支付说明。','支付'),array('shield','VPN订阅','订阅交付、有效期与配置。','订阅'),array('globe','海外账号','商品规格、交付内容与售后。','账号'),array('monitor','客户端与下载','按设备查找安装与配置教程。','客户端'),array('user','账户与安全','账号资料、密码与登录帮助。','密码')) as $topic) {
            $html.='<a class="hc-topic" href="/articles?q='.rawurlencode($topic[3]).'#help-results">'.help_icon($topic[0]).'<div><h3>'.e($topic[1]).'</h3><p>'.e($topic[2]).'</p><b>查看指引 →</b></div></a>';
        }
        $html.='</div></section><div class="hc-middle"><section class="hc-faq"><h2>常见问题</h2><nav class="hc-faq-filters" aria-label="常见问题分类">';
        foreach (array('all'=>'全部','payment'=>'购买支付','delivery'=>'交付使用','security'=>'账户安全') as $key=>$label) $html.='<button type="button" data-faq-filter="'.$key.'" aria-pressed="'.($key==='all'?'true':'false').'">'.$label.'</button>';
        $html.='</nav><div class="hc-faq-list">';
        $faqs=array(
            array('delivery','付款后在哪里查看交付内容？','付款确认后进入人工交付流程。交付完成后，登录本人账号，在订单详情中主动显示或复制交付内容。拼团订单需先成团成功，再进入人工交付。'),
            array('delivery','订阅有效期从什么时候开始计算？','以订单正式交付内容中登记的开通与到期时间为准。购买、付款或下载客户端不代表已开通，请在订单详情中核对交付信息。'),
            array('payment','余额不足可以与在线支付一起使用吗？','当前不支持余额与在线支付拆分付款。余额支付需要足额支付当前订单；余额不足时，请选择订单页面提供的其他可用支付方式。'),
            array('payment','海外账号商品支持优惠券或拼团吗？','海外账号商品不参与优惠券、拼团及相关营销活动。请在商品详情核对账号规格、交付内容和售后说明。'),
            array('security','忘记密码后如何找回？','前往登录页的「忘记密码」，通过注册邮箱申请重置邮件，并按邮件提示设置新密码。请勿向他人透露密码或重置链接。'),
            array('payment','如何申请售后或退款？','登录后可提交售后工单并关联订单。符合当前状态的订单可在订单详情申请退款，申请后由管理员审核处理；到账方式与进度以实际处理记录为准，不承诺即时退款。')
        );
        foreach ($faqs as $i=>$faq) $html.='<details data-faq-category="'.$faq[0].'"'.($i===0?' open':'').'><summary>'.e($faq[1]).'</summary><p>'.e($faq[2]).'</p>'.($i===4?'<a href="/password/forgot">找回密码 ↗</a>':'').'</details>';
        $html.='</div></section><aside class="hc-start"><h2>第一次使用？</h2><p>按照这份指南，完成你的第一次购买与配置。</p><ol>';
        foreach (array(array('/register','注册并登录账号'),array('/products','选择商品并完成付款'),array('/orders','查看订单与交付内容'),array('/articles?category='.rawurlencode('使用教程').'#help-results','参考教程完成使用配置')) as $i=>$step) $html.='<li><span>0'.($i+1).'</span><a href="'.e($step[0]).'">'.e($step[1]).' ↗</a></li>';
        $html.='</ol><a class="ep-button" href="/downloads">下载适用客户端 ↗</a></aside></div><section class="hc-guides"><header class="hc-section-head"><h2>使用指南</h2><a href="/articles?category='.rawurlencode('使用教程').'#help-results">全部教程 ↗</a></header><div>'.$articles.'</div></section>';
    }
    $brand=isset($config['app']['name'])?$config['app']['name']:'小云店';
    $html.='<section class="hc-support"><div><h2>还没有找到答案？</h2><p>登录后提交工单，可关联订单并补充问题说明。</p></div><div><nav><a class="ep-button hc-primary" href="/tickets#new-ticket">提交工单 ↗</a><a class="ep-button" href="/tickets">我的工单 ↗</a></nav><small>请勿在公开反馈中填写密码或完整订阅链接。</small></div></section><aside class="hc-feedback"><b>反馈建议</b><p>分享你的建议，审核通过后可按规则获得余额奖励；不保证提交即获奖。</p><a href="/feedback#feedback-form">提交反馈 ↗</a></aside><footer class="ep-footer"><div><a href="/">'.help_icon('cloud').'<b>'.e($brand).'</b></a><p>数字服务，简单一点。</p></div><nav><a href="/announcements">系统公告</a><a href="/articles">帮助中心</a></nav><small>© '.date('Y').' '.e($brand).'</small></footer></div>';
    if (!$filtered) $html.='<script>(function(){var root=document.querySelector(".hc-faq");root.querySelectorAll("[data-faq-filter]").forEach(function(button){button.addEventListener("click",function(){root.querySelectorAll("[data-faq-filter]").forEach(function(item){item.setAttribute("aria-pressed",String(item===button))});var first=true;root.querySelectorAll("details[data-faq-category]").forEach(function(item){item.hidden=button.dataset.faqFilter!=="all"&&item.dataset.faqCategory!==button.dataset.faqFilter;item.open=!item.hidden&&first;if(!item.hidden)first=false})})})})();</script>';
    return $html;
}
function help_article_detail($pdo,$id) {
    $a=false;if(ctype_digit((string)$id)){$s=$pdo->prepare('SELECT * FROM articles WHERE id=? AND status="published"');$s->execute(array($id));$a=$s->fetch();}
    if(!$a){http_response_code(404);return array('文章不存在','<div class="help-reference-empty"><h1>文章不存在或尚未发布</h1><a href="/articles">返回帮助中心 →</a></div>');}
    $body='<nav class="help-reference-breadcrumb" aria-label="面包屑"><a href="/articles">帮助中心</a><span>›</span><a href="/articles?category='.rawurlencode($a['category']).'">'.e($a['category']).'</a><span>›</span><span>'.e($a['title']).'</span></nav><div class="help-reference-layout">'.help_nav($a['category']).'<article class="help-reference-panel help-reference-article"><header><h1>'.e($a['title']).'</h1><div class="help-reference-meta"><span class="help-reference-badge">'.e($a['category']).'</span><time>'.e(substr((string)$a['published_at'],0,10)).'</time></div></header>'.($a['summary']!==''?'<p class="help-reference-summary">'.e($a['summary']).'</p>':'').'<div class="help-reference-prose">'.nl2br(e($a['content'])).'</div><footer class="help-reference-footer"><a href="/articles?category='.rawurlencode($a['category']).'">← 返回分类文章</a><a href="/tickets#new-ticket">仍需帮助？提交工单 →</a></footer></article></div>';
    return array($a['title'],$body);
}
