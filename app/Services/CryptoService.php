<?php
class CryptoService
{
    private $key;
    public function __construct($config)
    {
        $configured = $config['security']['encryption_key'];
        $this->key = hash('sha256', $configured, true);
    }
    public function encrypt($plaintext)
    {
        $nonce = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) throw new RuntimeException('加密失败');
        return array(base64_encode($cipher), $nonce, $tag);
    }
    public function decrypt($ciphertext, $nonce, $tag)
    {
        $value = openssl_decrypt(base64_decode($ciphertext), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($value === false) throw new RuntimeException('交付内容不可用');
        return $value;
    }
}
