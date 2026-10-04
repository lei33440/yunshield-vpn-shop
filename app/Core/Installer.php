<?php
class Installer
{
    private $root;

    public function __construct($root)
    {
        $this->root = $root;
    }

    public function isConfigured()
    {
        return is_file($this->root . '/config/config.php');
    }

    public function isInstalled()
    {
        return $this->isConfigured() && is_file($this->root . '/storage/install.lock');
    }

    public function environment()
    {
        $checks = array(
            array('PHP 版本 >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>=')),
            array('PDO 扩展', class_exists('PDO')),
            array('pdo_mysql 扩展', extension_loaded('pdo_mysql')),
            array('OpenSSL 扩展', extension_loaded('openssl')),
            array('JSON 扩展', function_exists('json_encode')),
            array('Mbstring 扩展', extension_loaded('mbstring')),
            array('Session 扩展', function_exists('session_start')),
            array('Sodium 扩展（在线更新）', function_exists('sodium_crypto_sign_verify_detached')),
            array('ZipArchive 扩展（在线更新）', class_exists('ZipArchive')),
            array('config 目录可写', is_dir($this->root . '/config') && is_writable($this->root . '/config')),
            array('storage 目录可写', is_dir($this->root . '/storage') && is_writable($this->root . '/storage')),
        );
        return $checks;
    }

    public function install($input)
    {
        if ($this->isInstalled()) throw new RuntimeException('系统已经完成初始化，不能重复安装。');
        if ($this->isConfigured()) throw new RuntimeException('config/config.php 已存在，请删除或备份现有配置后再初始化。');
        $data = $this->validate($input);
        $this->testDatabase($data);
        $config = $this->buildConfig($data);
        $configPath = $this->root . '/config/config.php';
        $tmp = $configPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $written = false;
        try {
            if (@file_put_contents($tmp, $config, LOCK_EX) === false) throw new RuntimeException('无法写入配置文件，请检查 config 目录权限。');
            @chmod($tmp, 0600);
            if (!@rename($tmp, $configPath)) throw new RuntimeException('无法保存配置文件，请检查目录权限。');
            $written = true;
            $pdo = $this->connect($data);
            $this->initializeDatabase($pdo);
            $this->createAdmin($pdo, $data);
            $this->writeLock($data);
            return true;
        } catch (Exception $e) {
            if (!$written && is_file($tmp)) @unlink($tmp);
            if ($written && !is_file($this->root . '/storage/install.lock')) @unlink($configPath);
            throw new RuntimeException($this->safeMessage($e));
        }
    }

    public function validate($input)
    {
        $host = trim(isset($input['db_host']) ? $input['db_host'] : '');
        $port = filter_var(isset($input['db_port']) ? $input['db_port'] : '3306', FILTER_VALIDATE_INT);
        $name = trim(isset($input['db_name']) ? $input['db_name'] : '');
        $user = trim(isset($input['db_user']) ? $input['db_user'] : '');
        $password = isset($input['db_password']) ? (string)$input['db_password'] : '';
        $siteName = trim(isset($input['site_name']) ? $input['site_name'] : '小云铺加速器');
        $baseUrl = trim(isset($input['base_url']) ? $input['base_url'] : '');
        $timezone = trim(isset($input['timezone']) ? $input['timezone'] : 'Asia/Shanghai');
        $email = trim(isset($input['admin_email']) ? $input['admin_email'] : '');
        $displayName = trim(isset($input['admin_name']) ? $input['admin_name'] : '系统管理员');
        $adminPassword = isset($input['admin_password']) ? (string)$input['admin_password'] : '';
        $confirm = isset($input['admin_password_confirm']) ? (string)$input['admin_password_confirm'] : '';
        if ($host === '' || strlen($host) > 255 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $host)) throw new InvalidArgumentException('数据库主机格式无效。');
        if ($port === false || $port < 1 || $port > 65535) throw new InvalidArgumentException('数据库端口格式无效。');
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name) || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $user)) throw new InvalidArgumentException('数据库名和用户名只能包含字母、数字、下划线、美元符号或短横线。');
        if (strlen($siteName) < 1 || strlen($siteName) > 100) throw new InvalidArgumentException('站点名称长度必须为 1-100 个字符。');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)), array('http','https'), true)) throw new InvalidArgumentException('站点地址必须是有效的 HTTP 或 HTTPS 地址。');
        if (!in_array($timezone, timezone_identifiers_list(), true)) throw new InvalidArgumentException('时区无效。');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new InvalidArgumentException('管理员邮箱格式无效。');
        if ($displayName === '' || strlen($displayName) > 100) throw new InvalidArgumentException('管理员名称长度必须为 1-100 个字符。');
        if (strlen($adminPassword) < 12 || $adminPassword !== $confirm) throw new InvalidArgumentException('管理员密码至少 12 位，且两次输入必须一致。');
        return array('db_host'=>$host,'db_port'=>(int)$port,'db_name'=>$name,'db_user'=>$user,'db_password'=>$password,'site_name'=>$siteName,'base_url'=>rtrim($baseUrl,'/'),'timezone'=>$timezone,'admin_email'=>$email,'admin_name'=>$displayName,'admin_password'=>$adminPassword);
    }

    private function connect($data)
    {
        $dsn = 'mysql:host=' . $data['db_host'] . ';port=' . $data['db_port'] . ';dbname=' . $data['db_name'] . ';charset=utf8mb4';
        return new PDO($dsn, $data['db_user'], $data['db_password'], array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false));
    }

    private function testDatabase($data)
    {
        try { $pdo = $this->connect($data); $pdo->query('SELECT 1'); }
        catch (Exception $e) { throw new RuntimeException('数据库连接失败，请检查主机、端口、数据库名、用户名和密码。'); }
    }

    private function initializeDatabase($pdo)
    {
        $sql = @file_get_contents($this->root . '/database/schema.sql');
        if ($sql === false) throw new RuntimeException('数据库结构文件不存在。');
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) $pdo->exec($statement);
        require_once $this->root . '/scripts/migrate.php';
        run_migrations($pdo, $this->root);
    }

    private function createAdmin($pdo, $data)
    {
        $check = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE email=?');
        $check->execute(array($data['admin_email']));
        if ((int)$check->fetchColumn() > 0) throw new RuntimeException('管理员邮箱已经存在，请更换邮箱或使用现有配置。');
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO admin_users(email,password_hash,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute(array($data['admin_email'], password_hash($data['admin_password'], PASSWORD_DEFAULT), $data['admin_name'], 'admin', 'active', $now, $now));
    }

    private function buildConfig($data)
    {
        $config = array('app'=>array('name'=>$data['site_name'],'base_url'=>$data['base_url'],'timezone'=>$data['timezone'],'debug'=>false,'temporary_subscription_url'=>''),'db'=>array('dsn'=>'mysql:host='.$data['db_host'].';port='.$data['db_port'].';dbname='.$data['db_name'].';charset=utf8mb4','username'=>$data['db_user'],'password'=>$data['db_password']),'security'=>array('encryption_key'=>base64_encode(random_bytes(32)),'session_name'=>'vpn_shop_session'),'update'=>array('repository'=>'lei33440/yunshield-vpn-shop','branch'=>'main','enabled'=>true,'cache_seconds'=>900,'install_enabled'=>false,'public_key'=>'','backup_keep'=>3,'package_name_prefix'=>'yunshield-vpn-shop-'));
        return "<?php\nreturn " . var_export($config, true) . ";\n";
    }

    private function writeLock($data)
    {
        $dir = $this->root . '/storage';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('无法创建 storage 目录。');
        $path = $dir . '/install.lock';
        if (@file_put_contents($path, json_encode(array('installed_at'=>date('c'),'version'=>trim((string)@file_get_contents($this->root.'/VERSION'))), JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('无法写入安装锁，请检查 storage 目录权限。');
        @chmod($path, 0600);
    }

    private function safeMessage($e)
    {
        $message = $e->getMessage();
        if (strpos($message, '管理员邮箱已经存在') !== false || strpos($message, '目录') !== false || strpos($message, '文件') !== false) return $message;
        return strpos($message, '数据库') !== false ? $message : '初始化失败，请检查服务器权限和数据库配置。';
    }
}
