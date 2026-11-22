<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$filterType = (string) ($_GET['type'] ?? 'all');
if (!in_array($filterType, ['all', 'income', 'expense'], true)) {
    $filterType = 'all';
}

$addedNotice = isset($_GET['added']) && $_GET['added'] === '1';

// Overall totals across all transactions
$summaryStmt = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
     FROM transactions"
);
$summary = $summaryStmt->fetch();
$totalIncome = (float) ($summary['total_income'] ?? 0);
$totalExpense = (float) ($summary['total_expense'] ?? 0);
$balance = $totalIncome - $totalExpense;

// Filtered transaction list using a prepared statement
if ($filterType === 'all') {
    $txnStmt = $pdo->prepare(
        'SELECT t.id, t.title, t.amount, t.type, t.txn_date, c.name AS category_name
         FROM transactions t
         LEFT JOIN categories c ON t.category_id = c.id
         ORDER BY t.txn_date DESC, t.id DESC'
    );
    $txnStmt->execute();
} else {
    $txnStmt = $pdo->prepare(
        'SELECT t.id, t.title, t.amount, t.type, t.txn_date, c.name AS category_name
         FROM transactions t
         LEFT JOIN categories c ON t.category_id = c.id
         WHERE t.type = :type
         ORDER BY t.txn_date DESC, t.id DESC'
    );
    $txnStmt->execute([':type' => $filterType]);
}

$transactions = $txnStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Tracker</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 820px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .btn { display: inline-block; padding: 0.55rem 1rem; background: #1d4ed8; color: #fff; text-decoration: none; border-radius: 4px; }
        .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .card { border: 1px solid #d1d5db; border-radius: 6px; padding: 1rem; }
        .card h2 { font-size: 0.9rem; color: #4b5563; margin: 0 0 0.35rem; }
        .card .value { font-size: 1.4rem; font-weight: bold; }
        .filters { margin-bottom: 1rem; display: flex; gap: 0.75rem; }
        .filters a { text-decoration: none; padding: 0.3rem 0.65rem; border: 1px solid #d1d5db; border-radius: 4px; color: #111827; }
        .filters a.active { background: #111827; color: #fff; border-color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 0.6rem; text-align: left; }
        .income { color: #15803d; font-weight: bold; }
        .expense { color: #b91c1c; font-weight: bold; }
        .alert { background: #dcfce7; border: 1px solid #22c55e; color: #166534; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; }
    </style>
</head>
<body>
<div class="header">
    <h1>Budget Tracker</h1>
    <a class="btn" href="add.php">+ Add Transaction</a>
</div>

<?php if ($addedNotice): ?>
    <div class="alert">Transaction saved successfully.</div>
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

<div class="filters">
    <a href="index.php?type=all" class="<?= $filterType === 'all' ? 'active' : '' ?>">All</a>
    <a href="index.php?type=income" class="<?= $filterType === 'income' ? 'active' : '' ?>">Income</a>
    <a href="index.php?type=expense" class="<?= $filterType === 'expense' ? 'active' : '' ?>">Expenses</a>
</div>

<table>
    <thead>
    <tr>
        <th>Date</th>
        <th>Title</th>
        <th>Category</th>
        <th>Type</th>
        <th>Amount</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($transactions)): ?>
        <tr>
            <td colspan="5">No transactions found for this filter.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($transactions as $txn): ?>
            <tr>
                <td><?= htmlspecialchars($txn['txn_date']) ?></td>
                <td><?= htmlspecialchars($txn['title']) ?></td>
                <td><?= htmlspecialchars($txn['category_name'] ?? 'Uncategorized') ?></td>
                <td class="<?= htmlspecialchars($txn['type']) ?>"><?= htmlspecialchars(ucfirst($txn['type'])) ?></td>
                <td class="<?= htmlspecialchars($txn['type']) ?>"><?= number_format((float) $txn['amount'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>