<?php
// env.local.php — NO funciones, solo config
return [
  'smtp' => [
    'HOST' => 'smtp.hostinger.com',
    'PORT' => 465,              // Hostinger: 465 = SSL implícito
    'SECURE' => 'ssl',
    'USER' => 'sistemas@lugaph.site',
    'PASS' => '1Sp2gd3pa*',     // ← cámbiala ya en Hostinger y pon la nueva aquí
    'FROM_EMAIL' => 'sistemas@lugaph.site',  // misma cuenta que autentica
    'FROM_NAME'  => 'Sistema Central 2.0',
    'REPLY_TO'   => 'sistemas@lugaph.site',  // opcional
    'DEBUG' => true,        // true si quieres ver transcript SMTP temporalmente
    'ALLOW_SELF_SIGNED' => false,
    'LANG' => 'es',
    'TIMEOUT' => 15,
  ],
];
