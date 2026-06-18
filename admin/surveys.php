<?php
$pageTitle = 'Surveys';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/db.php';

$errors = [];

/* =================== HANDLE POST =================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---- CREATE ---- */
    if ($action === 'create') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive    = isset($_POST['isActive']) ? 1 : 0;

        if (empty($title))          $errors[] = 'Survey title is required.';
        if (strlen($title) > 255)   $errors[] = 'Title too long (max 255 chars).';

        if (empty($errors)) {
            // Duplicate title check
            $chk = $conn->prepare("SELECT surveyId FROM surveys WHERE title = ? AND userId = ?");
            $uid = currentUserId();
            $chk->bind_param('si', $title, $uid);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = 'You already have a survey with that title.';
            $chk->close();
        }

        if (empty($errors)) {
            // Generate a unique share token
            $token = bin2hex(random_bytes(16));
            $uid   = currentUserId();
            $stmt  = $conn->prepare(
                "INSERT INTO surveys (userId, shareToken, title, description, isActive, createdAt)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->bind_param('isssi', $uid, $token, $title, $description, $isActive);
            if ($stmt->execute()) {
                $newId = $stmt->insert_id;
                logAudit($conn, $uid, "CREATE SURVEY: \"$title\" (ID $newId)");
                flashMessage('success', "Survey \"$title\" created. Share token: $token");
                redirect('/admin/surveys.php');
            } else {
                $errors[] = 'Database error: ' . $conn->error;
            }
            $stmt->close();
        }
    }

    /* ---- UPDATE ---- */
    if ($action === 'update') {
        $surveyId    = (int)($_POST['surveyId'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive    = isset($_POST['isActive']) ? 1 : 0;

        if (empty($title))        $errors[] = 'Title is required.';
        if (strlen($title) > 255) $errors[] = 'Title too long.';

        if (empty($errors)) {
            $uid = currentUserId();
            $chk = $conn->prepare("SELECT surveyId FROM surveys WHERE title=? AND userId=? AND surveyId!=?");
            $chk->bind_param('sii', $title, $uid, $surveyId);
            $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = 'Another survey with that title already exists.';
            $chk->close();
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE surveys SET title=?, description=?, isActive=? WHERE surveyId=?");
            $stmt->bind_param('ssii', $title, $description, $isActive, $surveyId);
            if ($stmt->execute()) {
                logAudit($conn, currentUserId(), "UPDATE SURVEY: \"$title\" (ID $surveyId)");
                flashMessage('success', "Survey updated.");
                redirect('/admin/surveys.php');
            } else {
                $errors[] = 'Database error: ' . $conn->error;
            }
            $stmt->close();
        }
    }

    /* ---- DELETE ---- */
    if ($action === 'delete') {
        $surveyId = (int)($_POST['surveyId'] ?? 0);
        $row = $conn->query("SELECT title FROM surveys WHERE surveyId=$surveyId")->fetch_assoc();
        $conn->begin_transaction();
        try {
            // Remove answers then responses then questions then survey
            $conn->query("DELETE ans FROM answers ans
                          JOIN responses res ON ans.responseId=res.responseId
                          WHERE res.surveyId=$surveyId");
            $conn->query("DELETE FROM responses WHERE surveyId=$surveyId");
            $conn->query("DELETE qo FROM question_options qo
                          JOIN questions q ON qo.questionId=q.questionId
                          WHERE q.surveyId=$surveyId");
            $conn->query("DELETE FROM questions WHERE surveyId=$surveyId");
            $conn->query("DELETE FROM surveys WHERE surveyId=$surveyId");
            $conn->commit();
            logAudit($conn, currentUserId(), "DELETE SURVEY: \"{$row['title']}\" (ID $surveyId)");
            flashMessage('success', "Survey deleted.");
        } catch (Exception $e) {
            $conn->rollback();
            flashMessage('error', 'Could not delete survey: ' . $e->getMessage());
        }
        redirect('/admin/surveys.php');
    }
}

/* =================== LIST =================== */
$search  = trim($_GET['search'] ?? '');
$sort    = $_GET['sort'] ?? 'createdAt';
$dir     = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$allowedSorts = ['surveyId','title','userName','createdAt','responseCount'];
if (!in_array($sort, $allowedSorts)) $sort = 'createdAt';

$where  = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where .= ' AND (s.title LIKE ? OR s.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

$countSql = "SELECT COUNT(*) AS c FROM surveys s JOIN user u ON s.userId=u.userId WHERE $where";
$listSql  = "SELECT s.surveyId, s.title, s.description, s.createdAt, s.shareToken,
                    s.isActive,
                    u.userName,
                    (SELECT COUNT(*) FROM responses r WHERE r.surveyId=s.surveyId) AS responseCount,
                    (SELECT COUNT(*) FROM questions q WHERE q.surveyId=s.surveyId) AS questionCount
             FROM surveys s JOIN user u ON s.userId=u.userId
             WHERE $where
             ORDER BY `$sort` $dir
             LIMIT $perPage OFFSET $offset";

$bindAndRun = function(string $sql) use ($conn, $params, $types) {
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
};

$totalRows  = (int)$bindAndRun($countSql)->fetch_assoc()['c'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$surveys    = $bindAndRun($listSql);

$editSurvey = null;
if (isset($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $editSurvey = $conn->query("SELECT * FROM surveys WHERE surveyId=$eId")->fetch_assoc();
}

$qp = ['search'=>$search,'sort'=>$sort,'dir'=>$dir];

function sortLink2(string $col, string $label, string $cs, string $cd, array $qp): string {
    $nd = ($cs===$col && $cd==='ASC') ? 'DESC' : 'ASC';
    $arrow = $cs===$col ? ($cd==='ASC' ? ' asc' : ' desc') : '';
    $qp['sort']=$col; $qp['dir']=$nd; unset($qp['page']);
    return '<a href="?'.http_build_query($qp).'">'.htmlspecialchars($label).$arrow.'</a>';
}
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>Surveys <span style="font-weight:400;color:var(--neutral-500);font-size:13px;">(<?= $totalRows ?> total)</span></h2>
        <button class="btn btn-primary btn-sm" onclick="openModal('modal-create')"><?= appIcon('plus') ?>New Survey</button>
    </div>
    <div class="card-body" style="padding-bottom:14px;">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" class="form-control search-input" placeholder="Search surveys..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-outline">Search</button>
            <a href="<?= appUrl('/admin/surveys.php') ?>" class="btn btn-outline">Reset</a>
        </form>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash flash-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= sortLink2('surveyId','#',$sort,$dir,$qp) ?></th>
                    <th><?= sortLink2('title','Title',$sort,$dir,$qp) ?></th>
                    <th><?= sortLink2('userName','Created By',$sort,$dir,$qp) ?></th>
                    <th>Status</th>
                    <th>Questions</th>
                    <th><?= sortLink2('responseCount','Responses',$sort,$dir,$qp) ?></th>
                    <th><?= sortLink2('createdAt','Created',$sort,$dir,$qp) ?></th>
                    <th>Share Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($surveys->num_rows === 0): ?>
                <tr><td colspan="9">
                    <div class="empty-state">
                        <div class="empty-icon"><?= appIcon('survey') ?></div>
                        <h3>No surveys yet</h3>
                        <p>Create your first survey to get started.</p>
                        <button class="btn btn-primary" onclick="openModal('modal-create')"><?= appIcon('plus') ?>New Survey</button>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php while ($s = $surveys->fetch_assoc()): ?>
                <tr>
                    <td style="color:var(--neutral-500)"><?= $s['surveyId'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($s['title']) ?></strong>
                        <?php if ($s['description']): ?>
                            <div style="font-size:12px;color:var(--neutral-500);margin-top:2px;"><?= htmlspecialchars(mb_substr($s['description'],0,60)) ?>...</div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($s['userName']) ?></td>
                    <td>
                        <span class="badge <?= (int)$s['isActive'] === 1 ? 'badge-active' : 'badge-inactive' ?>">
                            <?= (int)$s['isActive'] === 1 ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td><?= $s['questionCount'] ?></td>
                    <td><?= $s['responseCount'] ?></td>
                    <td style="white-space:nowrap;font-size:12px;color:var(--neutral-500);"><?= date('M j, Y', strtotime($s['createdAt'])) ?></td>
                    <td>
                        <a href="<?= appUrl('/public/take_survey.php?token=' . urlencode($s['shareToken'])) ?>" target="_blank" class="btn btn-outline btn-xs">Open</a>
                    </td>
                    <td>
                        <div class="td-actions">
                            <a href="<?= appUrl('/admin/questions.php?surveyId=' . $s['surveyId']) ?>" class="btn btn-outline btn-xs">Questions</a>
                            <a href="?edit=<?= $s['surveyId'] ?>&<?= http_build_query($qp) ?>" class="btn btn-outline btn-xs">Edit</a>
                            <form id="sdel-<?= $s['surveyId'] ?>" method="POST" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="surveyId" value="<?= $s['surveyId'] ?>">
                            </form>
                            <button class="btn btn-danger btn-xs"
                                onclick="confirmDelete('sdel-<?= $s['surveyId'] ?>','<?= addslashes(htmlspecialchars($s['title'])) ?>')">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div style="padding:14px 20px;border-top:1px solid var(--neutral-200);">
        <div class="pagination">
            <?php for ($p=1;$p<=$totalPages;$p++): ?>
                <?php $pq=array_merge($qp,['page'=>$p]); ?>
                <?php if($p===$page): ?><span class="current"><?=$p?></span>
                <?php else: ?><a href="?<?=http_build_query($pq)?>"><?=$p?></a><?php endif; ?>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay <?= (isset($_POST['action'])&&$_POST['action']==='create'&&$errors)?'active':'' ?>" id="modal-create">
    <div class="modal">
        <div class="modal-header">
            <h3>New Survey</h3>
            <button type="button" class="modal-close" onclick="closeModal('modal-create')" aria-label="Close"><?= appIcon('close') ?></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-control" maxlength="255" required
                           value="<?= htmlspecialchars($_POST['title']??'') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control"><?= htmlspecialchars($_POST['description']??'') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="isActive" class="form-control">
                        <option value="1" <?= (($_POST['isActive'] ?? '1') == '1') ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= (($_POST['isActive'] ?? '') === '0') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-create')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Survey</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<?php if ($editSurvey): ?>
<div class="modal-overlay active" id="modal-edit">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Survey</h3>
            <a href="<?= appUrl('/admin/surveys.php') ?>" class="modal-close" aria-label="Close"><?= appIcon('close') ?></a>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="surveyId" value="<?= $editSurvey['surveyId'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-control" maxlength="255" required
                           value="<?= htmlspecialchars($editSurvey['title']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control"><?= htmlspecialchars($editSurvey['description']) ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="isActive" class="form-control">
                        <option value="1" <?= (int)$editSurvey['isActive'] === 1 ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= (int)$editSurvey['isActive'] === 0 ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="form-hint" style="margin-top:8px;">
                    Share token: <code><?= htmlspecialchars($editSurvey['shareToken']) ?></code>
                </div>
            </div>
            <div class="modal-footer">
                <a href="<?= appUrl('/admin/surveys.php') ?>" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
