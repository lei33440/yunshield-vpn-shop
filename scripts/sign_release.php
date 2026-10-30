<?php
/** Sign an already-built manifest with an explicitly supplied Ed25519 private key. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
function signReleaseManifest($manifestPath, $privateKeyPath, $signaturePath, $expectedPublicPath = null)
{
    if (!function_exists('sodium_crypto_sign_detached')) throw new RuntimeException('Sodium extension is required.');
    if (!is_file($manifestPath) || !is_readable($manifestPath)) throw new RuntimeException('Manifest is missing.');
    if (!is_file($privateKeyPath) || !is_readable($privateKeyPath)) throw new RuntimeException('Explicit private key path is missing.');
    $json = file_get_contents($manifestPath); $private = file_get_contents($privateKeyPath);
    if ($json === false || $private === false) throw new RuntimeException('Cannot read signing inputs.');
    if ($expectedPublicPath === null) throw new RuntimeException('必须明确指定受信任公钥文件。');
    // Accept raw 32/64-byte keys or base64; never print key material.
    $decoded = in_array(strlen($private),array(SODIUM_CRYPTO_SIGN_SEEDBYTES,SODIUM_CRYPTO_SIGN_SECRETKEYBYTES),true)?false:base64_decode(trim($private), true);
    if ($decoded !== false && in_array(strlen($decoded), array(SODIUM_CRYPTO_SIGN_SEEDBYTES, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES), true)) $private = $decoded;
    if (!in_array(strlen($private), array(SODIUM_CRYPTO_SIGN_SEEDBYTES, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES), true)) throw new RuntimeException('Private key must be raw/base64 Ed25519 seed or secret key.');
    if (strlen($private) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
        $pair = sodium_crypto_sign_seed_keypair($private);
        sodium_memzero($private);
        $private = sodium_crypto_sign_secretkey($pair);
        sodium_memzero($pair);
    }
    $manifest=json_decode($json,true);
    if(!is_array($manifest)||!isset($manifest['version'],$manifest['package'],$manifest['sha256'],$manifest['files'])||!is_array($manifest['files'])||!isset($manifest['migration_required'])||!is_bool($manifest['migration_required'])||!preg_match('/\Ayunshield-vpn-shop-'.preg_quote($manifest['version'],'/').'\.zip\z/D',$manifest['package']))throw new RuntimeException('签名清单格式无效。');
    $package=dirname($manifestPath).'/'.$manifest['package'];
    if(!is_file($package)||!hash_equals($manifest['sha256'],hash_file('sha256',$package)))throw new RuntimeException('签名前更新包哈希校验失败。');
    $zip=new ZipArchive();if($zip->open($package)!==true)throw new RuntimeException('无法读取更新包。');
    try{
        if(trim((string)$zip->getFromName('VERSION'))!==$manifest['version'])throw new RuntimeException('更新包版本不一致。');
        if($zip->numFiles!==count($manifest['files']))throw new RuntimeException('更新包文件数量不一致。');
        foreach($manifest['files'] as $path=>$hash){$bytes=$zip->getFromName($path);if($bytes===false||!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('更新包文件校验失败。');}
    }finally{$zip->close();}
    $public = sodium_crypto_sign_publickey_from_secretkey($private);
    if ($expectedPublicPath !== null) {
        if (!is_file($expectedPublicPath)) throw new RuntimeException('Expected public key path is missing.');
        $expected = trim((string)file_get_contents($expectedPublicPath)); $decodedExpected = base64_decode($expected, true);
        if ($decodedExpected !== false) $expected = $decodedExpected;
        if (!hash_equals($expected, $public)) throw new RuntimeException('Private key does not match the supplied public key.');
    }
    try { $sig = sodium_crypto_sign_detached($json, $private); }
    finally { sodium_memzero($private); }
    $encoded = base64_encode($sig) . "\n";
    $fp = @fopen($signaturePath, 'xb');
    if (!$fp || fwrite($fp, $encoded) !== strlen($encoded)) { if ($fp) fclose($fp); throw new RuntimeException('Refusing to overwrite or cannot write signature.'); }
    fclose($fp);
    return array('manifest_sha256'=>hash('sha256', $json), 'signature_bytes'=>strlen($sig), 'public_key_base64'=>base64_encode($public));
}
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $o = getopt('', array('manifest:', 'private-key:', 'signature:', 'public-key:'));
        if (!isset($o['manifest'], $o['private-key'], $o['signature'], $o['public-key'])) throw new RuntimeException('Usage: php scripts/sign_release.php --manifest=PATH --private-key=PATH --signature=PATH --public-key=PATH.');
        $r = signReleaseManifest($o['manifest'], $o['private-key'], $o['signature'], isset($o['public-key'])?$o['public-key']:null);
        echo json_encode(array('manifest_sha256'=>$r['manifest_sha256'],'signature_bytes'=>$r['signature_bytes']), JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
