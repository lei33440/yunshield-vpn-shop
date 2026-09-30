<?php
class Router
{
    private $routes = array();
    public function get($path, $handler) { $this->routes['GET'][$path] = $handler; }
    public function post($path, $handler) { $this->routes['POST'][$path] = $handler; }
    public function dispatch($method, $path)
    {
        $path = rtrim(parse_url($path, PHP_URL_PATH), '/') ?: '/';
        foreach (isset($this->routes[$method]) ? $this->routes[$method] : array() as $pattern => $handler) {
            $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $path, $matches)) {
                $args = array();
                foreach ($matches as $key => $value) if (!is_int($key)) $args[] = $value;
                return call_user_func_array($handler, $args);
            }
        }
        http_response_code(404); echo '页面不存在';
    }
}
