<?php
class AppView
{
    public static function render($template, $data = array())
    {
        extract($data, EXTR_SKIP);
        $root = dirname(dirname(__DIR__));
        ob_start();
        require $root . '/app/Views/' . $template . '.php';
        $content = ob_get_clean();
        require $root . '/app/Views/layout.php';
    }
}
