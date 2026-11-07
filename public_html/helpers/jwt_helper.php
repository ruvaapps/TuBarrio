<?php
// helpers/jwt_helper.php

class JWTHelper {
    private static function getJwtKeys() {
        $active = getenv('JWT_SECRET_KEY') ?: '';
        $previous = getenv('JWT_PREVIOUS_SECRET_KEY') ?: '';

        if ($active === '') {
            throw new RuntimeException('JWT_SECRET_KEY is not configured.');
        }

        return ['active' => $active, 'previous' => $previous];
    }

    public static function createToken($payload) {
        $keys = self::getJwtKeys();
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $now = time();
        $payload['jti'] = bin2hex(random_bytes(16));
        $payload['iat'] = $now;
        $payload['nbf'] = $now;
        $payload['exp'] = $now + (60 * 30); // Token de acceso: 30 minutos

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $keys['active'], true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function verifyToken($jwt) {
        try {
            $keys = self::getJwtKeys();
        } catch (Throwable $e) {
            error_log('JWTHelper::verifyToken config error: ' . $e->getMessage());
            return false;
        }

        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) !== 3) return false;

        $header = self::base64UrlDecode($tokenParts[0]);
        $payload = self::base64UrlDecode($tokenParts[1]);
        $signatureProvided = $tokenParts[2];

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);
        $message = $base64UrlHeader . "." . $base64UrlPayload;

        $signatureActive = self::base64UrlEncode(hash_hmac('sha256', $message, $keys['active'], true));
        $validSignature = hash_equals($signatureActive, $signatureProvided);

        if (!$validSignature && $keys['previous'] !== '') {
            $signaturePrevious = self::base64UrlEncode(hash_hmac('sha256', $message, $keys['previous'], true));
            $validSignature = hash_equals($signaturePrevious, $signatureProvided);
        }

        if (!$validSignature) return false;

        $payloadData = json_decode($payload, true);
        if (!is_array($payloadData)) return false;

        if (isset($payloadData['nbf']) && $payloadData['nbf'] > time()) return false;
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