<?php
class VpnCategoryService
{
    private $pdo;
    public function __construct($pdo){$this->pdo=$pdo;}
    public function all(){return $this->pdo->query('SELECT * FROM vpn_categories ORDER BY sort_order,id')->fetchAll();}
    public static function parseId($value)
    {
        if(!is_string($value))throw new InvalidArgumentException('订阅分类编号无效。');
        if($value==='')return null;
        $max=(string)PHP_INT_MAX;
        if(!preg_match('/\A[1-9][0-9]*\z/D',$value)||strlen($value)>strlen($max)||(strlen($value)===strlen($max)&&strcmp($value,$max)>0))throw new InvalidArgumentException('订阅分类编号无效。');
        return (int)$value;
    }
    public function requireId($id,$lock=false)
    {
        $id=self::parseId(is_int($id)?(string)$id:$id);
        if($id===null)throw new InvalidArgumentException('请选择有效的订阅分类。');
        if($lock&&!$this->pdo->inTransaction())throw new LogicException('分类锁需要事务。');
        $s=$this->pdo->prepare('SELECT * FROM vpn_categories WHERE id=?'.($lock?' LOCK IN SHARE MODE':''));$s->execute(array($id));$row=$s->fetch();
        if(!$row)throw new InvalidArgumentException('订阅分类不存在，请重新选择。');
        return $row;
    }
    public function save($id,$input,$admin)
    {
        if($id!==0){$id=self::parseId(is_int($id)?(string)$id:$id);if($id===null)throw new InvalidArgumentException('订阅分类编号无效。');}
        if(!is_array($input)||!isset($input['name'],$input['sort_order'])||!is_string($input['name'])||!is_string($input['sort_order']))throw new InvalidArgumentException('分类表单参数无效。');
        $name=trim($input['name']);$sort=$input['sort_order'];
        if($name===''||!mb_check_encoding($name,'UTF-8')||mb_strlen($name,'UTF-8')>100||!preg_match('/\A(?:0|[1-9][0-9]{0,8})\z/D',$sort))throw new InvalidArgumentException('请填写100字以内的分类名称和有效的非负排序。');
        $hasDescription=array_key_exists('description',$input);$description=null;
        if($hasDescription){
            if(!is_string($input['description'])||!mb_check_encoding($input['description'],'UTF-8'))throw new InvalidArgumentException('分组介绍必须为有效的文字。');
            $description=trim($input['description']);
            if(mb_strlen($description,'UTF-8')>2000)throw new InvalidArgumentException('分组介绍不能超过2000字。');
            if($description==='')$description=null;
        }
        $this->pdo->beginTransaction();
        try{
            $old=null;$now=date('Y-m-d H:i:s');
            if($id){
                $s=$this->pdo->prepare('SELECT * FROM vpn_categories WHERE id=? FOR UPDATE');$s->execute(array($id));$old=$s->fetch();
                if(!$old)throw new InvalidArgumentException('订阅分类不存在。');
                if(!$hasDescription)$description=$old['description'];
                $this->pdo->prepare('UPDATE vpn_categories SET name=?,description=?,sort_order=?,updated_at=? WHERE id=?')->execute(array($name,$description,(int)$sort,$now,$id));
            }else{
                $this->pdo->prepare('INSERT INTO vpn_categories(name,description,sort_order,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(array($name,$description,(int)$sort,$now,$now));$id=(int)$this->pdo->lastInsertId();
            }
            $this->audit($admin,$old?'update_vpn_category':'create_vpn_category',$id,array('before'=>$old,'after'=>array('name'=>$name,'description'=>$description,'sort_order'=>(int)$sort)));
            $this->pdo->commit();return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($e instanceof PDOException&&isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1062)throw new InvalidArgumentException('分类名称已存在。');throw $e;}
    }
    public function delete($id,$admin)
    {
        $id=self::parseId(is_int($id)?(string)$id:$id);if($id===null)throw new InvalidArgumentException('订阅分类编号无效。');
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare('SELECT * FROM vpn_categories WHERE id=? FOR UPDATE');$s->execute(array($id));$old=$s->fetch();if(!$old)throw new InvalidArgumentException('订阅分类不存在。');
            $s=$this->pdo->prepare('SELECT id FROM products WHERE vpn_category_id=? AND product_type="vpn" LIMIT 1 FOR UPDATE');$s->execute(array($id));
            if($s->fetch())throw new InvalidArgumentException('该分类仍有订阅套餐使用（含草稿、下架套餐），请先调整套餐分类后再删除。');
            $this->pdo->prepare('DELETE FROM vpn_categories WHERE id=?')->execute(array($id));
            $this->audit($admin,'delete_vpn_category',$id,array('before'=>$old));$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function audit($admin,$action,$id,$details)
    {
        $this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($admin,$action,'vpn_category',$id,json_encode($details,JSON_UNESCAPED_UNICODE),date('Y-m-d H:i:s')));
    }
}
