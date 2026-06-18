<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brand.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';

requireLogin();

logAudit($conn, currentUserId(), 'VIEW USER DASHBOARD');

$flash = getFlash();
$currentSessionId = session_id();
$search = trim($_GET['search'] ?? '');

$stats = [
    'surveys' => 0,
    'questions' => 0,
    'responses' => 0,
    'myResponses' => 0,
    'mySurveys' => 0,
];

foreach ([
    'surveys' => 'SELECT COUNT(*) AS c FROM surveys WHERE isActive = 1',
    'questions' => 'SELECT COUNT(*) AS c FROM questions',
    'responses' => 'SELECT COUNT(*) AS c FROM responses',
] as $key => $sql) {
    $stats[$key] = (int)($conn->query($sql)->fetch_assoc()['c'] ?? 0);
}

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c,
            COUNT(DISTINCT r.surveyId) AS surveyCount
     FROM responses r
     JOIN respondents rp ON r.respondentId = rp.respondentId
     WHERE rp.sessionId = ?"
);
$stmt->bind_param('s', $currentSessionId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: ['c' => 0, 'surveyCount' => 0];
$stats['myResponses'] = (int)$row['c'];
$stats['mySurveys'] = (int)$row['surveyCount'];
$stmt->close();

$surveyWhere = '';
if ($search !== '') {
    $surveyWhere = 'WHERE s.isActive = 1 AND (s.title LIKE ? OR s.description LIKE ? OR u.userName LIKE ?)';
    $needle = '%' . $search . '%';
} else {
    $surveyWhere = 'WHERE s.isActive = 1';
}

$surveySql = "
    SELECT s.surveyId, s.title, s.description, s.createdAt, s.shareToken, u.userName,
           (SELECT COUNT(*) FROM questions q WHERE q.surveyId = s.surveyId) AS questionCount,
           (SELECT COUNT(*) FROM responses r WHERE r.surveyId = s.surveyId) AS responseCount
    FROM surveys s
    JOIN user u ON s.userId = u.userId
    $surveyWhere
    ORDER BY s.createdAt DESC
    LIMIT 8
";

$surveyStmt = $conn->prepare($surveySql);
if ($search !== '') {
    $surveyStmt->bind_param('sss', $needle, $needle, $needle);
}
$surveyStmt->execute();
$availableSurveys = $surveyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$surveyStmt->close();

$submissionStmt = $conn->prepare(
    "SELECT r.responseId, r.submittedAt, s.title,
            (SELECT COUNT(*) FROM answers a WHERE a.responseId = r.responseId) AS answerCount
     FROM responses r
     JOIN respondents rp ON r.respondentId = rp.respondentId
     JOIN surveys s ON r.surveyId = s.surveyId
     WHERE rp.sessionId = ?
     ORDER BY r.submittedAt DESC
     LIMIT 6"
);
$submissionStmt->bind_param('s', $currentSessionId);
$submissionStmt->execute();
$mySubmissions = $submissionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$submissionStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= appUrl('/assets/style.css') ?>">
</head>
<body>
<div class="admin-shell" style="min-height:100vh;">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <a href="<?= appUrl('/user/dashboard.php') ?>" class="brand-logo-link" aria-label="<?= APP_NAME ?>">
                <?= appLogo('app-logo sidebar-logo') ?>
            </a>
        </div>
        <nav class="sidebar-nav">
            <a href="<?= appUrl('/user/dashboard.php') ?>" class="active"><span class="nav-icon"><?= appIcon('dashboard') ?></span>Dashboard</a>
            <a href="<?= appUrl('/public/take_survey.php') ?>"><span class="nav-icon"><?= appIcon('survey') ?></span>Take Survey</a>
        </nav>
        <div class="sidebar-footer">
            <a href="<?= appUrl('/logout.php') ?>"><span class="nav-icon"><?= appIcon('logout') ?></span>Sign out</a>
        </div>
    </aside>

    <div class="main-wrap">
        <div class="topbar">
            <button type="button" class="mobile-menu-toggle" data-sidebar-toggle aria-label="Toggle navigation">
                <?= appIcon('menu') ?>
            </button>
            <span class="topbar-title">User Dashboard</span>
            <div class="topbar-user">
                <div class="topbar-avatar"><?= strtoupper(substr(currentUserName(), 0, 1)) ?></div>
                <?= currentUserName() ?>
            </div>
        </div>

        <div class="page-content">
            <?php if ($flash): ?>
                <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="stat-grid">
    <div class="stat-card">
                    <div class="stat-label">Available Surveys</div>
                    <div class="stat-value"><?= $stats['surveys'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Questions</div>
                    <div class="stat-value"><?= $stats['questions'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Responses</div>
                    <div class="stat-value"><?= $stats['responses'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">My Submissions</div>
                    <div class="stat-value"><?= $stats['myResponses'] ?></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1.35fr .95fr;gap:20px;align-items:start;">
                <div class="card">
                    <div class="card-header">
                        <h2>Available Surveys</h2>
                        <form method="GET" action="<?= appUrl('/user/dashboard.php') ?>" style="display:flex;gap:10px;align-items:center;">
                            <input
                                type="search"
                                name="search"
                                class="form-control"
                                placeholder="Search surveys"
                                value="<?= htmlspecialchars($search) ?>"
                                style="min-width:220px;"
                            >
                            <button type="submit" class="btn btn-outline btn-sm">Search</button>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Survey</th>
                                    <th>Creator</th>
                                    <th>Questions</th>
                                    <th>Responses</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($availableSurveys): ?>
                                <?php foreach ($availableSurveys as $survey): ?>
                                    <?php
                                        $description = trim((string)($survey['description'] ?? ''));
                                        if ($description === '') {
                                            $descriptionPreview = 'No description provided.';
                                        } elseif (strlen($description) > 90) {
                                            $descriptionPreview = substr($description, 0, 90) . '...';
                                        } else {
                                            $descriptionPreview = $description;
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($survey['title']) ?></strong><br>
                                                <span style="color:var(--neutral-500);font-size:12px;">
                                                    <?= htmlspecialchars($descriptionPreview) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($survey['userName']) ?></td>
                                            <td><?= (int)$survey['questionCount'] ?></td>
                                            <td><?= (int)$survey['responseCount'] ?></td>
                                            <td><span class="badge badge-active">Active</span></td>
                                            <td style="white-space:nowrap;">
                                                <a
                                                    href="<?= appUrl('/public/take_survey.php?token=' . urlencode($survey['shareToken'])) ?>"
                                                    class="btn btn-primary btn-xs"
                                                >
                                                    Open
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <h3>No surveys found</h3>
                                                <p>Try a different search or check back later.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="display:grid;gap:20px;">
                    <div class="card">
                        <div class="card-header">
                            <h2>Quick Actions</h2>
                        </div>
                        <div class="card-body">
                            <p style="margin-bottom:14px;">You have submitted <?= $stats['myResponses'] ?> response(s) across <?= $stats['mySurveys'] ?> survey(s) from this browser session.</p>
                            <div style="display:grid;gap:10px;">
                                <a href="<?= appUrl('/public/take_survey.php') ?>" class="btn btn-primary">Open Public Survey Form</a>
                                <a href="<?= appUrl('/logout.php') ?>" class="btn btn-outline">Sign out</a>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h2>My Recent Submissions</h2>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Survey</th>
                                        <th>Answers</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($mySubmissions): ?>
                                        <?php foreach ($mySubmissions as $submission): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($submission['title']) ?></td>
                                                <td><?= (int)$submission['answerCount'] ?></td>
                                                <td style="white-space:nowrap;color:var(--neutral-500);font-size:12px;">
                                                    <?= date('M j, g:i a', strtotime($submission['submittedAt'])) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">
                                                <div class="empty-state">
                                                    <h3>No submissions yet</h3>
                                                    <p>Your recent survey responses will show up here after you submit one.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= appUrl('/assets/admin.js') ?>"></script>
</body>
</html>
