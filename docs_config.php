<?php
// includes/docs_config.php
if (!defined('DOCS_BASE_PATH')) define('DOCS_BASE_PATH', __DIR__ . '/../uploads');
if (!defined('DOCS_MAX_SIZE')) define('DOCS_MAX_SIZE', 10 * 1024 * 1024); // 10 MB
if (!defined('DOCS_ALLOWED_EXT')) define('DOCS_ALLOWED_EXT', ['pdf','jpg','jpeg','png']);
if (!defined('DOCS_ALLOWED_MIME')) define('DOCS_ALLOWED_MIME', ['application/pdf','image/jpeg','image/png']);
