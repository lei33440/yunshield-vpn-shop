<?php
class Security
{
    public static function csrfToken()
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
    public static function checkCsrf($token)
    {
        if (!$token || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
            http_response_code(419); exit('请求已过期，请刷新页面重试。');
        }
    }
    public static function requireUser()
    {
        if (empty($_SESSION['user_id'])) {
            // 仅记住站内购前确认参数，不接受任意回跳地址或持久保存商品价格。
            if (parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH) === '/order-confirm') {
                $selection = array();
                foreach (array('product_id', 'plan_id', 'group_id') as $key) {
                    $value = isset($_GET[$key]) && is_string($_GET[$key]) ? filter_var($_GET[$key], FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1))) : false;
                    if ($value !== false) $selection[$key] = $value;
                }
                if (isset($selection['product_id'], $selection['plan_id'])) $_SESSION['purchase_return'] = '/order-confirm?' . http_build_query($selection, '', '&', PHP_QUERY_RFC3986);
            }
            flash('error', '请先登录。');
            redirect('/login');
        }
        $uid = (int) $_SESSION['user_id'];
        if (!array_key_exists('user_password_changed_at', $_SESSION)) { $_SESSION = array(); session_destroy(); flash('error', '请重新登录以验证会话。'); redirect('/login'); }
        if (array_key_exists('user_password_changed_at', $_SESSION)) {
            global $pdo;
            if (isset($pdo) && $pdo instanceof PDO) {
                $s = $pdo->prepare('SELECT password_changed_at FROM users WHERE id=?');
                $s->execute(array($uid));
                $changed = (string)$s->fetchColumn();
                if ($changed !== '' && $changed !== (string)$_SESSION['user_password_changed_at']) {
                    $_SESSION = array();
                    session_destroy();
                    flash('error', '密码已更新，请重新登录。');
                    redirect('/login');
                }
            }
        }
        return $uid;
    }
    public static function requireAdmin()
    {
        if (empty($_SESSION['admin_id'])) { flash('error', '请先登录管理员账号。'); redirect('/admin/login'); }
        return (int) $_SESSION['admin_id'];
    }
}
function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . e(Security::csrfToken()) . '">';
}
