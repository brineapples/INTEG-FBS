<?php
$pageTitle = 'User Accounts';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/db.php';

$errors = [];
$success = '';

/* =================== HANDLE POST ACTIONS =================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---- CREATE ---- */
    if ($action === 'create') {
        $userName      = trim($_POST['userName'] ?? '');
        $password      = $_POST['password'] ?? '';
        $roleId        = (int)($_POST['roleId'] ?? 0);
        $accountStatus = $_POST['accountStatus'] ?? 'active';

        if (empty($userName))   $errors[] = 'Username is required.';
        if (strlen($userName) > 100) $errors[] = 'Username too long (max 100 chars).';
        if (empty($password))   $errors[] = 'Password is required.';
        if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
        if (!in_array($roleId, [1, 2])) $errors[] = 'Invalid role.';
        if (!in_array($accountStatus, ['active','inactive'])) $errors[] = 'Invalid status.';

        if (empty($errors)) {
            // Duplicate check
            $chk = $conn->prepare("SELECT userId FROM user WHERE userName = ?");
            $chk->bind_param('s', $userName);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $errors[] = "Username \"$userName\" is already taken.";
            }
            $chk->close();
        }

        if (empty($errors)) {
            $hash = hashPassword($password);
            $stmt = $conn->prepare(
                "INSERT INTO user (roleId, userName, accountStatus, passwordHash) VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param('isss', $roleId, $userName, $accountStatus, $hash);
            if ($stmt->execute()) {
                $newId = $stmt->insert_id;
                logAudit($conn, currentUserId(), "CREATE USER: $userName (ID $newId)");
                flashMessage('success', "User \"$userName\" created successfully.");
                redirect('/admin/users.php');
            } else {
                $errors[] = 'Database error: ' . $conn->error;
            }
            $stmt->close();
        }
    }

    /* ---- UPDATE ---- */
    if ($action === 'update') {
        $userId        = (int)($_POST['userId'] ?? 0);
        $userName      = trim($_POST['userName'] ?? '');
        $roleId        = (int)($_POST['roleId'] ?? 0);
        $accountStatus = $_POST['accountStatus'] ?? 'active';
        $newPassword   = $_POST['newPassword'] ?? '';

        if (empty($userName))  $errors[] = 'Username is required.';
        if (!in_array($roleId, [1, 2])) $errors[] = 'Invalid role.';
        if (!in_array($accountStatus, ['active','inactive'])) $errors[] = 'Invalid status.';
        if (!empty($newPassword) && strlen($newPassword) < 6) $errors[] = 'New password must be at least 6 characters.';

        if (empty($errors)) {
            // Duplicate check (exclude self)
            $chk = $conn->prepare("SELECT userId FROM user WHERE userName = ? AND userId != ?");
            $chk->bind_param('si', $userName, $userId);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = "Username \"$userName\" is already taken.";
            $chk->close();
        }

        if (empty($errors)) {
            if (!empty($newPassword)) {
                $hash = hashPassword($newPassword);
                $stmt = $conn->prepare(
                    "UPDATE user SET roleId=?, userName=?, accountStatus=?, passwordHash=? WHERE userId=?"
                );
                $stmt->bind_param('isssi', $roleId, $userName, $accountStatus, $hash, $userId);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE user SET roleId=?, userName=?, accountStatus=? WHERE userId=?"
                );
                $stmt->bind_param('issi', $roleId, $userName, $accountStatus, $userId);
            }
            if ($stmt->execute()) {
                logAudit($conn, currentUserId(), "UPDATE USER: $userName (ID $userId)");
                flashMessage('success', "User \"$userName\" updated.");
                redirect('/admin/users.php');
            } else {
                $errors[] = 'Database error: ' . $conn->error;
            }
            $stmt->close();
        }
    }

    /* ---- DELETE ---- */
    if ($action === 'delete') {
        $userId = (int)($_POST['userId'] ?? 0);
        if ($userId === currentUserId()) {
            flashMessage('error', 'You cannot delete your own account.');
            redirect('/admin/users.php');
        }
        $nameRow = $conn->query("SELECT userName FROM user WHERE userId=$userId")->fetch_assoc();
        $stmt = $conn->prepare("DELETE FROM user WHERE userId=?");
        $stmt->bind_param('i', $userId);
        if ($stmt->execute()) {
            logAudit($conn, currentUserId(), "DELETE USER: {$nameRow['userName']} (ID $userId)");
            flashMessage('success', "User deleted.");
        }
        $stmt->close();
        redirect('/admin/users.php');
    }
}

