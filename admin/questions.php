<?php
$pageTitle = 'Questions';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/db.php';

$errors = [];

// Survey filter context
$filterSurveyId = (int)($_GET['surveyId'] ?? 0);

/* =================== HANDLE POST =================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---- CREATE ---- */
    if ($action === 'create') {
        $surveyId     = (int)($_POST['surveyId'] ?? 0);
        $questionText = trim($_POST['question'] ?? '');
        $questionType = $_POST['questionType'] ?? 'text';
        $isRequired   = isset($_POST['isRequired']) ? 1 : 0;
        $options      = $_POST['options'] ?? [];

        if ($surveyId <= 0)          $errors[] = 'Please select a survey.';
        if (empty($questionText))    $errors[] = 'Question text is required.';
        if (strlen($questionText) > 255) $errors[] = 'Question too long (max 255 chars).';
        if (!in_array($questionType, ['text','rating','mcq'])) $errors[] = 'Invalid question type.';
        if ($questionType === 'mcq') {
            $options = array_filter(array_map('trim', $options));
            if (count($options) < 2) $errors[] = 'Multiple choice questions require at least 2 options.';
        }

        if (empty($errors)) {
            // Duplicate check within same survey
            $chk = $conn->prepare("SELECT questionId FROM questions WHERE surveyId=? AND question=?");
            $chk->bind_param('is', $surveyId, $questionText);
            $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = 'This question already exists in that survey.';
            $chk->close();
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO questions (surveyId, question, questionType, isRequired) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('issi', $surveyId, $questionText, $questionType, $isRequired);
            if ($stmt->execute()) {
                $qId = $stmt->insert_id;
                // Insert options for mcq
                if ($questionType === 'mcq' && $options) {
                    $oStmt = $conn->prepare("INSERT INTO question_options (questionId, optionText) VALUES (?, ?)");
                    foreach ($options as $opt) {
                        $oStmt->bind_param('is', $qId, $opt);
                        $oStmt->execute();
                    }
                    $oStmt->close();
                }
                logAudit($conn, currentUserId(), "CREATE QUESTION (ID $qId) in survey $surveyId");
                flashMessage('success', 'Question added successfully.');
                redirect('/admin/questions.php?surveyId=' . $surveyId);
            } else {
                $errors[] = 'Database error: ' . $conn->error;
            }
            $stmt->close();
        }
    }

    /* ---- UPDATE ---- */
    if ($action === 'update') {
        $questionId   = (int)($_POST['questionId'] ?? 0);
        $questionText = trim($_POST['question'] ?? '');
        $options      = $_POST['options'] ?? [];
        $surveyId     = (int)($_POST['surveyId'] ?? 0);
        $isRequired   = isset($_POST['isRequired']) ? 1 : 0;

        if (empty($questionText)) $errors[] = 'Question text is required.';

        if (empty($errors)) {
            // Get question type from DB
            $qRow = $conn->query("SELECT questionType FROM questions WHERE questionId=$questionId")->fetch_assoc();
            $stmt = $conn->prepare("UPDATE questions SET question=?, isRequired=? WHERE questionId=?");
            $stmt->bind_param('sii', $questionText, $isRequired, $questionId);
            if ($stmt->execute()) {
                // Refresh options if mcq
                if ($qRow['questionType'] === 'mcq') {
                    $conn->query("DELETE FROM question_options WHERE questionId=$questionId");
                    $options = array_filter(array_map('trim', $options));
                    if ($options) {
                        $oStmt = $conn->prepare("INSERT INTO question_options (questionId, optionText) VALUES (?, ?)");
                        foreach ($options as $opt) {
                            $oStmt->bind_param('is', $questionId, $opt);
                            $oStmt->execute();
                        }
                        $oStmt->close();
                    }
                }
                logAudit($conn, currentUserId(), "UPDATE QUESTION (ID $questionId)");
                flashMessage('success', 'Question updated.');
                redirect('/admin/questions.php?surveyId=' . $surveyId);
            } else {
                $errors[] = 'Database error: ' . $conn->error;
            }
            $stmt->close();
        }
    }

    /* ---- DELETE ---- */
    if ($action === 'delete') {
        $questionId = (int)($_POST['questionId'] ?? 0);
        $surveyId   = (int)($_POST['surveyId']   ?? 0);
        $conn->query("DELETE FROM answers WHERE questionId=$questionId");
        $conn->query("DELETE FROM question_options WHERE questionId=$questionId");
        $conn->query("DELETE FROM questions WHERE questionId=$questionId");
        logAudit($conn, currentUserId(), "DELETE QUESTION (ID $questionId) from survey $surveyId");
        flashMessage('success', 'Question deleted.');
        redirect('/admin/questions.php?surveyId=' . $surveyId);
    }
}

