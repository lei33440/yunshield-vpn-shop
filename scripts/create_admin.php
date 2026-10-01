<?php
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/app/Core/Database.php';
fwrite(STDOUT, '管理员邮箱: ');
$email = trim((string)fgets(STDIN));
$password = getenv('VPN_ADMIN_PASSWORD');
if (!$password) { fwrite(STDOUT, '管理员密码（建议至少 12 位）: '); $password = trim((string)fgets(STDIN)); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) exit("邮箱无效或密码少于 12 位。\n");
$pdo = Database::connect($config);
$stmt = $pdo->prepare('INSERT INTO admin_users(email,password_hash,display_name,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
try { $now = date('Y-m-d H:i:s'); $stmt->execute(array($email,password_hash($password,PASSWORD_DEFAULT),'系统管理员','admin','active',$now,$now)); echo "管理员创建成功。\n"; }
catch (PDOException $e) { exit("创建失败：邮箱可能已存在。\n"); }
