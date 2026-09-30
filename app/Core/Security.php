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
        if (empty($_SESSION['user_id'])) { flash('error', '请先登录。'); redirect('/login'); }
        return (int) $_SESSION['user_id'];
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
