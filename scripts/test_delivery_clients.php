<?php
// 纯离线回归：不加载业务配置，不连接数据库，不访问网络；Node 仅模拟页面交互。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
require $root.'/app/Services/SubBoostClient.php';
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function dc_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
// 复用现有订单测试的函数提取器，但不执行其路由或测试数据。
$test=file_get_contents($root.'/scripts/test_orders_page.php');
$start=strpos($test,'function orders_function(');$end=strpos($test,'function orders_run(',$start);
eval(substr($test,$start,$end-$start));
$source=file_get_contents($root.'/public/index.php');
foreach(array('order_detail_secret','order_detail_subscription','order_detail_script') as $name)eval(orders_function($source,$name));
$oldUrl=getenv('SUBBOOST_URL');$oldSecret=getenv('SUBBOOST_API_SECRET');
putenv('SUBBOOST_URL');putenv('SUBBOOST_API_SECRET');
try{
    $config=array('subboost'=>array('url'=>'https://delivery.example.test','api_secret'=>'fixture-only'));
    $original='https://Delivery.Example.Test:443/api/subscriptions/Fixture_token-123/config.yaml';
    $base=substr($original,0,-strlen('/config.yaml'));
    $formats=array('auto','mihomo','clash','sing-box','v2ray','surge','surfboard','quantumult-x','uri','base64','legacy');
    $urls=SubBoostClient::clientUrls($original,$config);
    dc_ok(array_keys($urls)===$formats,'完整十一项及顺序');
    foreach($formats as $format){$expected=$format==='legacy'?$original:($format==='auto'?$base.'/auto':$base.'/config?format='.$format);dc_ok($urls[$format]===$expected,'链接规则 '.$format);}
    $bad=array('https://external.example.test/api/subscriptions/token/config.yaml','https://delivery.example.test.evil.test/api/subscriptions/token/config.yaml','https://delivery.example.test@evil.test/api/subscriptions/token/config.yaml','https://evil.test@delivery.example.test/api/subscriptions/token/config.yaml','http://delivery.example.test/api/subscriptions/token/config.yaml','https://delivery.example.test:444/api/subscriptions/token/config.yaml',$original.'?x=1',$original.'?',$original.'#hash',$original.'#',str_replace('Fixture_token-123','token%2Fother',$original),str_replace('Fixture_token-123','..',$original),$original.'/',str_replace('/config.yaml','/config',$original),str_replace('/api/','//api/',$original)," ".$original,str_replace('/config.yaml',"/config.yaml\n",$original));
    foreach($bad as $i=>$url){dc_ok(SubBoostClient::clientUrls($url,$config)===array('legacy'=>$url),'拒转换且原样保留 '.$i);$html=order_detail_subscription($url,$config);dc_ok(strpos($html,'data-detail-client')===false&&strpos($html,e($url))!==false&&strpos($html,'本链接不支持多客户端切换')!==false,'历史链接展示与提示 '.$i);}
    dc_ok(SubBoostClient::clientUrls($original,array())===array('legacy'=>$original),'缺少配置保留原链接');
    $invalid=$config;$invalid['subboost']['url'].='/prefix';dc_ok(SubBoostClient::clientUrls($original,$invalid)===array('legacy'=>$original),'非法配置保留原链接');
    $html=order_detail_subscription($original,$config);
    $doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);
    dc_ok($xp->query('//select/option')->length===11&&$xp->query('//option[@selected and @value="auto"]')->length===1,'无JS默认选择auto');
    dc_ok($xp->query('//code[@id="subscription-link"]')->item(0)->textContent===$urls['auto'],'无JS初始链接为auto');
    foreach($xp->query('//select/option') as $option)dc_ok($option->getAttribute('data-url')===$urls[$option->getAttribute('value')],'服务端选项属性 '.$option->getAttribute('value'));
    dc_ok(strpos($html,'无法识别时返回 Mihomo')!==false&&strpos($html,'节点订阅格式仅含节点')!==false,'适配与兼容限制说明');
    $attack='https://old.example.test/?a=" onmouseover="alert(1)&x=<script>secret</script>';
    $escaped=order_detail_subscription($attack,$config);
    dc_ok(strpos($escaped,e($attack))!==false&&strpos($escaped,'<script>secret')===false&&strpos($escaped,'&quot;')!==false&&strpos($escaped,'&amp;')!==false,'历史秘密HTML安全转义');
    $temporary=order_detail_secret('temporary-subscription-link','服务地址（HTTPS）',$original);
    $account=order_detail_secret('order-account-content','完整账号交付内容',$attack,true);
    dc_ok(strpos($temporary,e($original))!==false&&strpos($temporary,'data-detail-client')===false&&strpos($account,e($attack))!==false&&strpos($account,'data-detail-client')===false,'账号及临时渲染保持原样');
    dc_ok(substr_count($source,"order_detail_subscription(isset(\$x['subscription'])?\$x['subscription']:'',\$config)")===1&&strpos($source,"if(\$isAccount){\$delivery=order_detail_secret('order-account-content'")!==false&&strpos($source,"order_detail_secret('temporary-subscription-link','服务地址（HTTPS）',\$o['temporary_subscription_url'])")!==false,'真实路由仅正式VPN接入切换');
    $script=order_detail_script();
    dc_ok(strpos($script,'innerHTML')===false&&strpos($script,'localStorage')===false&&strpos($script,'fetch(')===false&&strpos($script,'userAgent')===false,'脚本不存储不外传不猜测UA');
    $node=<<<'JS'
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const input=JSON.parse(fs.readFileSync(0,'utf8'));
(async()=>{
 let copied=[],revealed=false;
 const feedback={textContent:''};
 const row={classList:{toggle(name,on){assert.equal(name,'is-revealed');revealed=on;}},querySelector(){return feedback;}};
 const value={textContent:input.urls.auto,hidden:false,closest(){return row;}};
 const reveal={dataset:{detailReveal:'subscription-link'},attrs:{'aria-pressed':'true'},getAttribute(k){return this.attrs[k];},setAttribute(k,v){this.attrs[k]=v;},addEventListener(k,fn){this[k]=fn;}};
 const copy={dataset:{detailCopy:'subscription-link'},closest(){return row;},addEventListener(k,fn){this[k]=fn;}};
 const select={dataset:{detailClient:'subscription-link'},selectedIndex:0,options:Object.entries(input.urls).map(([format,url])=>({dataset:{url},textContent:format,hasAttribute(k){return k==='data-url';}})),addEventListener(k,fn){this[k]=fn;}};
 const root={contains(v){return v===value;},querySelector(){return reveal;},querySelectorAll(k){return k==='[data-detail-reveal]'?[reveal]:k==='[data-detail-copy]'?[copy]:[select];},classList:{add(k){assert.equal(k,'order-secrets-ready');}}};
 const navigator={clipboard:{writeText(text){copied.push(text);return Promise.resolve();}}};
 vm.runInNewContext(input.script,{document:{querySelector(){return root;},getElementById(){return value;}},navigator});
 assert.equal(value.hidden,true);assert.equal(revealed,false);
 for(let i=0;i<select.options.length;i++){
  select.selectedIndex=i;select.change();assert.equal(value.hidden,true);assert.equal(value.textContent,select.options[i].dataset.url);assert(feedback.textContent.includes(select.options[i].textContent));
  copy.click();await Promise.resolve();assert.equal(copied[copied.length-1],value.textContent);
 }
 reveal.click();assert.equal(value.hidden,false);assert.equal(revealed,true);
 select.selectedIndex=0;select.change();assert.equal(value.hidden,false);assert.equal(value.textContent,input.urls.auto);
 navigator.clipboard=null;copy.click();assert(feedback.textContent.includes('手动复制'));assert.equal(value.hidden,false);
 console.log('PASS Node：十一项切换后复制、掩码保持、显示状态保持、aria反馈及复制降级');
})().catch(error=>{console.error(error);process.exitCode=1;});
JS;
    $process=proc_open(array('node','-e',$node),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,$root);
    if(!is_resource($process))throw new RuntimeException('Node unavailable');
    fwrite($pipes[0],json_encode(array('urls'=>$urls,'script'=>substr($script,8,-9))));fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    dc_ok($status===0,'真实切换复制脚本Node运行');echo $stdout;if($stderr!=='')throw new RuntimeException($stderr);
    echo "DELIVERY CLIENT TESTS PASSED\n";
}finally{
    putenv($oldUrl===false?'SUBBOOST_URL':'SUBBOOST_URL='.$oldUrl);putenv($oldSecret===false?'SUBBOOST_API_SECRET':'SUBBOOST_API_SECRET='.$oldSecret);
}