/* =================== LIST / SEARCH / FILTER / SORT / PAGINATE =================== */
$search  = trim($_GET['search'] ?? '');
$roleF   = (int)($_GET['role'] ?? 0);
$statusF = $_GET['status'] ?? '';
$sort    = $_GET['sort']  ?? 'userId';
$dir     = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$allowedSorts = ['userId','userName','roleName','accountStatus'];
if (!in_array($sort, $allowedSorts)) $sort = 'userId';

$where = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= ' AND u.userName LIKE ?';
    $params[] = "%$search%";
    $types .= 's';
}
if ($roleF > 0) {
    $where .= ' AND u.roleId = ?';
    $params[] = $roleF;
    $types .= 'i';
}
if ($statusF !== '') {
    $where .= ' AND u.accountStatus = ?';
    $params[] = $statusF;
    $types .= 's';
}

$countSql = "SELECT COUNT(*) AS c FROM user u JOIN roles r ON u.roleId=r.roleId WHERE $where";
$listSql  = "SELECT u.userId, u.userName, u.accountStatus, r.roleName
             FROM user u JOIN roles r ON u.roleId=r.roleId
             WHERE $where
             ORDER BY `$sort` $dir
             LIMIT $perPage OFFSET $offset";

$bindAndRun = function(string $sql) use ($conn, $params, $types) {
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result();
};

$totalRows  = (int)$bindAndRun($countSql)->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$users      = $bindAndRun($listSql);

