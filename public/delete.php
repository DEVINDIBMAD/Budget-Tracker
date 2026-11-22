<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$idRaw = trim((string) ($_POST['id'] ?? ''));
if ($idRaw === '' || !ctype_digit($idRaw)) {
    header('Location: index.php?error=invalid_id');
    exit;
}

$stmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id');
$stmt->execute([':id' => (int) $idRaw]);

if ($stmt->rowCount() > 0) {
    header('Location: index.php?deleted=1');
} else {
    header('Location: index.php?error=not_found');
}
exit;