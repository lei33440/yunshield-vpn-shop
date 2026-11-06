<?php
// 仅内存数据库，执行真实文章入口，不加载业务配置。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/app/Core/Router.php';require $root.'/app/Core/HelpCenterPages.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function page($title,$body,$config){$GLOBALS['article_body']=$body;}
function article_ok($v,$label){if(!$v)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function article_run($path,$get=array()){$_GET=$get;http_response_code(200);$GLOBALS['article_body']='';$GLOBALS['router']->dispatch('GET',$path);return $GLOBALS['article_body'];}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE articles(id INTEGER PRIMARY KEY,title TEXT,summary TEXT,category TEXT,content TEXT,published_at TEXT,status TEXT)');
$insert=$pdo->prepare('INSERT INTO articles VALUES(?,?,?,?,?,?,?)');
$insert->execute(array(1,'VPN 购买教程','下单与交付说明','使用教程',"第一步\n<script>不可执行</script>\n0",'2026-11-01 10:00:00','published'));
$insert->execute(array(2,'VPN 常见问题','登录帮助','常见问题','正文私有词','2026-11-01 09:00:00','published'));
$insert->execute(array(3,'草稿标题','草稿摘要','使用教程','秘密草稿','2026-11-01 11:00:00','draft'));
$insert->execute(array(4,'下架标题','下架摘要','使用教程','秘密下架','2026-11-01 12:00:00','offline'));
$config=array();$router=new Router();$source=file_get_contents($root.'/public/index.php');$start=strpos($source,"\$router->get('/articles',");$end=strpos($source,'function lottery_panel(',$start);if($start===false||$end===false)throw new RuntimeException('文章路由边界缺失');eval(substr($source,$start,$end-$start));
article_ok(substr_count($source,"\$router->get('/articles/{id}',")===1,'文章详情只有一处实际注册，避免后注册覆盖');
$body=article_run('/articles');article_ok(strpos($body,'VPN 购买教程')!==false&&strpos($body,'VPN 常见问题')!==false&&strpos($body,'草稿标题')===false&&strpos($body,'下架标题')===false,'列表只显示已发布文章');
article_ok(strpos($body,'hc-intro')!==false&&strpos($body,'hc-article')!==false&&strpos($body,'hc-topic-grid')!==false&&strpos($body,'help-reference-sidebar')===false&&strpos($body,'href="/articles/1"')!==false,'真实列表使用新布局与详情入口');
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new DOMXPath($doc);
article_ok($xpath->query('//h1')->length===1&&$xpath->query('//a[@class="hc-topic"]')->length===6,'帮助首页只有一个主标题与六个分类指引');
article_ok($xpath->query('//details[@data-faq-category]')->length===6&&$xpath->query('//details[@data-faq-category][@open]')->length===1,'默认六个FAQ且仅首条展开');
article_ok($xpath->query('//button[@data-faq-filter][@aria-pressed="true"]')->length===1,'FAQ筛选唯一选中态');
article_ok(strpos($body,'/password/forgot')!==false&&strpos($body,'/tickets#new-ticket')!==false&&strpos($body,'/downloads')!==false&&strpos($body,'/feedback#feedback-form')!==false,'安全与支持入口均连接现有路由');
$body=article_run('/articles',array('view'=>'all'));article_ok(strpos($body,'hc-results')!==false&&strpos($body,'hc-topic-grid')===false&&strpos($body,'VPN 购买教程')!==false&&strpos($body,'VPN 常见问题')!==false,'全部文章真实入口与默认概览区分');
$body=article_run('/articles',array('q'=>'VPN','category'=>'使用教程'));article_ok(strpos($body,'VPN 购买教程')!==false&&strpos($body,'VPN 常见问题')===false,'搜索与分类交集');article_ok(strpos($body,'name="category" value="使用教程"')!==false&&strpos($body,'q=VPN')!==false&&strpos($body,'(2)')!==false,'分类与搜索互相保留且计数基于匹配集合');
$body=article_run('/articles',array('q'=>'正文私有词'));article_ok(strpos($body,'暂无匹配文章')!==false&&strpos($body,'href="/articles/2"')===false,'搜索不包含正文');
foreach(array(array('q'=>array('bad')),array('q'=>str_repeat('长',101)),array('q'=>"\xff"),array('category'=>'未知'),array('category'=>array('x')),array('view'=>array('all')),array('view'=>'unknown')) as $get){article_run('/articles',$get);article_ok(http_response_code()===400,'异常查询返回400');}
$body=article_run('/articles',array('q'=>'"><script>'));article_ok(strpos($body,'value="&quot;&gt;&lt;script&gt;"')!==false&&strpos($body,'<script>')===false,'搜索输入安全转义');
$body=article_run('/articles/1');article_ok(http_response_code()===200&&strpos($body,'help-reference-sidebar')!==false&&strpos($body,'help-reference-article')!==false,'文章详情使用帮助侧栏和正文卡');article_ok(strpos($body,'help-reference-summary')!==false&&strpos($body,'下单与交付说明')!==false,'详情保留真实文章摘要');article_ok(strpos($body,'&lt;script&gt;不可执行&lt;/script&gt;')!==false&&strpos($body,'<script>')===false&&strpos($body,'<br />')!==false,'正文纯文本转义并保留换行');
foreach(array('3','4','999','bad','1 OR 1=1') as $id){$body=article_run('/articles/'.$id);article_ok(http_response_code()===404&&strpos($body,'秘密')===false,'非法或未发布详情404 '.$id);}
$pdo->exec('DELETE FROM articles');$body=article_run('/articles');article_ok(strpos($body,'暂无匹配文章')!==false,'空库不填充示例文章');
echo "ARTICLE PAGE TESTS PASSED\n";
