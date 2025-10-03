<?php
// env.local.php — SOLO configuración, sin funciones
return [
  'smtp' => [
    'HOST' => 'smtp.hostinger.com',
    'PORT' => 465,
    'SECURE' => 'ssl',
    'USER' => 'sistemas@lugaph.site',
    'PASS' => '1Sp2gd3pa*',     // cambia por tu pass real
    'FROM_EMAIL' => 'sistemas@lugaph.site',
    'FROM_NAME'  => 'Sistema Central 2.0',
    'REPLY_TO'   => 'sistemas@lugaph.site',
    'DEBUG' => true,
    'ALLOW_SELF_SIGNED' => false,
    'LANG' => 'es',
    'TIMEOUT' => 15,
  ],
  'uploads' => [
    // Ruta ABSOLUTA en tu máquina local
    'BASE_DIR' => __DIR__ . '/../uploads',
    // URL base pública
    'BASE_URL' => '/uploads',

    'VENTAS' => [
      'IDENTIFICACIONES_DIR' => 'ventas/identificaciones',
      'CONTRATOS_DIR'        => 'ventas/contratos',
      'THUMBS_DIR'           => 'ventas/thumbs',
    ],
    'MAX_SIZE_BYTES' => 5 * 1024 * 1024, // 5 MB
    'ALLOW_PDF_IN_CONTRACT' => true,
  ],
];
