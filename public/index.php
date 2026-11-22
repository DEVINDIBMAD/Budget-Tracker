<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$filterType = (string) ($_GET['type'] ?? 'all');
if (!in_array($filterType, ['all', 'income', 'expense'], true)) {
    $filterType = 'all';
}

$filterMonth = trim((string) ($_GET['month'] ?? ''));
if ($filterMonth !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filterMonth)) {
    $filterMonth = '';
}

$filterCategoryRaw = trim((string) ($_GET['category'] ?? 'all'));
$filterCategory = 'all';
if ($filterCategoryRaw === 'uncategorized') {
    $filterCategory = 'uncategorized';
} elseif (ctype_digit($filterCategoryRaw) && (int) $filterCategoryRaw > 0) {
    $filterCategory = (string) (int) $filterCategoryRaw;
}

$addedNotice   = isset($_GET['added']) && $_GET['added'] === '1';
$updatedNotice = isset($_GET['updated']) && $_GET['updated'] === '1';
$deletedNotice = isset($_GET['deleted']) && $_GET['deleted'] === '1';
$errorCode     = (string) ($_GET['error'] ?? '');

$errorMessages = [
        'invalid_id' => 'Invalid transaction ID.',
        'not_found'  => 'Transaction could not be found.',
];
$errorNotice = $errorMessages[$errorCode] ?? null;

$categories = $pdo->query('SELECT id, name, type FROM categories ORDER BY type ASC, name ASC')->fetchAll();

// Build dynamic WHERE clause with bound parameters for summary + transaction list
$whereClauses = [];
$params = [];

if ($filterType !== 'all') {
    $whereClauses[] = 't.type = :type';
    $params[':type'] = $filterType;
}

if ($filterMonth !== '') {
    $startDate = $filterMonth . '-01';
    $endDate   = date('Y-m-t', strtotime($startDate));
    $whereClauses[] = 't.txn_date BETWEEN :start_date AND :end_date';
    $params[':start_date'] = $startDate;
    $params[':end_date']   = $endDate;
}

if ($filterCategory === 'uncategorized') {
    $whereClauses[] = 't.category_id IS NULL';
} elseif ($filterCategory !== 'all') {
    $whereClauses[] = 't.category_id = :category_id';
    $params[':category_id'] = (int) $filterCategory;
}

$whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$summarySql = "SELECT
        COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount ELSE 0 END), 0) AS total_expense
     FROM transactions t
     $whereSql";
$summaryStmt = $pdo->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$totalIncome  = (float) ($summary['total_income'] ?? 0);
$totalExpense = (float) ($summary['total_expense'] ?? 0);
$balance      = $totalIncome - $totalExpense;

$txnSql = "SELECT t.id, t.title, t.amount, t.type, t.txn_date, c.name AS category_name
           FROM transactions t
           LEFT JOIN categories c ON t.category_id = c.id
           $whereSql
           ORDER BY t.txn_date DESC, t.id DESC";
$txnStmt = $pdo->prepare($txnSql);
$txnStmt->execute($params);
$transactions = $txnStmt->fetchAll();

// Monthly budget overview (defaults to current month if no specific month filter is chosen)
$budgetMonth = $filterMonth !== '' ? $filterMonth : date('Y-m');
$bStartDate = $budgetMonth . '-01';
$bEndDate   = date('Y-m-t', strtotime($bStartDate));

$budgetWhere = ['mb.budget_month = :budget_month'];
$budgetParams = [
        ':start_date'   => $bStartDate,
        ':end_date'     => $bEndDate,
        ':budget_month' => $budgetMonth,
];

if ($filterCategory !== 'all' && $filterCategory !== 'uncategorized') {
    $budgetWhere[] = 'mb.category_id = :b_category_id';
    $budgetParams[':b_category_id'] = (int) $filterCategory;
}

$budgetSql = "SELECT
        mb.category_id,
        c.name AS category_name,
        mb.limit_amount,
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
     WHERE " . implode(' AND ', $budgetWhere) . "
     ORDER BY c.name ASC";
