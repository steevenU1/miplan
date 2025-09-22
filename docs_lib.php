<?php
// includes/docs_lib.php
// ---------------------------------------------------------
// Librería backend para documentos del expediente
// - Validación y guardado con versionado (sin triggers)
// - Helpers de permisos, listado y descarga segura
// ---------------------------------------------------------

require_once __DIR__ . '/docs_config.php';

// Carga db.php (soporta proyecto con db.php en /includes o en raíz)
if (file_exists(__DIR__ . '/db.php')) {
  require_once __DIR__ . '/db.php';
} else {
  require_once __DIR__ . '/../db.php';
}

/* =========================
   HELPERS GENERALES
========================= */

function sanitize_filename(string $name): string {
  $name = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $name);
  return substr($name, 0, 120);
}

function random_slug(int $len = 8): string {
  return bin2hex(random_bytes(max(4, min(32, $len))));
}

function ensure_upload_dir(int $usuario_id, string $codigo): string {
  $dir = rtrim(DOCS_BASE_PATH, '/\\') . "/usuarios/{$usuario_id}/{$codigo}";
  if (!is_dir($dir)) { mkdir($dir, 0775, true); }
  return $dir;
}

/**
 * Validar archivo subido (tamaño, MIME y extensión).
 * Retorna [bool ok, string|null error]
 */
function validate_upload(array $file): array {
  if (!isset($file['error']) || is_array($file['error'])) return [false, 'Carga inválida'];
  if ($file['error'] !== UPLOAD_ERR_OK) return [false, 'Error al subir archivo'];
  if ($file['size'] > DOCS_MAX_SIZE) return [false, 'Archivo supera el tamaño permitido'];

  // MIME real del archivo
  $mime = mime_content_type($file['tmp_name']);
  if (!in_array($mime, DOCS_ALLOWED_MIME, true)) return [false, 'Tipo de archivo no permitido'];

  // Extensión
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, DOCS_ALLOWED_EXT, true)) return [false, 'Extensión no permitida'];

  return [true, null];
}

/* =========================
   PERMISOS / CATÁLOGOS
========================= */

function doc_tipo_id_by_codigo(mysqli $conn, string $codigo): ?int {
  $stmt = $conn->prepare("SELECT id FROM doc_tipos WHERE codigo=?");
  $stmt->bind_param('s', $codigo);
  $stmt->execute();
  $stmt->bind_result($id);
  $res = $stmt->fetch();
  $stmt->close();
  return $res ? (int)$id : null;
}

/**
 * true si el rol tiene acceso a ver/descargar este tipo
 */
function user_can_view_doc_type(mysqli $conn, string $rol, int $doc_tipo_id): bool {
  $stmt = $conn->prepare("SELECT 1 FROM doc_tipo_roles WHERE doc_tipo_id=? AND rol=?");
  $stmt->bind_param('is', $doc_tipo_id, $rol);
  $stmt->execute();
  $ok = $stmt->get_result()->num_rows > 0;
  $stmt->close();
  return $ok;
}

/* =========================
   GUARDADO CON VERSIONADO
========================= */

/**
 * Guarda documento de usuario con versionado en transacción (sin triggers).
 * - Calcula siguiente versión con SELECT ... FOR UPDATE
 * - Desmarca vigente anterior y marca vigente la nueva
 * - Mueve el archivo al disco sólo si la BD se guardó
 *
 * Retorna [bool ok, string|null error]
 */
