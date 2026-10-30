<?php
class AccountPlatformService
{
    private $pdo;
    public function __construct($pdo){$this->pdo=$pdo;}
    public function all(){return $this->pdo->query('SELECT * FROM account_platforms ORDER BY sort_order,id')->fetchAll();}
    public function requireCode($code,$lock=false)
    {
        if(!is_string($code)||!preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D',$code))throw new InvalidArgumentException('平台编码无效。');
        if($lock&&!$this->pdo->inTransaction())throw new LogicException('平台锁需要事务。');
        $s=$this->pdo->prepare('SELECT * FROM account_platforms WHERE code=?'.($lock?' LOCK IN SHARE MODE':''));$s->execute(array($code));$r=$s->fetch();
        if(!$r)throw new InvalidArgumentException('平台不存在，请先在账号平台管理中创建。');
        return $r;
    }
    public function save($id,$input,$admin)
    {
        foreach(array('code','name','sort_order') as $key)if(!isset($input[$key])||!is_string($input[$key]))throw new InvalidArgumentException('平台表单参数无效。');
        $code=trim($input['code']);$name=trim($input['name']);$sort=$input['sort_order'];
        if(!preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D',$code)||$name===''||mb_strlen($name,'UTF-8')>100||!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$sort))throw new InvalidArgumentException('请填写有效的平台编码、名称和非负排序。');
        $this->pdo->beginTransaction();
        try{
            $old=null;$now=date('Y-m-d H:i:s');
            if($id){$s=$this->pdo->prepare('SELECT * FROM account_platforms WHERE id=? FOR UPDATE');$s->execute(array($id));$old=$s->fetch();if(!$old)throw new RuntimeException('平台不存在。');if($old['code']!==$code)throw new InvalidArgumentException('平台编码创建后不可修改。');$this->pdo->prepare('UPDATE account_platforms SET name=?,sort_order=?,updated_at=? WHERE id=?')->execute(array($name,(int)$sort,$now,$id));}
            else{$this->pdo->prepare('INSERT INTO account_platforms(code,name,sort_order,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(array($code,$name,(int)$sort,$now,$now));$id=$this->pdo->lastInsertId();}
            $this->audit($admin,$old?'update_account_platform':'create_account_platform',$id,array('before'=>$old,'after'=>array('code'=>$code,'name'=>$name,'sort_order'=>(int)$sort)));
            $this->pdo->commit();return $id;
        }catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')throw new InvalidArgumentException('平台编码已存在。');throw $e;}
    }
    public function delete($id,$admin)
    {
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare('SELECT * FROM account_platforms WHERE id=? FOR UPDATE');$s->execute(array($id));$old=$s->fetch();if(!$old)throw new RuntimeException('平台不存在。');
            $s=$this->pdo->prepare('SELECT id FROM products WHERE account_platform=? LIMIT 1 FOR UPDATE');$s->execute(array($old['code']));if($s->fetch())throw new RuntimeException('该平台仍有商品使用（含草稿、下架商品），请先调整商品平台后再删除。');
            $this->pdo->prepare('DELETE FROM account_platforms WHERE id=?')->execute(array($id));$this->audit($admin,'delete_account_platform',$id,array('before'=>$old));$this->pdo->commit();
        }catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function audit($admin,$action,$id,$details){$this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($admin,$action,'account_platform',$id,json_encode($details,JSON_UNESCAPED_UNICODE),date('Y-m-d H:i:s')));}
}
