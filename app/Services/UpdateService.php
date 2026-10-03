<?php
class UpdateService
{
    private $config;
    private $root;

    public function __construct($config)
    {
        $this->config = $config;
        $this->root = dirname(dirname(__DIR__));
    }

    public function currentVersion()
    {
        $path = $this->root . '/VERSION';
        if (!is_file($path)) throw new RuntimeException('本地版本文件不存在。');
        $version = trim((string)file_get_contents($path));
        if (!$this->isVersion($version)) throw new RuntimeException('本地版本号格式无效。');
        return $version;
    }

    public function cachedResult()
    {
        $path = $this->cachePath();
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $cache = json_decode($raw, true);
        if (!is_array($cache) || !isset($cache['checked_at']) || !isset($cache['result']) || !is_array($cache['result'])) return null;
        $maxAge = $this->cacheSeconds();
        if ((int)$cache['checked_at'] + $maxAge < time()) return null;
        return $this->withCurrentVersion($cache['result']);
    }

    public function checkLatest($force = false)
    {
        $current = $this->currentVersion();
        $update = isset($this->config['update']) && is_array($this->config['update']) ? $this->config['update'] : array();
        if (isset($update['enabled']) && !$update['enabled']) return $this->result($current, 'disabled', '更新检测已关闭。');
        if (!$force) {
            $cached = $this->cachedResult();
            if ($cached !== null) return $cached;
        }
        $repository = isset($update['repository']) ? trim((string)$update['repository']) : '';
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) return $this->store($this->result($current, 'error', '更新仓库配置无效。'));
        $url = 'https://api.github.com/repos/' . $repository . '/releases/latest';
        try {
            $response = $this->request($url);
            $data = json_decode($response['body'], true);
            if (!is_array($data)) throw new RuntimeException('GitHub 返回的数据格式无效。');
            $tag = isset($data['tag_name']) ? trim((string)$data['tag_name']) : '';
            if (strpos($tag, 'v') === 0 || strpos($tag, 'V') === 0) $tag = substr($tag, 1);
            if (!$this->isVersion($tag)) return $this->store($this->result($current, 'no_release', 'GitHub 最新 Release 没有可比较的版本号。'));
            $releaseUrl = isset($data['html_url']) && filter_var($data['html_url'], FILTER_VALIDATE_URL) ? $data['html_url'] : '';
            if (strpos($releaseUrl, 'https://github.com/') !== 0) $releaseUrl = '';
            $latest = array(
                'status' => 'ok',
                'message' => version_compare($tag, $current, '>') ? '发现新版本。' : '当前已是最新版本。',
                'latest_version' => $tag,
                'release_name' => isset($data['name']) ? trim((string)$data['name']) : '',
                'published_at' => isset($data['published_at']) ? trim((string)$data['published_at']) : '',
                'release_url' => $releaseUrl,
                'has_update' => version_compare($tag, $current, '>'),
                'checked_at' => date('c'),
            );
            return $this->store($this->withCurrentVersion($latest));
        } catch (Exception $e) {
            $message = $e->getMessage();
            $status = $message === 'GitHub 尚未发布 Release。' ? 'no_release' : 'error';
            return $this->store($this->result($current, $status, $message));
        }
    }

    private function request($url)
    {
        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: yunshield-vpn-shop-update-checker\r\n",
        ), 'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)));
        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) throw new RuntimeException('无法连接 GitHub，请稍后重试。');
        $body = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) { fclose($stream); throw new RuntimeException('读取 GitHub 响应失败。'); }
            $body .= $chunk;
            if (strlen($body) > 1048576) { fclose($stream); throw new RuntimeException('GitHub 响应过大。'); }
        }
        fclose($stream);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) $status = (int)$matches[1];
        if ($status < 200 || $status >= 300) throw new RuntimeException($status === 404 ? 'GitHub 尚未发布 Release。' : 'GitHub 暂时无法提供版本信息。');
        return array('body' => $body);
    }

    private function result($current, $status, $message)
    {
        return $this->withCurrentVersion(array('status' => $status, 'message' => $message, 'latest_version' => '', 'release_name' => '', 'published_at' => '', 'release_url' => '', 'has_update' => false, 'checked_at' => date('c')));
    }

    private function withCurrentVersion($result)
    {
        $result['current_version'] = $this->currentVersion();
        return $result;
    }

    private function store($result)
    {
        $path = $this->cachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return $result;
        $payload = json_encode(array('checked_at' => time(), 'result' => $result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) @rename($tmp, $path);
        return $result;
    }

    private function cachePath()
    {
        return $this->root . '/storage/cache/update-check.json';
    }

    private function cacheSeconds()
    {
        $update = isset($this->config['update']) && is_array($this->config['update']) ? $this->config['update'] : array();
        $seconds = isset($update['cache_seconds']) ? (int)$update['cache_seconds'] : 900;
        return max(60, min(86400, $seconds));
    }

    private function isVersion($version)
    {
        return (bool)preg_match('/^\d+(?:\.\d+){1,3}$/', $version);
    }
}
