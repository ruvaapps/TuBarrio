<?php
// helpers/jwt_helper.php

class JWTHelper {
    // CAMBIAR esta clave secreta por una frase larga y segura
    private static $secret_key = 'Barrio_Secret_Key_Super_Segura_2026!'; 

    public static function createToken($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload['exp'] = time() + (86400 * 30); // El token vence en 30 días

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secret_key, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function verifyToken($jwt) {
        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) !== 3) return false;

        $header = self::base64UrlDecode($tokenParts[0]);
        $payload = self::base64UrlDecode($tokenParts[1]);
        $signatureProvided = $tokenParts[2];

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, self::$secret_key, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        if ($base64UrlSignature !== $signatureProvided) return false;

        $payloadData = json_decode($payload, true);
        if (isset($payloadData['exp']) && $payloadData['exp'] < time()) return false;

        return $payloadData;
    }

    private static function base64UrlEncode($text) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($text));
    }

    private static function base64UrlDecode($text) {
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $text));
    }
}