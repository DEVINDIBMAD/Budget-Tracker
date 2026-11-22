<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$errors = [];
$notice = '';

$selectedMonth = trim((string) ($_GET['month'] ?? $_POST['budget_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $notice = 'Changes saved successfully.';
} elseif (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $notice = 'Item deleted successfully.';
} elseif (isset($_GET['error']) && $_GET['error'] === 'in_use') {
    $errors[] = 'Cannot delete a category that is still used by transactions.';
} elseif (isset($_GET['error']) && $_GET['error'] === 'not_found') {
    $errors[] = 'Requested record was not found.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_category') {
        $catName = trim((string) ($_POST['name'] ?? ''));
        $catType = (string) ($_POST['type'] ?? 'expense');

        if ($catName === '') {
            $errors[] = 'Please enter a category name.';
        } elseif (mb_strlen($catName) > 100) {
            $errors[] = 'Category name must be 100 characters or fewer.';
        }

        if (!in_array($catType, ['income', 'expense'], true)) {
            $errors[] = 'Invalid category type.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('INSERT INTO categories (name, type) VALUES (:name, :type)');
            $stmt->execute([
                ':name' => $catName,
                ':type' => $catType,
            ]);
            header('Location: categories.php?month=' . urlencode($selectedMonth) . '&saved=1');
            exit;
        }
    } elseif ($action === 'delete_category') {
        $catIdRaw = trim((string) ($_POST['category_id'] ?? ''));
        if ($catIdRaw === '' || !ctype_digit($catIdRaw)) {
            $errors[] = 'Invalid category ID.';
        } else {
            $catId = (int) $catIdRaw;

            $checkTxn = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE category_id = :id');
            $checkTxn->execute([':id' => $catId]);
            if ((int) $checkTxn->fetchColumn() > 0) {
                header('Location: categories.php?month=' . urlencode($selectedMonth) . '&error=in_use');
                exit;
            }

            $delStmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
            $delStmt->execute([':id' => $catId]);
            header('Location: categories.php?month=' . urlencode($selectedMonth) . '&deleted=1');
            exit;
        }
    } elseif ($action === 'save_budget') {
        $catIdRaw = trim((string) ($_POST['category_id'] ?? ''));
        $limitRaw = trim((string) ($_POST['limit_amount'] ?? ''));
        $monthRaw = trim((string) ($_POST['budget_month'] ?? $selectedMonth));

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthRaw)) {
            $errors[] = 'Please choose a valid budget month (YYYY-MM).';
        }

        if ($catIdRaw === '' || !ctype_digit($catIdRaw)) {
            $errors[] = 'Please choose an expense category.';
        }

        if ($limitRaw === '' || !is_numeric($limitRaw) || (float) $limitRaw <= 0) {
            $errors[] = 'Please enter a positive monthly budget limit.';
        }

        if (empty($errors)) {
            $catId = (int) $catIdRaw;
            $limitAmount = number_format((float) $limitRaw, 2, '.', '');

            // Ensure category exists and is an expense category
            $catCheck = $pdo->prepare("SELECT id FROM categories WHERE id = :id AND type = 'expense' LIMIT 1");
            $catCheck->execute([':id' => $catId]);
            if (!$catCheck->fetch()) {
                $errors[] = 'Monthly budget limits can only be set for expense categories.';
            } else {
                $upsertStmt = $pdo->prepare(
                    'INSERT INTO monthly_budgets (category_id, budget_month, limit_amount)
                     VALUES (:category_id, :budget_month, :limit_amount)
                     ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)'
                );
                $upsertStmt->execute([
                    ':category_id'  => $catId,
                    ':budget_month' => $monthRaw,
                    ':limit_amount' => $limitAmount,
                ]);
                header('Location: categories.php?month=' . urlencode($monthRaw) . '&saved=1');
                exit;
            }
        }
    } elseif ($action === 'delete_budget') {
        $budgetIdRaw = trim((string) ($_POST['budget_id'] ?? ''));
        if ($budgetIdRaw === '' || !ctype_digit($budgetIdRaw)) {
            $errors[] = 'Invalid budget limit ID.';
        } else {
            $delBudget = $pdo->prepare('DELETE FROM monthly_budgets WHERE id = :id');
            $delBudget->execute([':id' => (int) $budgetIdRaw]);
            header('Location: categories.php?month=' . urlencode($selectedMonth) . '&deleted=1');
            exit;
        }
    }
}

$categories = $pdo->query('SELECT id, name, type FROM categories ORDER BY type ASC, name ASC')->fetchAll();

$expenseCategories = array_values(array_filter(
    $categories,
    static fn(array $row): bool => $row['type'] === 'expense'
));

$startDate = $selectedMonth . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

