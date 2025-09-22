<?php
require __DIR__ . '/mailer.php';

$destinatario = 'facturacionefr@gmail.com'; // cámbialo

$asunto = 'Prueba SMTP Hostinger';
$html = "<h3>Prueba OK</h3><p>Si ves esto, PHPMailer ya quedó.</p>";

list($ok, $err) = enviarCorreo($destinatario, $asunto, $html);

if ($ok) {
    echo "✅ Correo enviado correctamente a {$destinatario}";
} else {
    echo "❌ Error al enviar: {$err}";
}