/* =================== LIST =================== */
$search  = trim($_GET['search'] ?? '');
$typeF   = $_GET['type'] ?? '';
$sort    = $_GET['sort'] ?? 'questionId';
$dir     = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = '1=1';
$params = [];
$types  = '';

if ($filterSurveyId > 0) {
    $where .= ' AND q.surveyId=?';
    $params[] = $filterSurveyId;
    $types .= 'i';
}
if ($search !== '') {
    $where .= ' AND q.question LIKE ?';
    $params[] = "%$search%";
    $types .= 's';
}
if ($typeF !== '') {
    $where .= ' AND q.questionType=?';
    $params[] = $typeF;
    $types .= 's';
}

$allowedSorts = ['questionId','question','questionType','surveyTitle'];
if (!in_array($sort, $allowedSorts)) $sort = 'questionId';

$countSql = "SELECT COUNT(*) AS c FROM questions q
             JOIN surveys s ON q.surveyId=s.surveyId
             WHERE $where";
$listSql  = "SELECT q.questionId, q.question, q.questionType, q.surveyId,
                    q.isRequired,
                    s.title AS surveyTitle,
                    (SELECT COUNT(*) FROM question_options qo WHERE qo.questionId=q.questionId) AS optionCount
             FROM questions q
             JOIN surveys s ON q.surveyId=s.surveyId
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
$questions  = $bindAndRun($listSql);