// For edit modal
$editUser = null;
if (isset($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $r   = $conn->query("SELECT userId,userName,roleId,accountStatus FROM user WHERE userId=$eId");
    $editUser = $r->fetch_assoc();
}

// Roles
$roles = $conn->query("SELECT * FROM roles ORDER BY roleId")->fetch_all(MYSQLI_ASSOC);

function sortLink(string $col, string $label, string $curSort, string $curDir, array $qp): string {
    $newDir = ($curSort === $col && $curDir === 'ASC') ? 'DESC' : 'ASC';
    $arrow  = '';
    if ($curSort === $col) $arrow = $curDir === 'ASC' ? ' asc' : ' desc';
    $qp['sort'] = $col; $qp['dir'] = $newDir; unset($qp['page']);
    return '<a href="?' . http_build_query($qp) . '">' . htmlspecialchars($label) . $arrow . '</a>';
}

$qp = compact('search') + ['role'=>$roleF,'status'=>$statusF,'sort'=>$sort,'dir'=>$dir];
?>

<!-- TOOLBAR -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>User Accounts <span style="font-weight:400;color:var(--neutral-500);font-size:13px;">(<?= $totalRows ?> total)</span></h2>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-create')"><?= appIcon('plus') ?>Add User</button>
    </div>
    <div class="card-body" style="padding-bottom:14px;">
        <form method="GET" action="<?= appUrl('/admin/users.php') ?>" class="filter-bar">
            <input type="text" name="search" class="form-control search-input" placeholder="Search username..." value="<?= htmlspecialchars($search) ?>">
            <select name="role" class="form-control">
                <option value="">All Roles</option>
                <?php foreach ($roles as $rl): ?>
                    <option value="<?= $rl['roleId'] ?>" <?= $roleF==$rl['roleId']?'selected':'' ?>><?= htmlspecialchars($rl['roleName']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="active"   <?= $statusF==='active'  ?'selected':''?>>Active</option>
                <option value="inactive" <?= $statusF==='inactive'?'selected':''?>>Inactive</option>
            </select>
            <button type="submit" class="btn btn-outline">Filter</button>
            <a href="<?= appUrl('/admin/users.php') ?>" class="btn btn-outline">Reset</a>
        </form>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash flash-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<!-- TABLE -->
<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= sortLink('userId',      '#',       $sort,$dir,$qp) ?></th>
                    <th><?= sortLink('userName',    'Username',$sort,$dir,$qp) ?></th>
                    <th><?= sortLink('roleName',    'Role',    $sort,$dir,$qp) ?></th>
                    <th><?= sortLink('accountStatus','Status', $sort,$dir,$qp) ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($users->num_rows === 0): ?>
                <tr><td colspan="5">
                    <div class="empty-state">
                        <div class="empty-icon"><?= appIcon('users') ?></div>
                        <h3>No users found</h3>
                        <p>Try adjusting your filters or add a new user.</p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php while ($u = $users->fetch_assoc()): ?>
                <tr>
                    <td style="color:var(--neutral-500)"><?= $u['userId'] ?></td>
                    <td><strong><?= htmlspecialchars($u['userName']) ?></strong></td>
                    <td>
                        <span class="badge <?= $u['roleName']==='Admin'?'badge-admin':'badge-user' ?>">
                            <?= htmlspecialchars($u['roleName']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $u['accountStatus']==='active'?'badge-active':'badge-inactive' ?>">
                            <?= ucfirst($u['accountStatus']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="td-actions">
                            <a href="?edit=<?= $u['userId'] ?>&<?= http_build_query(compact('search','sort','dir')+['role'=>$roleF,'status'=>$statusF]) ?>" class="btn btn-outline btn-xs">Edit</a>
                            <?php if ($u['userId'] != currentUserId()): ?>
                            <form id="del-<?= $u['userId'] ?>" method="POST" action="<?= appUrl('/admin/users.php') ?>" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="userId" value="<?= $u['userId'] ?>">
                            </form>
                            <button class="btn btn-danger btn-xs"
                                onclick="confirmDelete('del-<?= $u['userId'] ?>','<?= addslashes(htmlspecialchars($u['userName'])) ?>')">
                                Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <?php if ($totalPages > 1): ?>
    <div style="padding:14px 20px;border-top:1px solid var(--neutral-200);">
        <div class="pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php $pQp = array_merge($qp, ['page'=>$p]); ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query($pQp) ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay <?= (isset($_POST['action']) && $_POST['action']==='create' && $errors) ? 'active' : '' ?>" id="modal-create">
    <div class="modal">
        <div class="modal-header">
            <h3>Add New User</h3>
            <button type="button" class="modal-close" onclick="closeModal('modal-create')" aria-label="Close"><?= appIcon('close') ?></button>
        </div>
        <form method="POST" action="<?= appUrl('/admin/users.php') ?>">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input type="text" name="userName" class="form-control" maxlength="100" required
                           value="<?= htmlspecialchars($_POST['userName'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" minlength="6" required>
                    <div class="form-hint">Minimum 6 characters.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select name="roleId" class="form-control" required>
                            <?php foreach ($roles as $rl): ?>
                                <option value="<?= $rl['roleId'] ?>" <?= ($_POST['roleId']??'')==$rl['roleId']?'selected':''?>><?= htmlspecialchars($rl['roleName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="accountStatus" class="form-control" required>
                            <option value="active"   <?= ($_POST['accountStatus']??'active')==='active'  ?'selected':''?>>Active</option>
                            <option value="inactive" <?= ($_POST['accountStatus']??'')==='inactive'?'selected':''?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-create')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<?php if ($editUser): ?>
<div class="modal-overlay active" id="modal-edit">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit User</h3>
            <a href="<?= appUrl('/admin/users.php') ?>" class="modal-close" aria-label="Close"><?= appIcon('close') ?></a>
        </div>
        <form method="POST" action="<?= appUrl('/admin/users.php') ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="userId" value="<?= $editUser['userId'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input type="text" name="userName" class="form-control" maxlength="100" required
                           value="<?= htmlspecialchars($editUser['userName']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="newPassword" class="form-control" minlength="6">
                    <div class="form-hint">Leave blank to keep the current password.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role *</label>
                        <select name="roleId" class="form-control" required>
                            <?php foreach ($roles as $rl): ?>
                                <option value="<?= $rl['roleId'] ?>" <?= $editUser['roleId']==$rl['roleId']?'selected':''?>><?= htmlspecialchars($rl['roleName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="accountStatus" class="form-control" required>
                            <option value="active"   <?= $editUser['accountStatus']==='active'  ?'selected':''?>>Active</option>
                            <option value="inactive" <?= $editUser['accountStatus']==='inactive'?'selected':''?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="<?= appUrl('/admin/users.php') ?>" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
