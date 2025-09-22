<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }
if (file_exists(__DIR__ . '/includes/db.php')) {
  require_once __DIR__ . '/includes/db.php';
} else {
  require_once __DIR__ . '/db.php';
}
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Prueba documentos</title></head>
<body>
  <h2>Prueba de subida de documentos</h2>

  <form action="documento_subir.php" method="post" enctype="multipart/form-data">
    <label>Usuario ID:</label>
    <input type="number" name="usuario_id" value="<?= (int)($_SESSION['id_usuario'] ?? 0) ?>" required>
    <br><br>

    <label>Tipo de documento:</label>
    <select name="doc_tipo_id" required>
      <?php
        $r = $conn->query("SELECT id, nombre FROM doc_tipos ORDER BY id");
        while($row = $r->fetch_assoc()){
          echo '<option value="'.$row['id'].'">'.htmlspecialchars($row['nombre']).'</option>';
        }
      ?>
    </select>
    <br><br>

    <input type="file" name="archivo" required>
    <button type="submit">Subir</button>
  </form>
</body>
</html>
