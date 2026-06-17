<?php
$pageTitle = 'Audit Trail';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/db.php';

$search  = trim($_GET['search'] ?? '');
$actionF = strtoupper(trim($_GET['action'] ?? ''));
$userIdF = (int)($_GET['userId'] ?? 0);
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$allowedActions = ['LOGIN', 'LOGOUT', 'VIEW', 'OPEN', 'SUBMIT', 'CREATE', 'UPDATE', 'DELETE'];
if (!in_array($actionF, $allowedActions)) {
    $actionF = '';
}

$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= ' AND (a.action LIKE ? OR u.userName LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if ($actionF !== '') {
    $where .= ' AND a.action LIKE ?';
    $params[] = "$actionF%";
    $types .= 's';
}

if ($userIdF > 0) {
    $where .= ' AND a.userId = ?';
    $params[] = $userIdF;
    $types .= 'i';
}

if ($from !== '') {
    $where .= ' AND DATE(a.timestamp) >= ?';
    $params[] = $from;
    $types .= 's';
}

if ($to !== '') {
    $where .= ' AND DATE(a.timestamp) <= ?';
    $params[] = $to;
    $types .= 's';
}

$bindAndRun = function(string $sql) use ($conn, $params, $types) {
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
};

$countSql = "SELECT COUNT(*) AS c
             FROM audit_trail a
             JOIN user u ON a.userId = u.userId
             WHERE $where";
$listSql = "SELECT a.auditId, a.action, a.timestamp, u.userName
            FROM audit_trail a
            JOIN user u ON a.userId = u.userId
            WHERE $where
            ORDER BY a.timestamp DESC, a.auditId DESC
            LIMIT $perPage OFFSET $offset";

$totalRows = (int)$bindAndRun($countSql)->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$audit = $bindAndRun($listSql);
$users = $conn->query("SELECT userId, userName FROM user ORDER BY userName")->fetch_all(MYSQLI_ASSOC);

$qp = [
    'search' => $search,
    'action' => $actionF,
    'userId' => $userIdF,
    'from' => $from,
    'to' => $to,
];
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>Audit Trail <span style="font-weight:400;color:var(--neutral-500);font-size:13px;">(<?= $totalRows ?> total)</span></h2>
    </div>
    <div class="card-body" style="padding-bottom:14px;">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" class="form-control search-input" placeholder="Search user or action..." value="<?= htmlspecialchars($search) ?>">
            <select name="action" class="form-control">
                <option value="">All actions</option>
                <?php foreach ($allowedActions as $action): ?>
                    <option value="<?= $action ?>" <?= $actionF === $action ? 'selected' : '' ?>><?= ucfirst(strtolower($action)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="userId" class="form-control">
                <option value="0">All users</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int)$user['userId'] ?>" <?= $userIdF === (int)$user['userId'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['userName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="filter-field">
                <span>From date</span>
                <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
            </label>
            <label class="filter-field">
                <span>To date</span>
                <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
            </label>
            <button type="submit" class="btn btn-outline">Filter</button>
            <a href="<?= appUrl('/admin/audit.php') ?>" class="btn btn-outline">Reset</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Activity Logs</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($audit && $audit->num_rows): ?>
                <?php while ($row = $audit->fetch_assoc()): ?>
                    <tr>
                        <td><?= (int)$row['auditId'] ?></td>
                        <td><?= htmlspecialchars($row['userName']) ?></td>
                        <td><?= htmlspecialchars($row['action']) ?></td>
                        <td><?= date('M j, Y g:i a', strtotime($row['timestamp'])) ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4"><div class="empty-state"><h3>No audit entries yet</h3><p>User activity will appear here.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $prev = $page - 1;
        $next = $page + 1;
        ?>
        <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qp, ['page' => $prev])) ?>">Prev</a>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => $i])) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qp, ['page' => $next])) ?>">Next</a>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
