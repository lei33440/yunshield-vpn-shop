<?php
class Bootstrap
{
    public static function init()
    {
        $root = dirname(dirname(__DIR__));
        $config = require $root . '/config/config.php';
        date_default_timezone_set($config['app']['timezone']);
        spl_autoload_register(function ($class) use ($root) {
            $paths = array(
                $root . '/app/Core/' . $class . '.php',
                $root . '/app/Services/' . $class . '.php',
            );
            foreach ($paths as $path) {
                if (is_file($path)) { require_once $path; return; }
            }
        });
        require_once $root . '/app/Core/Security.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($config['security']['session_name']);
            $secure=!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';session_set_cookie_params(array('lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax'));
            session_start();
        }
        set_exception_handler(function ($e) use ($config, $root) {
            error_log(date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL, 3, $root . '/storage/logs/app.log');
            http_response_code(500);
            if (!empty($config['app']['debug'])) { echo '<pre>' . e($e->getMessage()) . '</pre>'; }
            else { echo '系统暂时不可用，请稍后再试。'; }
        });
        return $config;
    }
}
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}
function flash($type, $message)
{
    $_SESSION['_flash'][$type] = $message;
}
function get_flash($type)
{
    $message = isset($_SESSION['_flash'][$type]) ? $_SESSION['_flash'][$type] : null;
    unset($_SESSION['_flash'][$type]);
    return $message;
}
