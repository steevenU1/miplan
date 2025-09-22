<?php
// mailer.php — Helper de envío de correos con PHPMailer (Composer)

// Namespaces
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Autoload de Composer con guard
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    die('Falta vendor/autoload.php. Corre: composer require phpmailer/phpmailer en la raíz del proyecto.');
}
require_once $autoload;

// Cargar configuración (sin redeclarar si lo incluyen dos veces)
if (!function_exists('mailer_load_config')) {
    function mailer_load_config(): array {
        $cfg = [];
        $local = __DIR__ . '/env.local.php';
        $prod  = __DIR__ . '/env.prod.php';
        if (file_exists($local)) {
            $cfg = require $local;
        } elseif (file_exists($prod)) {
            $cfg = require $prod;
        }
        $smtp = $cfg['smtp'] ?? [];
        return [
            'host'       => $smtp['HOST'] ?? 'smtp.hostinger.com',
            'port'       => (int)($smtp['PORT'] ?? 465),
            'secure'     => $smtp['SECURE'] ?? 'ssl',    // 'ssl' ó 'tls'
            'username'   => $smtp['USER'] ?? '',
            'password'   => $smtp['PASS'] ?? '',
            'from_email' => $smtp['FROM_EMAIL'] ?? '',
            'from_name'  => $smtp['FROM_NAME'] ?? 'Notificaciones',
            'reply_to'   => $smtp['REPLY_TO'] ?? null,
            'debug'      => (bool)($smtp['DEBUG'] ?? false),
            'lang'       => $smtp['LANG'] ?? 'es',
            'timeout'    => (int)($smtp['TIMEOUT'] ?? 15),
        ];
    }
}

/**
 * Envía correo HTML.
 * @param string|array $to
 * @param string $subject
 * @param string $html
 * @param array $opts
 * @return array [bool $ok, ?string $error]
 */
function enviarCorreo($to, string $subject, string $html, array $opts = []): array {
    $cfg = mailer_load_config();

    $mail = new PHPMailer(true);
    try {
        $mail->setLanguage($cfg['lang']);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = $cfg['timeout'];

        // SMTP
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];

        // Asegurar cifrado correcto según puerto
        if ((int)$cfg['port'] === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;      // SSL implícito
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;   // STARTTLS
        }
        $mail->Port = $cfg['port'];

        if (!empty($cfg['debug'])) {
            $mail->SMTPDebug = 2; // verbose debug (no en producción)
        }

        // Remitente
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);

        // Destinatarios
        foreach ((array)$to as $addr) {
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($addr);
            }
        }

        // Reply-To opcional
        if (!empty($cfg['reply_to']) && filter_var($cfg['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($cfg['reply_to']);
        }

        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));

        $mail->send();
        return [true, null];

    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}
