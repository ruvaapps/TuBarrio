<?php
// actualizar_rol.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getConnection();
    
    // Cambiar el rol a 'intendente' basándose en tu email de acceso
    $email = "intendente@lasrosas.com"; // Reemplazá con tu email si usaste otro
    
    $stmt = $db->prepare("UPDATE usuarios SET rol = 'intendente' WHERE email = :email");
    $stmt->execute([':email' => $email]);

    if ($stmt->rowCount() > 0) {
        echo "<h2 style='color: green;'>¡Rol actualizado exitosamente a 'intendente'!</h2>";
        echo "<p>Ya podés cerrar sesión y volver a ingresar en <a href='login.html'>login.html</a>.</p>";
    } else {
        echo "<h2 style='color: orange;'>No se encontró ningún usuario con ese email.</h2>";
    }

} catch (Exception $e) {
    echo "<h2 style='color: red;'>Error: " . $e->getMessage() . "</h2>";
}