// Load monthly expense budgets and compare against actual spending for the selected month
$budgetStmt = $pdo->prepare(
    "SELECT
        mb.id AS budget_id,
        mb.category_id,
        mb.budget_month,
        mb.limit_amount,
        c.name AS category_name,
        COALESCE(spent.total_spent, 0) AS actual_spent
     FROM monthly_budgets mb
     INNER JOIN categories c ON mb.category_id = c.id
     LEFT JOIN (
         SELECT category_id, SUM(amount) AS total_spent
         FROM transactions
         WHERE type = 'expense'
           AND txn_date BETWEEN :start_date AND :end_date
         GROUP BY category_id
     ) spent ON spent.category_id = mb.category_id
     WHERE mb.budget_month = :budget_month
     ORDER BY c.name ASC"
);
$budgetStmt->execute([
    ':start_date'   => $startDate,
    ':end_date'     => $endDate,
    ':budget_month' => $selectedMonth,
]);
$budgets = $budgetStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories &amp; Monthly Budgets — Budget Tracker</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 920px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .nav { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .box { border: 1px solid #d1d5db; border-radius: 6px; padding: 1rem; }
        .field { margin-bottom: 0.9rem; }
        label { display: block; font-weight: bold; margin-bottom: 0.25rem; }
        input, select { width: 100%; padding: 0.45rem; box-sizing: border-box; }
        button { padding: 0.5rem 0.9rem; cursor: pointer; }
        .btn-danger { background: #dc2626; color: #fff; border: none; border-radius: 4px; padding: 0.3rem 0.65rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 0.75rem; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 0.55rem; text-align: left; }
        .alert { background: #dcfce7; border: 1px solid #22c55e; color: #166534; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
        .errors { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
        .over { color: #b91c1c; font-weight: bold; }
        .ok { color: #15803d; font-weight: bold; }
    </style>
</head>
<body>
<div class="nav">
    <a href="index.php">&larr; Dashboard</a>
    <a href="add.php">+ Add Transaction</a>
</div>

<h1>Categories &amp; Monthly Budget Limits</h1>

<?php if ($notice !== ''): ?>
    <div class="alert"><?= htmlspecialchars($notice) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="errors">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="box">
        <h2>Add Category</h2>
        <form method="post" action="categories.php?month=<?= urlencode($selectedMonth) ?>">
            <input type="hidden" name="action" value="add_category">
            <input type="hidden" name="budget_month" value="<?= htmlspecialchars($selectedMonth) ?>">

            <div class="field">
                <label for="cat_name">Name</label>
                <input type="text" id="cat_name" name="name" required maxlength="100" placeholder="e.g., Groceries, Salary">
            </div>

            <div class="field">
                <label for="cat_type">Type</label>
                <select id="cat_type" name="type" required>
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                </select>
            </div>

            <button type="submit">Add Category</button>
        </form>
    </div>

    <div class="box">
        <h2>Set Monthly Expense Budget Limit</h2>
        <form method="post" action="categories.php?month=<?= urlencode($selectedMonth) ?>">
            <input type="hidden" name="action" value="save_budget">

            <div class="field">
                <label for="budget_month">Month</label>
                <input type="month" id="budget_month" name="budget_month" value="<?= htmlspecialchars($selectedMonth) ?>" required>
            </div>

            <div class="field">
                <label for="budget_category_id">Expense Category</label>
                <select id="budget_category_id" name="category_id" required>
                    <option value="">-- Select Expense Category --</option>
                    <?php foreach ($expenseCategories as $expCat): ?>
                        <option value="<?= (int) $expCat['id'] ?>"><?= htmlspecialchars($expCat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="limit_amount">Monthly Limit</label>
                <input type="number" id="limit_amount" name="limit_amount" step="0.01" min="0.01" required placeholder="0.00">
            </div>

            <button type="submit">Save Budget Limit</button>
        </form>
    </div>
</div>

<h2>Existing Categories</h2>
<table>
    <thead>
    <tr>
        <th>Name</th>
        <th>Type</th>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($categories)): ?>
        <tr><td colspan="3">No categories yet.</td></tr>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <tr>
                <td><?= htmlspecialchars($cat['name']) ?></td>
                <td><?= htmlspecialchars(ucfirst($cat['type'])) ?></td>
                <td>
                    <form method="post" action="categories.php?month=<?= urlencode($selectedMonth) ?>" style="display:inline;" onsubmit="return confirm('Delete this category?');">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="budget_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                        <input type="hidden" name="category_id" value="<?= (int) $cat['id'] ?>">
                        <button type="submit" class="btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<h2 style="margin-top: 2rem;">Budget Limits vs. Actual Spending (<?= htmlspecialchars($selectedMonth) ?>)</h2>
<form method="get" action="categories.php" style="display:flex; gap:0.5rem; align-items:center; margin-bottom:0.75rem;">
    <label for="view_month">View Month:</label>
    <input type="month" id="view_month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>" style="width:auto;">
    <button type="submit">Load Month</button>
</form>

<table>
    <thead>
    <tr>
        <th>Category</th>
        <th>Budget Limit</th>
        <th>Spent</th>
        <th>Remaining</th>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($budgets)): ?>
        <tr><td colspan="5">No monthly budgets configured for <?= htmlspecialchars($selectedMonth) ?>.</td></tr>
    <?php else: ?>
        <?php foreach ($budgets as $b): ?>
            <?php
            $limitVal = (float) $b['limit_amount'];
            $spentVal = (float) $b['actual_spent'];
            $remaining = $limitVal - $spentVal;
            $isOver = $remaining < 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($b['category_name']) ?></td>
                <td><?= number_format($limitVal, 2) ?></td>
                <td><?= number_format($spentVal, 2) ?></td>
                <td class="<?= $isOver ? 'over' : 'ok' ?>">
                    <?= $isOver ? 'Over by ' . number_format(abs($remaining), 2) : number_format($remaining, 2) ?>
                </td>
                <td>
                    <form method="post" action="categories.php?month=<?= urlencode($selectedMonth) ?>" style="display:inline;" onsubmit="return confirm('Remove this monthly budget limit?');">
                        <input type="hidden" name="action" value="delete_budget">
                        <input type="hidden" name="budget_month" value="<?= htmlspecialchars($selectedMonth) ?>">
                        <input type="hidden" name="budget_id" value="<?= (int) $b['budget_id'] ?>">
                        <button type="submit" class="btn-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>