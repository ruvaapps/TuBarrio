<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

try {
    // Generar par de claves EC (Curva P-256 / prime256v1)
    $config = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ];
    
    $res = openssl_pkey_new($config);
    if (!$res) {
        throw new Exception("OpenSSL no pudo generar el par de claves. Verifica que la extensión php_openssl esté activa.");
    }

    openssl_pkey_export($res, $privateKeyPem);
    $details = openssl_pkey_get_details($res);
    
    // Convertir x e y a binario no comprimido (0x04 . x . y)
    $publicKeyBin = "\x04" . $details['ec']['x'] . $details['ec']['y'];

    // Convertir a formato Base64URL
    function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    $publicKey = base64url_encode($publicKeyBin);
    $privateKey = base64url_encode($details['ec']['d']);

    echo "<h2>¡Claves VAPID generadas con éxito!</h2>";
    echo "<p><b>VAPID Public Key:</b><br><code>" . $publicKey . "</code></p>";
    echo "<p><b>VAPID Private Key:</b><br><code>" . $privateKey . "</code></p>";
    echo "<hr><p style='color:red;'><b>Importante:</b> Copiá estas claves y elimina este archivo del servidor por seguridad.</p>";

} catch (\Throwable $e) {
    echo "<h2>Error:</h2><p>" . $e->getMessage() . "</p>";
}