$budgetStmt = $pdo->prepare($budgetSql);
$budgetStmt->execute($budgetParams);
$monthlyBudgets = $budgetStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Tracker</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: Arial, sans-serif; max-width: 920px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.75rem; }
        .nav-actions { display: flex; gap: 0.6rem; align-items: center; }
        .btn { display: inline-block; padding: 0.55rem 1rem; background: #1d4ed8; color: #fff; text-decoration: none; border-radius: 4px; }
        .btn-secondary { display: inline-block; padding: 0.55rem 0.9rem; border: 1px solid #d1d5db; color: #111827; text-decoration: none; border-radius: 4px; }
        .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .card { border: 1px solid #d1d5db; border-radius: 6px; padding: 1rem; }
        .card h2 { font-size: 0.9rem; color: #4b5563; margin: 0 0 0.35rem; }
        .card .value { font-size: 1.4rem; font-weight: bold; }
        .filters { margin-bottom: 1.25rem; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; background: #f9fafb; padding: 0.75rem; border-radius: 6px; border: 1px solid #e5e7eb; }
        .filters select, .filters input { padding: 0.35rem 0.5rem; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 0.6rem; text-align: left; }
        .income { color: #15803d; font-weight: bold; }
        .expense { color: #b91c1c; font-weight: bold; }
        .alert { background: #dcfce7; border: 1px solid #22c55e; color: #166534; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
        .alert-error { background: #fee2e2; border: 1px solid #ef4444; color: #991b1b; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
        .actions { display: flex; align-items: center; gap: 0.6rem; }
        .edit-link { color: #1d4ed8; text-decoration: none; font-size: 0.9rem; }
        .delete-btn { background: #dc2626; color: #fff; border: none; padding: 0.28rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.85rem; }
        .progress-track { width: 100%; height: 10px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-top: 0.3rem; }
        .progress-fill { height: 100%; background: #16a34a; }
        .progress-fill.over { background: #dc2626; }
    </style>
</head>
<body>
<div class="header">
    <h1>Budget Tracker</h1>
    <div class="nav-actions">
        <a class="btn-secondary" href="categories.php?month=<?= urlencode($budgetMonth) ?>">Categories &amp; Budgets</a>
        <a class="btn" href="add.php">+ Add Transaction</a>
    </div>
</div>

<?php if ($addedNotice): ?>
    <div class="alert">Transaction saved successfully.</div>
<?php elseif ($updatedNotice): ?>
    <div class="alert">Transaction updated successfully.</div>
<?php elseif ($deletedNotice): ?>
    <div class="alert">Transaction deleted successfully.</div>
<?php endif; ?>

<?php if ($errorNotice !== null): ?>
    <div class="alert-error"><?= htmlspecialchars($errorNotice) ?></div>
<?php endif; ?>

<div class="cards">
    <div class="card">
        <h2>Total Income</h2>
        <div class="value income"><?= number_format($totalIncome, 2) ?></div>
    </div>
    <div class="card">
        <h2>Total Expenses</h2>
        <div class="value expense"><?= number_format($totalExpense, 2) ?></div>
    </div>
    <div class="card">
        <h2>Current Balance</h2>
        <div class="value"><?= number_format($balance, 2) ?></div>
    </div>
</div>

<form method="get" action="index.php" class="filters">
    <label>
        Type:
        <select name="type">
            <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All</option>
            <option value="income" <?= $filterType === 'income' ? 'selected' : '' ?>>Income</option>
            <option value="expense" <?= $filterType === 'expense' ? 'selected' : '' ?>>Expenses</option>
        </select>
    </label>

    <label>
        Month:
        <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>">
    </label>

    <label>
        Category:
        <select name="category">
            <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
            <option value="uncategorized" <?= $filterCategory === 'uncategorized' ? 'selected' : '' ?>>Uncategorized</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>" <?= $filterCategory === (string) $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?> (<?= htmlspecialchars($cat['type']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <button type="submit">Apply Filters</button>
    <a href="index.php">Reset</a>
</form>

<h2>Transactions</h2>
<table>
    <thead>
    <tr>
        <th>Date</th>
        <th>Title</th>
        <th>Category</th>
        <th>Type</th>
        <th>Amount</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($transactions)): ?>
        <tr>
            <td colspan="6">No transactions found for the selected filters.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($transactions as $txn): ?>
            <tr>
                <td><?= htmlspecialchars($txn['txn_date']) ?></td>
                <td><?= htmlspecialchars($txn['title']) ?></td>
                <td><?= htmlspecialchars($txn['category_name'] ?? 'Uncategorized') ?></td>
                <td class="<?= htmlspecialchars($txn['type']) ?>"><?= htmlspecialchars(ucfirst($txn['type'])) ?></td>
                <td class="<?= htmlspecialchars($txn['type']) ?>"><?= number_format((float) $txn['amount'], 2) ?></td>
                <td>
                    <div class="actions">
                        <a class="edit-link" href="edit.php?id=<?= (int) $txn['id'] ?>">Edit</a>
                        <form method="post" action="delete.php" onsubmit="return confirm('Delete this transaction?');">
                            <input type="hidden" name="id" value="<?= (int) $txn['id'] ?>">
                            <button type="submit" class="delete-btn">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<h2>Monthly Category Budgets (<?= htmlspecialchars($budgetMonth) ?>)</h2>
<table>
    <thead>
    <tr>
        <th>Category</th>
        <th>Spent / Limit</th>
        <th>Progress</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($monthlyBudgets)): ?>
        <tr>
            <td colspan="3">No monthly budgets set for <?= htmlspecialchars($budgetMonth) ?>. <a href="categories.php?month=<?= urlencode($budgetMonth) ?>">Set one here</a>.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($monthlyBudgets as $b): ?>
            <?php
            $limitVal = (float) $b['limit_amount'];
            $spentVal = (float) $b['actual_spent'];
            $pct = $limitVal > 0 ? ($spentVal / $limitVal) * 100 : 0;
            $barWidth = min(100, max(0, $pct));
            $isOver = $spentVal > $limitVal;
            ?>
            <tr>
                <td><?= htmlspecialchars($b['category_name']) ?></td>
                <td>
                    <span class="<?= $isOver ? 'expense' : 'income' ?>"><?= number_format($spentVal, 2) ?></span>
                    / <?= number_format($limitVal, 2) ?>
                    (<?= number_format($pct, 1) ?>%)
                </td>
                <td style="width: 35%;">
                    <div class="progress-track">
                        <div class="progress-fill <?= $isOver ? 'over' : '' ?>" style="width: <?= number_format($barWidth, 2, '.', '') ?>%;"></div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>