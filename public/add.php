<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$errors = [];
$title = '';
$amount = '';
$type = 'expense';
$categoryId = '';
$txnDate = date('Y-m-d');

// Load categories for the dropdown
$categoriesStmt = $pdo->query('SELECT id, name, type FROM categories ORDER BY type ASC, name ASC');
$categories = $categoriesStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $amountRaw = trim((string) ($_POST['amount'] ?? ''));
    $type = (string) ($_POST['type'] ?? 'expense');
    $categoryIdRaw = trim((string) ($_POST['category_id'] ?? ''));
    $txnDate = trim((string) ($_POST['txn_date'] ?? date('Y-m-d')));

    if ($title === '') {
        $errors[] = 'Please enter a transaction title.';
    } elseif (mb_strlen($title) > 255) {
        $errors[] = 'Title must be 255 characters or fewer.';
    }

    if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw <= 0) {
        $errors[] = 'Please enter a valid positive amount.';
    } else {
        $amount = number_format((float) $amountRaw, 2, '.', '');
    }

    if (!in_array($type, ['income', 'expense'], true)) {
        $errors[] = 'Invalid transaction type.';
        $type = 'expense';
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $txnDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $txnDate) {
        $errors[] = 'Please provide a valid date (YYYY-MM-DD).';
    }

    $categoryId = null;
    if ($categoryIdRaw !== '') {
        if (!ctype_digit($categoryIdRaw)) {
            $errors[] = 'Invalid category selection.';
        } else {
            // Confirm category exists and matches selected type
            $checkCat = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND type = :type LIMIT 1');
            $checkCat->execute([
                ':id'   => (int) $categoryIdRaw,
                ':type' => $type,
            ]);
            $foundCat = $checkCat->fetch();

            if (!$foundCat) {
                $errors[] = 'Selected category does not match the chosen transaction type.';
            } else {
                $categoryId = (int) $foundCat['id'];
            }
        }
    }

    if (empty($errors)) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO transactions (category_id, title, amount, type, txn_date)
             VALUES (:category_id, :title, :amount, :type, :txn_date)'
        );

        $insertStmt->execute([
            ':category_id' => $categoryId,
            ':title'       => $title,
            ':amount'      => $amount,
            ':type'        => $type,
            ':txn_date'    => $txnDate,
        ]);

        header('Location: index.php?added=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Transaction — Budget Tracker</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .field { margin-bottom: 1rem; }
        label { display: block; font-weight: bold; margin-bottom: 0.25rem; }
        input, select { width: 100%; padding: 0.5rem; box-sizing: border-box; }
        .errors { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 0.75rem; margin-bottom: 1rem; }
        .notice { background: #fef9c3; border: 1px solid #eab308; color: #854d0e; padding: 0.75rem; margin-bottom: 1rem; }
        button { padding: 0.6rem 1.2rem; cursor: pointer; }
        .nav { margin-bottom: 1.5rem; }
    </style>
</head>
<body>
<div class="nav">
    <a href="index.php">&larr; Back to Dashboard</a>
</div>

<h1>Add Transaction</h1>

<?php if (empty($categories)): ?>
    <div class="notice">
        No categories found yet. You can still save a transaction without a category, or insert categories in phpMyAdmin/SQL first.
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="errors">
        <strong>Please fix the following:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="add.php">
    <div class="field">
        <label for="type">Type</label>
        <select id="type" name="type" required>
            <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Expense</option>
            <option value="income" <?= $type === 'income' ? 'selected' : '' ?>>Income</option>
        </select>
    </div>

    <div class="field">
        <label for="title">Title</label>
        <input type="text" id="title" name="title" value="<?= htmlspecialchars($title) ?>" required maxlength="255" placeholder="e.g., Grocery shopping or Salary">
    </div>

    <div class="field">
        <label for="amount">Amount</label>
        <input type="number" id="amount" name="amount" value="<?= htmlspecialchars((string) $amount) ?>" step="0.01" min="0.01" required placeholder="0.00">
    </div>

    <div class="field">
        <label for="category_id">Category (optional)</label>
        <select id="category_id" name="category_id">
            <option value="">-- Uncategorized --</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>" <?= (string) $categoryId === (string) $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?> (<?= htmlspecialchars($cat['type']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="txn_date">Date</label>
        <input type="date" id="txn_date" name="txn_date" value="<?= htmlspecialchars($txnDate) ?>" required>
    </div>

    <button type="submit">Save Transaction</button>
</form>
</body>
</html>