function save_user_doc(mysqli $conn, int $usuario_id, int $doc_tipo_id, int $subido_por, array $file): array {
  [$ok, $err] = validate_upload($file);
  if (!$ok) return [false, $err];

  // Obtener código del tipo (para carpeta)
  $stmt = $conn->prepare("SELECT codigo FROM doc_tipos WHERE id=?");
  $stmt->bind_param('i', $doc_tipo_id);
  $stmt->execute();
  $codigo = ($stmt->get_result()->fetch_assoc()['codigo'] ?? null);
  $stmt->close();
  if (!$codigo) return [false, 'Tipo de documento inválido'];

  $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $mime = mime_content_type($file['tmp_name']);
  $hash = hash_file('sha256', $file['tmp_name']);
  $orig = sanitize_filename($file['name']);

  // Prepara directorio destino (aún no movemos el archivo)
  $dir = ensure_upload_dir($usuario_id, $codigo);

  try {
    $conn->begin_transaction();

    // Bloquea el par usuario/tipo para calcular versión segura
    $stmt = $conn->prepare("
      SELECT IFNULL(MAX(version),0) AS maxv
      FROM usuario_documentos
      WHERE usuario_id=? AND doc_tipo_id=?
      FOR UPDATE
    ");
    $stmt->bind_param('ii', $usuario_id, $doc_tipo_id);
    $stmt->execute();
    $maxv = (int)($stmt->get_result()->fetch_assoc()['maxv'] ?? 0);
    $stmt->close();

    $nextv = $maxv + 1;

    // Desmarcar vigente anterior (si existe)
    $stmt = $conn->prepare("
      UPDATE usuario_documentos
         SET vigente=0
       WHERE usuario_id=? AND doc_tipo_id=? AND vigente=1
    ");
    $stmt->bind_param('ii', $usuario_id, $doc_tipo_id);
    $stmt->execute();
    $stmt->close();

    // Definir nombre final y ruta relativa
    $fname    = "v{$nextv}_" . random_slug(4) . "." . $ext;
    $dest     = rtrim($dir, '/\\') . "/" . $fname;
    $ruta_rel = "usuarios/{$usuario_id}/{$codigo}/{$fname}";
    $tamano   = (int)filesize($file['tmp_name']);

    // Inserta nuevo registro vigente
    $stmt = $conn->prepare("
      INSERT INTO usuario_documentos
        (usuario_id, doc_tipo_id, version, ruta, nombre_original, mime, tamano, hash_sha256, vigente, subido_por)
      VALUES (?,?,?,?,?,?,?, ?,1,?)
    ");
    // tipos: i i i s s s i s i
    $stmt->bind_param('iiisssisi',
      $usuario_id, $doc_tipo_id, $nextv, $ruta_rel, $orig, $mime, $tamano, $hash, $subido_por
    );
    $stmt->execute();
    $stmt->close();

    // Mover archivo físico
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      $conn->rollback();
      return [false, 'No se pudo guardar el archivo en disco'];
    }

    $conn->commit();
    return [true, null];

  } catch (Throwable $e) {
    if ($conn->errno) { $conn->rollback(); }
    return [false, 'Error al registrar documento: ' . $e->getMessage()];
  }
}

/* =========================
   LISTADOS / CONSULTAS
========================= */

/**
 * Devuelve todos los tipos y el estado para un usuario.
 * Cada item: id, codigo, nombre, requerido, doc_id_vigente, version(max)
 */
function list_doc_types_with_status(mysqli $conn, int $usuario_id): array {
  $sql = "SELECT t.id,t.codigo,t.nombre,t.requerido,
                 (SELECT id FROM usuario_documentos ud 
                   WHERE ud.usuario_id=? AND ud.doc_tipo_id=t.id AND ud.vigente=1 LIMIT 1) AS doc_id_vigente,
                 (SELECT MAX(version) FROM usuario_documentos ud 
                   WHERE ud.usuario_id=? AND ud.doc_tipo_id=t.id) AS version
          FROM doc_tipos t ORDER BY t.id";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ii', $usuario_id, $usuario_id);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $res;
}

/**
 * Obtiene un registro de documento por id (con nombre del tipo y código)
 */
function get_doc_record(mysqli $conn, int $doc_id): ?array {
  $stmt = $conn->prepare("SELECT ud.*, t.codigo, t.nombre AS tipo_nombre
                          FROM usuario_documentos ud
                          JOIN doc_tipos t ON t.id = ud.doc_tipo_id
                          WHERE ud.id=?");
  $stmt->bind_param('i', $doc_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

/* =========================
   DESCARGA / VISUALIZACIÓN
========================= */

function output_file_secure(string $abs_path, string $mime, string $download_name, bool $inline = true) {
  if (!is_file($abs_path)) {
    http_response_code(404); echo "No encontrado"; exit;
  }
  header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
  header('Content-Length: ' . filesize($abs_path));
  $disp = $inline ? 'inline' : 'attachment';
  header('Content-Disposition: ' . $disp . '; filename="' . basename($download_name ?: 'documento') . '"');
  readfile($abs_path);
  exit;
}
