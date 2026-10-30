<?php
// Explicit isolated MySQL only; connection-local temporary fixtures never alter site tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = getenv('CHAOXI_TEST_DSN');
if (!$dsn || strpos($dsn, 'mysql:') !== 0 || preg_match_all('/(?:^|;)\s*dbname\s*=/i', substr($dsn,6)) !== 1 || !preg_match('/(?:^|;)dbname=(chaoxi_wallet_test_[A-Za-z0-9_]+)(?:;|$)/', substr($dsn,6), $match)) throw new RuntimeException('必须显式指定唯一chaoxi_wallet_test_前缀隔离库。');
$expected = $match[1];
$pdo = new PDO($dsn, getenv('CHAOXI_TEST_USER'), getenv('CHAOXI_TEST_PASSWORD'), array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $expected) throw new RuntimeException('实际测试库与显式隔离库不一致。');
date_default_timezone_set('Asia/Shanghai');
require dirname(__DIR__).'/app/Services/OperatingMetricsService.php';
require dirname(__DIR__).'/app/Services/WalletService.php';
function m_exec($sql,$args=array()) { global $pdo; $s=$pdo->prepare($sql); $s->execute($args); }
function m_eq($expected,$actual,$name) { if ($expected!==$actual) throw new RuntimeException($name.' expected '.var_export($expected,true).' got '.var_export($actual,true)); echo 'PASS '.$name.PHP_EOL; }
function m_reject($fn,$name) { try {$fn();} catch(InvalidArgumentException $e) {echo 'PASS '.$name.PHP_EOL;return;} throw new RuntimeException('未拒绝'.$name); }
// Shapes mirror the columns read from real Order/Payment/Wallet/Group services.
$definitions = array(
 'orders'=>'id INT PRIMARY KEY,paid_payment_record_id INT NULL,total_amount_cents INT,status VARCHAR(40),paid_at DATETIME NULL,refund_processed_at DATETIME NULL',
 'payment_records'=>'id INT PRIMARY KEY,order_id INT,amount_cents INT,gateway_type VARCHAR(30),status VARCHAR(30),paid_at DATETIME NULL',
 'wallet_transactions'=>'id INT PRIMARY KEY,amount_cents INT,type VARCHAR(40),created_at DATETIME',
 'group_payment_refunds'=>'id INT PRIMARY KEY,payment_id INT,order_id INT,channel VARCHAR(20),status VARCHAR(20),amount_cents INT,processed_at DATETIME NULL',
 'users'=>'id INT PRIMARY KEY,balance_cents INT',
 'support_tickets'=>'id INT PRIMARY KEY,status VARCHAR(30)',
 'feedback_submissions'=>'id INT PRIMARY KEY,status VARCHAR(30)'
);
foreach($definitions as $table=>$columns) $pdo->exec('CREATE TEMPORARY TABLE '.$table.' ('.$columns.') ENGINE=InnoDB');
$pdo->beginTransaction();
try {
    function m_order($id,$channel,$cents,$paid='2030-01-01 00:00:00',$status='delivered',$refund=null) {
        m_exec('INSERT INTO orders VALUES(?,?,?,?,?,?)',array($id,$id,$cents,$status,$paid,$refund));
        m_exec('INSERT INTO payment_records VALUES(?,?,?,?,?,?)',array($id,$id,$cents,$channel,$status==='refunded'?'refunded':'confirmed',$paid));
    }
    m_order(1,'alipay',800); // discounted real payment, not original list price
    m_order(2,'balance',1200,'2030-01-01 12:00:00','paid_waiting_group');
    m_order(3,'manual',300);
    m_order(4,'zero',0);
    m_order(5,'wxpay',500,'2030-01-01 23:59:59','refunded','2030-02-01 00:00:00');
    m_order(6,'balance',600,'2030-01-01 10:00:00','cancelled');
    m_order(7,'alipay',700,'2029-12-01 00:00:00','refunded','2030-01-02 00:00:00');
    m_order(8,'manual',400,'2029-12-01 00:00:00','refunded','2030-01-02 12:00:00');
    m_order(9,'balance',900,'2029-12-01 00:00:00','refunded','2030-01-02 12:00:00');
    m_order(10,'mystery',999);
    m_order(11,'alipay',100,'2030-01-03 00:00:00'); // exclusive end
    m_order(12,'alipay',111,'2029-12-31 23:59:59');
    m_order(13,'manual',50,'2029-12-01 00:00:00','delivered','2030-01-02 12:00:00'); // rejected refund date
    m_order(14,'alipay',10);m_exec('UPDATE payment_records SET amount_cents=11 WHERE id=14');
    m_order(15,'alipay',10);m_exec('UPDATE payment_records SET order_id=99 WHERE id=15');
    m_exec('INSERT INTO orders VALUES(16,NULL,123,"delivered","2030-01-01 12:00:00",NULL)');
    m_exec('INSERT INTO orders VALUES(17,NULL,123,"refunded",NULL,NULL)');
    m_exec('INSERT INTO payment_records VALUES(101,1,800,"alipay","superseded",NULL),(102,1,800,"alipay","pending",NULL),(103,1,800,"alipay","conflict","2030-01-01 10:00:00"),(104,1,800,"alipay","confirmed","2030-01-01 10:00:00"),(105,1,800,"alipay","refunded","2030-01-01 10:00:00")');
    m_exec('INSERT INTO group_payment_refunds VALUES(1,7,7,"external","completed",700,"2030-01-02 00:00:00"),(2,9,9,"balance","completed",900,"2030-01-02 12:00:00"),(3,105,1,"external","completed",800,"2030-01-02 12:00:00"),(4,103,1,"external","pending",800,NULL)');
    m_exec('INSERT INTO wallet_transactions VALUES(1,600,"order_refund","2030-01-02 00:00:00"),(2,900,"order_refund","2030-01-02 12:00:00"),(3,200,"feedback_reward","2030-01-01 10:00:00"),(4,80,"referral_credit","2030-01-01 10:00:00"),(5,-20,"referral_reversal","2030-01-02 10:00:00"),(6,9000,"admin_adjust","2030-01-01 10:00:00"),(7,-100,"admin_adjust","2030-01-02 10:00:00"),(8,999,"order_refund","2030-01-03 00:00:00"),(9,-99,"order_refund","2030-01-02 10:00:00")');
    m_exec('INSERT INTO users VALUES(1,500),(2,100)');
    m_exec('INSERT INTO support_tickets VALUES(1,"open"),(2,"waiting_customer"),(3,"closed")');
    m_exec('INSERT INTO feedback_submissions VALUES(1,"pending"),(2,"rewarded")');
    $service = new OperatingMetricsService($pdo);
    $data=$service->snapshot('2030-01-01 00:00:00','2030-01-03 00:00:00');
    m_eq(array('online_cents'=>1300,'manual_cents'=>300,'balance_cents'=>1800,'zero_count'=>1,'settlement_cents'=>3400,'settlement_count'=>6,'balance_refund_cents'=>1500,'external_refund_cents'=>1100,'feedback_reward_cents'=>200,'referral_credit_cents'=>80,'referral_reversal_cents'=>-20,'admin_adjust_cents'=>8900),$data['totals'],'all exact integer totals, no joins or attempts multiply facts');
    m_eq(array('2030-01-01'=>3400,'2030-01-02'=>0),$data['trend'],'half-open boundaries and zero-filled trend');
    m_eq(array('missing_primary'=>2,'invalid_primary'=>3,'extra_payment'=>4,'undated_refund'=>1,'invalid_group_refund'=>0,'paid_cancelled_without_refund'=>0,'nonprimary_refund'=>2),$data['anomalies'],'unknown, mismatch, missing, conflict and late refunds quarantined');
    m_eq(1,$data['todos']['tickets'],'open only, not all unclosed tickets');
    m_eq(1,$data['todos']['feedback'],'pending feedback');
    m_eq(600,$data['todos']['balance_cents'],'balance liability not cash');
    $later=$service->snapshot('2030-02-01 00:00:00','2030-02-02 00:00:00');
    m_eq(500,$later['totals']['external_refund_cents'],'cross-period ordinary refund separately dated');
    m_eq(0,$later['totals']['settlement_cents'],'refund period never invents a sale');
    m_exec('UPDATE orders SET refund_processed_at="2030-02-01 00:00:00" WHERE id=7');
    m_eq(500,$service->snapshot('2030-02-01 00:00:00','2030-02-02 00:00:00')['totals']['external_refund_cents'],'group coverage deduplicates across periods');
    m_order(20,'alipay',200,'2029-12-01 00:00:00','refunded','2030-02-01 00:00:00');
    m_exec('INSERT INTO group_payment_refunds VALUES(20,20,20,"external","pending",200,NULL)');
    $boundary=$service->snapshot('2030-02-01 00:00:00','2030-02-02 00:00:00');
    m_eq(500,$boundary['totals']['external_refund_cents'],'pending group registration blocks ordinary refunded status fallback');
    m_eq(2,$boundary['todos']['group_external_refunds'],'pending external registrations remain in todo');
    m_exec('UPDATE group_payment_refunds SET status="completed",processed_at="2030-02-01 12:00:00" WHERE id=20');
    m_eq(700,$service->snapshot('2030-02-01 00:00:00','2030-02-02 00:00:00')['totals']['external_refund_cents'],'completed group registration enters exactly once');
    m_order(21,'wxpay',210,'2029-12-01 00:00:00','cancelled');
    m_eq(1,$service->snapshot('2030-02-01 00:00:00','2030-02-02 00:00:00')['anomalies']['paid_cancelled_without_refund'],'external paid cancellation without receipt is anomaly not refund');
    m_order(22,'alipay',220,'2029-12-01 00:00:00','refunded','2030-02-01 00:00:00');
    m_order(23,'alipay',230,'2029-12-01 00:00:00','refunded','2030-02-01 00:00:00');
    m_order(24,'alipay',240,'2029-12-01 00:00:00','refunded','2030-02-01 00:00:00');
    m_exec('UPDATE orders SET paid_at=NULL WHERE id=22');
    m_exec('UPDATE payment_records SET status="pending" WHERE id=23');
    m_exec('UPDATE payment_records SET gateway_type=NULL WHERE id=24');
    m_exec('INSERT INTO group_payment_refunds VALUES(22,22,22,"external","completed",220,"2030-02-01 12:00:00"),(23,23,23,"external","completed",230,"2030-02-01 12:00:00"),(24,24,24,"external","completed",240,"2030-02-01 12:00:00")');
    $invalid=$service->snapshot('2030-02-01 00:00:00','2030-02-02 00:00:00');
    m_eq(700,$invalid['totals']['external_refund_cents'],'missing paid date invalid status and NULL channel never become refunds');
    m_eq(3,$invalid['anomalies']['invalid_group_refund'],'NULL-safe invalid group primary facts remain visible');
    m_eq(true,$pdo->inTransaction(),'read-only service preserves caller transaction');
    m_eq(array('start'=>'2030-01-01 00:00:00','end'=>'2030-01-03 00:00:00','start_date'=>'2030-01-01','end_date'=>'2030-01-02'),OperatingMetricsService::range(array('start'=>'2030-01-01','end'=>'2030-01-02')),'inclusive UI dates become exclusive endpoint');
    foreach(array(array('start'=>array(),'end'=>'2030-01-02'),array('start'=>'2030-02-30','end'=>'2030-03-01'),array('days'=>array()),array('days'=>'15'),array('start'=>'2030-01-03','end'=>'2030-01-01')) as $q) m_reject(function()use($q){OperatingMetricsService::range($q);},'invalid range '.json_encode($q));
    m_eq('10000000.01',WalletService::formatAmount(1000000001),'accumulated cents formatter has no wallet cap or float');
    $pdo->rollBack();
    echo 'TEST_DATABASE='.$expected.PHP_EOL.'RESULT: operating metrics exact fixtures passed; no migrations or permanent-table writes'.PHP_EOL;
} catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
