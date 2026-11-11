<?php
/** SubBoost 诊断只使用白名单元数据，不读取请求、订阅标识、地址或凭据。 */
class SubBoostTaskService
{
    private $pdo;
    private $timezone;
    public function __construct($pdo,$config=array()){
        $this->pdo=$pdo;
        $this->timezone=new DateTimeZone(isset($config['app']['timezone'])?$config['app']['timezone']:date_default_timezone_get());
    }
    public static function statuses(){return array('pending'=>'待处理','waiting'=>'等待上游配置','retry'=>'等待重试','completed'=>'已完成');}
    public static function stages(){return array('eligibility'=>'检查订单资格','source_check'=>'校验服务来源','request_validation'=>'校验订单参数','create_subscription'=>'创建或恢复订阅','status_query'=>'查询上游状态','response_validation'=>'校验上游响应','identity_check'=>'核对订阅身份','dates_check'=>'校验订阅有效期','persist_dates'=>'保存有效期','delivery'=>'执行本地交付','complete'=>'完成任务');}
    public static function errors(){
        return array('order_ineligible'=>'订单暂不符合自动交付条件','invalid_configuration'=>'服务配置无效','invalid_duration'=>'订单时长无效','invalid_request'=>'订单请求参数不一致','invalid_response'=>'上游响应格式不符合约定','invalid_dates'=>'订阅时间不符合约定','invalid_subscription_url'=>'上游订阅地址不符合安全规则','identity_mismatch'=>'订阅身份不一致','source_mismatch'=>'服务来源已变更','dates_changed'=>'已确认的订阅时间发生变化','http_transport_failed'=>'网络传输失败','http_0'=>'未收到有效 HTTP 响应','http_400'=>'上游拒绝请求参数','http_401'=>'上游身份认证失败','http_403'=>'上游拒绝访问','http_404'=>'上游接口或订阅不存在','http_408'=>'上游请求超时','http_409'=>'上游请求冲突','http_422'=>'上游参数校验失败','http_429'=>'上游请求频率受限','http_500'=>'上游服务内部错误','http_502'=>'上游网关错误','http_503'=>'上游服务暂不可用','http_504'=>'上游网关超时','database_error'=>'本地数据库操作失败','delivery_failed'=>'本地交付未完成','sync_failed'=>'同步发生未分类异常','unknown_error'=>'历史错误详情已隐藏');
    }
    public static function transportReason($errno){
        if($errno===null)return '未记录';
        $labels=array(0=>'未报告 cURL 错误',5=>'无法解析代理',6=>'无法解析主机',7=>'无法连接服务',18=>'响应传输不完整',23=>'响应超出大小限制或接收中断',28=>'连接或请求超时',35=>'TLS 握手失败',47=>'重定向次数超限',52=>'服务返回空响应',55=>'发送失败',56=>'接收失败',60=>'TLS 证书校验失败');
        return isset($labels[(int)$errno])?$labels[(int)$errno]:'其他传输错误（仅保留编号）';
    }
    public static function safeCode($code){
        if(!is_string($code))return 'unknown_error';
        if($code==='')return '';
        if(isset(self::errors()[$code])||preg_match('/\Ahttp_[1-5][0-9]{2}\z/D',$code))return $code;
        return 'unknown_error';
    }
    public static function exceptionCode($exception,$stage){
        if($exception instanceof PDOException)return 'database_error';
        $code=self::safeCode($exception->getMessage());
        if($code!==''&&$code!=='unknown_error')return $code;
        return $stage==='delivery'?'delivery_failed':'sync_failed';
    }
    public static function explanation($code){
        $code=self::safeCode($code);
        $labels=self::errors();
        return $code===''?'未记录错误':(isset($labels[$code])?$labels[$code]:'上游返回 HTTP '.substr($code,5));
    }
    public static function suggestion($code){
        $code=self::safeCode($code);
        $texts=array(
            ''=>'当前没有记录错误；这不等于 worker 正在运行。',
            'order_ineligible'=>'检查订单是否待交付、是否已付款、是否进入退款或已由人工交付，以及是否属于拼团。不要为恢复任务直接修改订单或资金状态。',
            'invalid_configuration'=>'检查 SubBoost 地址协议、主机格式及 API 密钥配置；如使用连接目标覆盖，也需检查其格式。页面不会显示密钥。',
            'invalid_duration'=>'核对订单购买时的时长快照和任务参数；不要直接更改已付款订单以绕过检查。',
            'invalid_request'=>'核对原订单号、购买时长与任务是否一致，保留原任务供排查。',
            'invalid_response'=>'检查上游接口版本及约定字段是否完整、类型是否正确。响应正文不保存于诊断记录。',
            'invalid_dates'=>'检查上游开通、到期时间及所购天数，确认时区和实际时长一致。',
            'invalid_subscription_url'=>'检查上游返回地址是否同源且符合约定路径；不要关闭 URL 安全校验。',
            'identity_mismatch'=>'核对上游按原订单号查询的订阅身份；不要删除任务后盲目重新创建。',
            'source_mismatch'=>'服务地址与任务创建时不一致。核实是否切换上游；不要直接改来源哈希绕过检查。',
            'dates_changed'=>'上游有效期与首次确认值不一致。核对上游修改记录后再处理，不要反复创建订阅。',
            'http_transport_failed'=>'检查 worker 所在主机或容器的 DNS、网络、端口、TLS 和连接超时；本地回环地址在容器内指向容器自身。详情中的传输错误编号可辅助排查。',
            'http_401'=>'检查商户 API 密钥是否正确、过期或已轮换，不要把密钥粘贴到日志或工单。',
            'http_403'=>'检查上游访问权限、IP 限制及反向代理访问规则。',
            'http_404'=>'确认上游版本和接口路径；若为状态查询，核对原订单在上游是否仍存在。',
            'http_409'=>'按原订单号核对上游已创建的订阅，避免重复开通。',
            'http_429'=>'检查调用频率和上游限流规则，保留退避重试，避免连续手动请求。',
            'database_error'=>'检查本地数据库连通性、表结构、唯一约束和服务日志；不要删除历史订单或交付记录来规避约束。',
            'delivery_failed'=>'核对本地交付状态、应用加密配置及数据库状态。上游可能已开通，先核实原订单再处理。',
            'sync_failed'=>'按失败阶段和时间排查本地服务、数据库及上游状态；不要把原始异常或凭据公开。',
            'unknown_error'=>'旧记录未使用已知安全原因码，原始内容不展示。可结合时间、阶段与上游安全日志定位。'
        );
        if(isset($texts[$code]))return $texts[$code];
        if(preg_match('/\Ahttp_5[0-9]{2}\z/D',$code))return '检查上游服务及代理健康状态，等待退避重试；请求可能已被上游接受，先按原订单核对，避免重复创建。';
        return '结合 HTTP 状态码检查上游参数、接口和服务状态；不要通过重复下单绕过问题。';
    }
    private static function value($input,$name){
        if(!isset($input[$name]))return '';
        if(!is_string($input[$name]))throw new InvalidArgumentException('筛选参数格式无效。');
        return trim($input[$name]);
    }
    private static function positiveId($value){
        if(is_int($value))$value=(string)$value;
        if(!is_string($value)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',$value))throw new InvalidArgumentException('任务编号或页码无效。');
        return $value;
    }
    public static function filters($input){
        $f=array('status'=>'','error'=>'','order_no'=>'','id'=>'','from'=>'','to'=>'','page'=>1);
        foreach(array('status','error','order_no','id','from','to') as $key)$f[$key]=self::value($input,$key);
        if($f['status']!==''&&!isset(self::statuses()[$f['status']]))throw new InvalidArgumentException('请选择有效的任务状态。');
        if($f['error']!==''&&(!isset(self::errors()[$f['error']])&&!preg_match('/\Ahttp_[1-5][0-9]{2}\z/D',$f['error'])))throw new InvalidArgumentException('请选择有效的错误原因。');
        if($f['order_no']!==''&&!preg_match('/\A[A-Za-z0-9_-]{1,40}\z/D',$f['order_no']))throw new InvalidArgumentException('请输入有效的完整订单号。');
        if($f['id']!=='')self::positiveId($f['id']);
        foreach(array('from','to') as $key){
            if($f[$key]==='')continue;
            if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$f[$key]))throw new InvalidArgumentException('请输入有效的起止日期。');
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$f[$key]);
            if(!$d||$d->format('Y-m-d')!==$f[$key]||$f[$key]<'1000-01-01'||$f[$key]>'9998-12-31')throw new InvalidArgumentException('请输入有效的起止日期。');
        }
        if($f['from']!==''&&$f['to']!==''&&$f['from']>$f['to'])throw new InvalidArgumentException('开始日期不能晚于结束日期。');
        $page=self::value($input,'page');if($page!==''){$page=self::positiveId($page);$f['page']=(int)min(100000000,(float)$page);}
        return $f;
    }
    private function time($value){
        if(!is_string($value)||$value==='')return '';
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $d&&$d->format('Y-m-d H:i:s')===$value?$d->setTimezone($this->timezone)->format('Y-m-d H:i:s'):'';
    }
    private function task($r){
        $states=self::statuses();$state=isset($states[$r['status']])?$r['status']:'unknown';
        $orderStates=array('pending_payment'=>'待付款','paid_waiting_group'=>'待成团','pending_delivery'=>'待交付','delivered'=>'已交付','cancelled'=>'已取消','refunding'=>'退款中','refunded'=>'已退款');
        $orderStatus=isset($orderStates[$r['order_status']])?$r['order_status']:'unknown';
        $code=self::safeCode($r['last_error']);
        $item=array('id'=>$r['id'],'order_id'=>$r['order_id'],'order_no'=>$r['order_no']===null?'':$r['order_no'],'order_status'=>$orderStatus,'order_status_label'=>isset($orderStates[$orderStatus])?$orderStates[$orderStatus]:'订单不存在或状态未识别','status'=>$state,'status_label'=>isset($states[$state])?$states[$state]:'未识别状态','retry_count'=>(int)$r['retry_count'],'error_code'=>$code,'error_text'=>self::explanation($code),'suggestion'=>self::suggestion($code));
        foreach(array('available_at','created_at','updated_at','completed_at','opened_at','expires_at') as $key)$item[$key]=$this->time($r[$key]);
        return $item;
    }
    private function select(){return 'SELECT t.id,t.order_id,t.status,t.retry_count,t.last_error,t.available_at,t.created_at,t.updated_at,t.completed_at,t.opened_at,t.expires_at,o.order_no,o.status order_status FROM subboost_sync_tasks t LEFT JOIN orders o ON o.id=t.order_id';}
    public function listing($input){
        $f=self::filters($input);$where=array('1=1');$args=array();
        foreach(array('status'=>'t.status','error'=>'t.last_error','order_no'=>'o.order_no','id'=>'t.id') as $key=>$column){
            if($f[$key]==='')continue;
            if($key==='error'&&$f[$key]==='unknown_error'){
                $codes=array_keys(self::errors());$codes=array_values(array_diff($codes,array('unknown_error')));$codes[]='';
                for($http=100;$http<=599;$http++)$codes[]='http_'.$http;
                $where[]='t.last_error NOT IN ('.implode(',',array_fill(0,count($codes),'?')).')';$args=array_merge($args,$codes);
            }else{$where[]=$column.'=?';$args[]=$f[$key];}
        }
        foreach(array('from','to') as $key)if($f[$key]!==''){
            $d=new DateTimeImmutable($f[$key].' 00:00:00',$this->timezone);if($key==='to')$d=$d->modify('+1 day');
            $where[]='t.created_at'.($key==='from'?'>=':'<').'?';$args[]=$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $where=' WHERE '.implode(' AND ',$where);
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM subboost_sync_tasks t LEFT JOIN orders o ON o.id=t.order_id'.$where);$s->execute($args);$total=(int)$s->fetchColumn();$pages=max(1,(int)ceil($total/50));$page=min($pages,$f['page']);$f['page']=$page;
        $s=$this->pdo->prepare($this->select().$where.' ORDER BY t.id DESC LIMIT 50 OFFSET '.(($page-1)*50));$s->execute($args);$items=array();foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$items[]=$this->task($r);
        return array('filters'=>$f,'items'=>$items,'total'=>$total,'pages'=>$pages,'page'=>$page);
    }
    public function detail($id,$historyPage=1){
        $id=self::positiveId($id);$historyPage=self::positiveId($historyPage);
        $s=$this->pdo->prepare($this->select().' WHERE t.id=?');$s->execute(array($id));$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
        $s=$this->pdo->prepare('SELECT COUNT(*) FROM subboost_sync_attempts WHERE task_id=?');$s->execute(array($id));$total=(int)$s->fetchColumn();$pages=max(1,(int)ceil($total/50));$page=(int)min($pages,(float)$historyPage);
        $s=$this->pdo->prepare('SELECT id,result,stage,error_code,http_status,transport_errno,duration_ms,started_at,finished_at FROM subboost_sync_attempts WHERE task_id=? ORDER BY id DESC LIMIT 50 OFFSET '.(($page-1)*50));$s->execute(array($id));$history=array();$results=array('running'=>'已开始，结果尚未记录','completed'=>'交付完成','waiting'=>'等待上游配置','retry'=>'失败，等待重试','skipped'=>'本次未执行交付','deferred'=>'交付前条件变化，已暂缓');$stages=self::stages();
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
            $code=self::safeCode($row['error_code']);$result=isset($results[$row['result']])?$row['result']:'unknown';$stage=isset($stages[$row['stage']])?$row['stage']:'unknown';
            $history[]=array('id'=>$row['id'],'result'=>$result,'result_label'=>isset($results[$result])?$results[$result]:'未识别结果','stage'=>$stage,'stage_label'=>isset($stages[$stage])?$stages[$stage]:'未记录阶段','error_code'=>$code,'error_text'=>self::explanation($code),'suggestion'=>self::suggestion($code),'http_status'=>$row['http_status']===null?null:(int)$row['http_status'],'transport_errno'=>$row['transport_errno']===null?null:(int)$row['transport_errno'],'duration_ms'=>$row['duration_ms']===null?null:(int)$row['duration_ms'],'started_at'=>$this->time($row['started_at']),'finished_at'=>$this->time($row['finished_at']));
        }
        return array('task'=>$this->task($r),'history'=>$history,'history_total'=>$total,'history_page'=>$page,'history_pages'=>$pages);
    }
    public function startAttempt($taskId){
        $s=$this->pdo->prepare('INSERT INTO subboost_sync_attempts(task_id,result,stage,error_code,started_at) VALUES(?,"running","eligibility","",?)');$s->execute(array($taskId,gmdate('Y-m-d H:i:s')));return $this->pdo->lastInsertId();
    }
    public function finishAttempt($id,$result,$stage,$code,$metadata,$durationMs){
        if(!in_array($result,array('completed','waiting','retry','skipped','deferred'),true)||!isset(self::stages()[$stage]))throw new LogicException('invalid_subboost_diagnostic');
        $status=isset($metadata['http_status'])?max(0,min(599,(int)$metadata['http_status'])):null;
        $errno=isset($metadata['transport_errno'])?max(0,min(9999,(int)$metadata['transport_errno'])):null;
        $s=$this->pdo->prepare('UPDATE subboost_sync_attempts SET result=?,stage=?,error_code=?,http_status=?,transport_errno=?,duration_ms=?,finished_at=? WHERE id=? AND result="running"');
        $s->execute(array($result,$stage,self::safeCode($code),$status,$errno,max(0,min(2147483647,(int)$durationMs)),gmdate('Y-m-d H:i:s'),$id));
    }
}
