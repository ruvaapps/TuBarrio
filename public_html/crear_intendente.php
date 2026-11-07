<?php
// crear_intendente.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

require_once __DIR__ . '/config/database.php';

$db = Database::getConnection();

// 1. Datos del Barrio
$nombre_barrio = "Barrio Cerrado Las Rosas";

// 2. Datos del Intendente
$nombre = "Carlos";
$apellido = "Intendente";
$email = "intendente@lasrosas.com";
$password_plana = "Admin1234*"; // Cambiá esto por la contraseña deseada
$telefono = "3511234567";

try {
    $db->beginTransaction();

    // A. Insertar el Barrio (solo con la columna 'nombre')
    $stmtBarrio = $db->prepare("INSERT INTO barrios (nombre) VALUES (:nombre)");
    $stmtBarrio->execute([':nombre' => $nombre_barrio]);
    $barrio_id = $db->lastInsertId();

    // B. Generar el hash seguro de la contraseña
    $password_hash = password_hash($password_plana, PASSWORD_DEFAULT);

    // C. Insertar al Usuario con rol 'intendente' y estado 'activo'
    $stmtUser = $db->prepare("
        INSERT INTO usuarios (barrio_id, nombre, apellido, email, telefono, password_hash, rol, estado)
        VALUES (:barrio_id, :nombre, :apellido, :email, :telefono, :password_hash, 'intendente', 'activo')
    ");

    $stmtUser->execute([
        ':barrio_id' => $barrio_id,
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':email' => $email,
        ':telefono' => $telefono,
        ':password_hash' => $password_hash
    ]);

    $db->commit();

    echo "<h2 style='color: green;'>¡Intendente y Barrio creados con éxito!</h2>";
    echo "<p><b>Email:</b> {$email}</p>";
    echo "<p><b>Contraseña temporal:</b> {$password_plana}</p>";
    echo "<p>Ya podés ir al <a href='login.html'>login.html</a> para iniciar sesión.</p>";

} catch (Exception $e) {
    $db->rollBack();
    echo "<h2 style='color: red;'>Error al crear el intendente:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}