// Edit question
$editQuestion = null;
$editOptions  = [];
if (isset($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $editQuestion = $conn->query("SELECT * FROM questions WHERE questionId=$eId")->fetch_assoc();
    $optRows = $conn->query("SELECT optionText FROM question_options WHERE questionId=$eId ORDER BY optionsId");
    while ($o = $optRows->fetch_assoc()) $editOptions[] = $o['optionText'];
}

// All surveys for select
$allSurveys = $conn->query("SELECT surveyId, title FROM surveys ORDER BY title")->fetch_all(MYSQLI_ASSOC);

// Current survey name (if filtered)
$currentSurveyName = '';
if ($filterSurveyId > 0) {
    $sRow = $conn->query("SELECT title FROM surveys WHERE surveyId=$filterSurveyId")->fetch_assoc();
    $currentSurveyName = $sRow['title'] ?? '';
}

$qp = ['surveyId'=>$filterSurveyId,'search'=>$search,'type'=>$typeF,'sort'=>$sort,'dir'=>$dir];
$questionColspan = $filterSurveyId ? 6 : 7;

function sortLink3(string $col, string $label, string $cs, string $cd, array $qp): string {
    $nd = ($cs===$col&&$cd==='ASC')?'DESC':'ASC';
    $a = $cs===$col ? ($cd==='ASC' ? ' asc' : ' desc') : '';
    $qp['sort']=$col;$qp['dir']=$nd;unset($qp['page']);
    return '<a href="?'.http_build_query($qp).'">'.htmlspecialchars($label).$a.'</a>';
}
?>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>
            Questions
            <?php if ($currentSurveyName): ?>
                <span style="font-weight:400;color:var(--neutral-500);font-size:13px;">for "<?= htmlspecialchars($currentSurveyName) ?>"</span>
            <?php endif; ?>
            <span style="font-weight:400;color:var(--neutral-500);font-size:13px;">(<?= $totalRows ?> total)</span>
        </h2>
        <div style="display:flex;gap:8px;">
            <?php if ($filterSurveyId): ?>
                <a href="<?= appUrl('/admin/surveys.php') ?>" class="btn btn-outline btn-sm">Back to Surveys</a>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm" onclick="openModal('modal-create')"><?= appIcon('plus') ?>Add Question</button>
        </div>
    </div>
    <div class="card-body" style="padding-bottom:14px;">
        <form method="GET" class="filter-bar">
            <?php if ($filterSurveyId): ?>
                <input type="hidden" name="surveyId" value="<?= $filterSurveyId ?>">
            <?php endif; ?>
            <input type="text" name="search" class="form-control search-input" placeholder="Search questions..." value="<?= htmlspecialchars($search) ?>">
            <?php if (!$filterSurveyId): ?>
            <select name="surveyId" class="form-control">
                <option value="">All Surveys</option>
                <?php foreach ($allSurveys as $sv): ?>
                    <option value="<?= $sv['surveyId'] ?>"><?= htmlspecialchars($sv['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="type" class="form-control">
                <option value="">All Types</option>
                <option value="text"   <?= $typeF==='text'  ?'selected':''?>>Text</option>
                <option value="rating" <?= $typeF==='rating'?'selected':''?>>Rating</option>
                <option value="mcq"    <?= $typeF==='mcq'   ?'selected':''?>>Multiple Choice</option>
            </select>
            <button type="submit" class="btn btn-outline">Filter</button>
            <a href="<?= appUrl('/admin/questions.php' . ($filterSurveyId ? "?surveyId=$filterSurveyId" : '')) ?>" class="btn btn-outline">Reset</a>
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
                    <th><?= sortLink3('questionId','#',$sort,$dir,$qp) ?></th>
                    <th><?= sortLink3('question','Question',$sort,$dir,$qp) ?></th>
                    <th><?= sortLink3('questionType','Type',$sort,$dir,$qp) ?></th>
                    <th>Required</th>
                    <?php if (!$filterSurveyId): ?>
                    <th><?= sortLink3('surveyTitle','Survey',$sort,$dir,$qp) ?></th>
                    <?php endif; ?>
                    <th>Options</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($questions->num_rows === 0): ?>
                <tr><td colspan="<?= $questionColspan ?>">
                    <div class="empty-state">
                        <div class="empty-icon"><?= appIcon('questions') ?></div>
                        <h3>No questions found</h3>
                        <p>Add your first question to this survey.</p>
                        <button class="btn btn-primary" onclick="openModal('modal-create')"><?= appIcon('plus') ?>Add Question</button>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php while ($q = $questions->fetch_assoc()): ?>
                <tr>
                    <td style="color:var(--neutral-500)"><?= $q['questionId'] ?></td>
                    <td><?= htmlspecialchars($q['question']) ?></td>
                    <td>
                        <?php
                        $typeLabel = ['text'=>'Text','rating'=>'Rating (1-5)','mcq'=>'Multiple Choice'];
                        $typeCss   = ['text'=>'badge-user','rating'=>'badge-warning','mcq'=>'badge-admin'];
                        ?>
                        <span class="badge <?= $typeCss[$q['questionType']] ?? 'badge-user' ?>">
                            <?= $typeLabel[$q['questionType']] ?? $q['questionType'] ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= (int)$q['isRequired'] === 1 ? 'badge-active' : 'badge-inactive' ?>">
                            <?= (int)$q['isRequired'] === 1 ? 'Yes' : 'No' ?>
                        </span>
                    </td>
                    <?php if (!$filterSurveyId): ?>
                    <td><?= htmlspecialchars($q['surveyTitle']) ?></td>
                    <?php endif; ?>
                    <td><?= $q['questionType']==='mcq' ? $q['optionCount'].' opts' : '-' ?></td>
                    <td>
                        <div class="td-actions">
                            <a href="?edit=<?= $q['questionId'] ?>&<?= http_build_query($qp) ?>" class="btn btn-outline btn-xs">Edit</a>
                            <form id="qdel-<?= $q['questionId'] ?>" method="POST" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="questionId" value="<?= $q['questionId'] ?>">
                                <input type="hidden" name="surveyId" value="<?= $q['surveyId'] ?>">
                            </form>
                            <button class="btn btn-danger btn-xs"
                                onclick="confirmDelete('qdel-<?= $q['questionId'] ?>','Question #<?= $q['questionId'] ?>')">Delete</button>
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
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3>Add Question</h3>
            <button type="button" class="modal-close" onclick="closeModal('modal-create')" aria-label="Close"><?= appIcon('close') ?></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Survey *</label>
                    <select name="surveyId" class="form-control" required>
                        <option value="">-- select a survey --</option>
                        <?php foreach ($allSurveys as $sv): ?>
                            <option value="<?= $sv['surveyId'] ?>"
                                <?= ($filterSurveyId==$sv['surveyId']||($_POST['surveyId']??'')==$sv['surveyId'])?'selected':'' ?>>
                                <?= htmlspecialchars($sv['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Question Text *</label>
                    <input type="text" name="question" class="form-control" maxlength="255" required
                           value="<?= htmlspecialchars($_POST['question']??'') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Question Type *</label>
                    <select id="questionType" name="questionType" class="form-control" required>
                        <option value="text"   <?= ($_POST['questionType']??'')==='text'  ?'selected':''?>>Text (open-ended)</option>
                        <option value="rating" <?= ($_POST['questionType']??'')==='rating'?'selected':''?>>Rating (1-5)</option>
                        <option value="mcq"    <?= ($_POST['questionType']??'')==='mcq'   ?'selected':''?>>Multiple Choice</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="isRequired" value="1" <?= (($_POST['isRequired'] ?? '1') == '1') ? 'checked' : '' ?>>
                        Required question
                    </label>
                </div>
                <div id="options-block" style="display:none;">
                    <div class="form-group">
                        <label class="form-label">Answer Options</label>
                        <div id="options-container"></div>
                        <button type="button" class="btn btn-outline btn-sm" onclick="addOption()" style="margin-top:8px;"><?= appIcon('plus') ?>Add Option</button>
                        <div class="form-hint">Add at least 2 options for Multiple Choice questions.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-create')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Question</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT MODAL -->
<?php if ($editQuestion): ?>
<div class="modal-overlay active" id="modal-edit">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3>Edit Question</h3>
            <a href="<?= appUrl('/admin/questions.php?' . http_build_query($qp)) ?>" class="modal-close" aria-label="Close"><?= appIcon('close') ?></a>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="questionId" value="<?= $editQuestion['questionId'] ?>">
            <input type="hidden" name="surveyId" value="<?= $editQuestion['surveyId'] ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Question Text *</label>
                    <input type="text" name="question" class="form-control" maxlength="255" required
                           value="<?= htmlspecialchars($editQuestion['question']) ?>">
                </div>
                <div class="form-hint" style="margin-bottom:12px;">
                    Type: <strong><?= ucfirst($editQuestion['questionType']) ?></strong> (cannot be changed after creation)
                </div>
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="isRequired" value="1" <?= (int)($editQuestion['isRequired'] ?? 1) === 1 ? 'checked' : '' ?>>
                        Required question
                    </label>
                </div>
                <?php if ($editQuestion['questionType'] === 'mcq'): ?>
                <div class="form-group">
                    <label class="form-label">Answer Options</label>
                    <div id="options-container">
                        <?php foreach ($editOptions as $opt): ?>
                        <div class="option-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                            <input type="text" name="options[]" class="form-control" value="<?= htmlspecialchars($opt) ?>" required>
                            <button type="button" onclick="this.parentElement.remove()" class="btn btn-danger btn-sm" aria-label="Remove option"><?= appIcon('close') ?></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addOption()" style="margin-top:8px;"><?= appIcon('plus') ?>Add Option</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <a href="<?= appUrl('/admin/questions.php?' . http_build_query($qp)) ?>" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
