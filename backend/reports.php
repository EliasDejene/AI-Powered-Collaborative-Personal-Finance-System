<?php
// ============================================================
// reports.php — Financial Reports & Dashboard Data
// Endpoints:
//   GET /reports.php?action=dashboard      — summary stats
//   GET /reports.php?action=monthly&month=4&year=2026
//   GET /reports.php?action=category       — category breakdown
//   GET /reports.php?action=yearly         — yearly income vs expense
// ============================================================
require_once 'config.php';
setJsonHeaders();

$userId = requireAuth();
$action = $_GET['action'] ?? 'dashboard';
$db     = getDB();

// ── DASHBOARD summary ─────────────────────────────────────────
if ($action === 'dashboard') {
    $month = (int) ($_GET['month'] ?? date('m'));
    $year  = (int) ($_GET['year']  ?? date('Y'));

    // Total income this month
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS total
         FROM transactions
         WHERE user_id = ? AND type = 'income'
           AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?"
    );
    $stmt->execute([$userId, $month, $year]);
    $totalIncome = (float) $stmt->fetchColumn();

    // Total expenses this month
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS total
         FROM transactions
         WHERE user_id = ? AND type = 'expense'
           AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?"
    );
    $stmt->execute([$userId, $month, $year]);
    $totalExpenses = (float) $stmt->fetchColumn();

    // Category breakdown for doughnut chart
    $stmt = $db->prepare(
        "SELECT category, SUM(amount) AS total
         FROM transactions
         WHERE user_id = ? AND type = 'expense'
           AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?
         GROUP BY category
         ORDER BY total DESC"
    );
    $stmt->execute([$userId, $month, $year]);
    $categoryBreakdown = $stmt->fetchAll();

    // Spending trend (last 7 months) for line chart
    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(transaction_date, '%b %Y') AS label,
                SUM(amount) AS total
         FROM transactions
         WHERE user_id = ? AND type = 'expense'
           AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 MONTH)
         GROUP BY YEAR(transaction_date), MONTH(transaction_date)
         ORDER BY YEAR(transaction_date), MONTH(transaction_date)"
    );
    $stmt->execute([$userId]);
    $spendingTrend = $stmt->fetchAll();

    // Budget utilisation %
    $budgetAdherence = $totalIncome > 0
        ? round(($totalExpenses / $totalIncome) * 100, 1)
        : 0;

    // Latest 5 transactions
    $stmt = $db->prepare(
        'SELECT * FROM transactions WHERE user_id = ?
         ORDER BY transaction_date DESC LIMIT 5'
    );
    $stmt->execute([$userId]);
    $recentTx = $stmt->fetchAll();

    // AI insight (simple rule-based)
    $aiInsight = 'Keep tracking your expenses to improve your financial health!';
    foreach ($categoryBreakdown as $cat) {
        if ($cat['category'] === 'Entertainment' && $cat['total'] > $totalIncome * 0.10) {
            $aiInsight = 'Your entertainment spending exceeds 10% of your income. Consider reducing subscriptions.';
            break;
        }
        if ($cat['category'] === 'Food & Dining' && $cat['total'] > $totalIncome * 0.25) {
            $aiInsight = 'Food spending is high this month. Try meal planning to cut costs.';
            break;
        }
    }
    if ($totalExpenses > $totalIncome) {
        $aiInsight = 'Warning: You are spending more than you earn this month. Review your budget immediately.';
    }

    respond([
        'month'              => $month,
        'year'               => $year,
        'total_income'       => $totalIncome,
        'total_expenses'     => $totalExpenses,
        'remaining_budget'   => $totalIncome - $totalExpenses,
        'budget_adherence'   => $budgetAdherence,
        'category_breakdown' => $categoryBreakdown,
        'spending_trend'     => $spendingTrend,
        'recent_transactions'=> $recentTx,
        'ai_insight'         => $aiInsight,
    ]);
}

// ── MONTHLY report (for reports.html table) ───────────────────
if ($action === 'monthly') {
    $months = [];
    // Get last 6 months of summary
    for ($i = 5; $i >= 0; $i--) {
        $date  = date('Y-m', strtotime("-$i months"));
        [$y, $m] = explode('-', $date);

        $stmt = $db->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN type='income'  THEN amount END), 0) AS income,
               COALESCE(SUM(CASE WHEN type='expense' THEN amount END), 0) AS expenses
             FROM transactions
             WHERE user_id = ? AND MONTH(transaction_date)=? AND YEAR(transaction_date)=?"
        );
        $stmt->execute([$userId, $m, $y]);
        $row = $stmt->fetch();

        $income   = (float) $row['income'];
        $expenses = (float) $row['expenses'];
        $savings  = $income - $expenses;
        $adherence = $income > 0 ? round(($expenses / $income) * 100, 1) : 0;

        $months[] = [
            'label'           => date('F Y', mktime(0,0,0,$m,1,$y)),
            'month'           => (int)$m,
            'year'            => (int)$y,
            'income'          => $income,
            'expenses'        => $expenses,
            'net_savings'     => $savings,
            'budget_adherence'=> $adherence,
            'status'          => $savings > 0 ? 'Healthy' : 'Overspent',
        ];
    }
    respond(['monthly_reports' => $months]);
}

// ── CATEGORY breakdown ─────────────────────────────────────────
if ($action === 'category') {
    $month = (int) ($_GET['month'] ?? date('m'));
    $year  = (int) ($_GET['year']  ?? date('Y'));

    $stmt = $db->prepare(
        "SELECT category,
                SUM(amount)  AS total,
                COUNT(*)     AS count
         FROM transactions
         WHERE user_id = ? AND type='expense'
           AND MONTH(transaction_date)=? AND YEAR(transaction_date)=?
         GROUP BY category ORDER BY total DESC"
    );
    $stmt->execute([$userId, $month, $year]);
    respond(['categories' => $stmt->fetchAll()]);
}

// ── YEARLY income vs expense trend ────────────────────────────
if ($action === 'yearly') {
    $year = (int) ($_GET['year'] ?? date('Y'));
    $stmt = $db->prepare(
        "SELECT MONTH(transaction_date) AS month,
                MONTHNAME(transaction_date) AS month_name,
                SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expenses
         FROM transactions
         WHERE user_id = ? AND YEAR(transaction_date)=?
         GROUP BY MONTH(transaction_date)
         ORDER BY MONTH(transaction_date)"
    );
    $stmt->execute([$userId, $year]);
    respond(['yearly' => $stmt->fetchAll(), 'year' => $year]);
}

respond(['error' => 'Unknown action.'], 400);
