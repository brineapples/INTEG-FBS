<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/brand.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/icons.php';

$token = trim($_GET['token'] ?? '');
$survey = null;
$questions = [];
$browseSurveys = [];

if ($token !== '') {
    $stmt = $conn->prepare("SELECT surveyId, title, description, isActive FROM surveys WHERE shareToken = ? LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $survey = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($survey && (int)$survey['isActive'] !== 1) {
        $survey = null;
    } elseif ($survey && isLoggedIn()) {
        logAudit($conn, currentUserId(), 'OPEN SURVEY: ' . $survey['title']);
    }
}

if ($survey) {
    $stmt = $conn->prepare("SELECT questionId, question, questionType, isRequired FROM questions WHERE surveyId = ? ORDER BY questionId");
    $stmt->bind_param('i', $survey['surveyId']);
    $stmt->execute();
    $questionRows = $stmt->get_result();
    while ($question = $questionRows->fetch_assoc()) {
        $question['options'] = [];
        if ($question['questionType'] === 'mcq') {
            $optStmt = $conn->prepare("SELECT optionsId, optionText FROM question_options WHERE questionId = ? ORDER BY optionsId");
            $optStmt->bind_param('i', $question['questionId']);
            $optStmt->execute();
            $question['options'] = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $optStmt->close();
        }
        $questions[] = $question;
    }
    $stmt->close();
}

if ($token === '') {
    if (isLoggedIn()) {
        logAudit($conn, currentUserId(), 'VIEW SURVEY BROWSER');
    }

    $browseStmt = $conn->prepare(
        "SELECT s.surveyId, s.title, s.description, s.createdAt, s.shareToken, u.userName,
                s.isActive,
                (SELECT COUNT(*) FROM questions q WHERE q.surveyId = s.surveyId) AS questionCount,
                (SELECT COUNT(*) FROM responses r WHERE r.surveyId = s.surveyId) AS responseCount
         FROM surveys s
         JOIN user u ON s.userId = u.userId
         WHERE s.isActive = 1
         ORDER BY s.createdAt DESC
         LIMIT 12"
    );
    $browseStmt->execute();
    $browseSurveys = $browseStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $browseStmt->close();
}

$submitted = false;
$error = '';
$postedAnswers = $_POST['answers'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $survey) {
    if (empty($questions)) {
        $error = 'This survey has no questions yet.';
    } else {
        try {
            $preparedAnswers = [];
            foreach ($questions as $question) {
                $questionId = (int)$question['questionId'];
                $isRequired = (int)($question['isRequired'] ?? 1) === 1;

                if ($question['questionType'] !== 'mcq') {
                    if ($question['questionType'] === 'rating') {
                        $rawRating = trim((string)($postedAnswers[$questionId] ?? ''));
                        if ($rawRating === '') {
                            if ($isRequired) {
                                throw new Exception('Please rate every required rating question from 1 to 5.');
                            }
                            continue;
                        }
                        $rating = (int)$rawRating;
                        if ($rating < 1 || $rating > 5) {
                            throw new Exception('Rating questions must be answered from 1 to 5.');
                        }
                        $preparedAnswers[] = [
                            'questionId' => $questionId,
                            'type' => 'rating',
                            'ratingValue' => $rating,
                        ];
                    } elseif ($question['questionType'] === 'text') {
                        $answerText = trim((string)($postedAnswers[$questionId] ?? ''));
                        if ($answerText === '') {
                            if ($isRequired) {
                                throw new Exception('Please answer every required text question.');
                            }
                            continue;
                        }
                        $preparedAnswers[] = [
                            'questionId' => $questionId,
                            'type' => 'text',
                            'answerText' => $answerText,
                        ];
                    } else {
                        throw new Exception('Unsupported question type found in this survey.');
                    }
                    continue;
                }

                $optionId = (int)($postedAnswers[$questionId] ?? 0);
                if ($optionId <= 0) {
                    if ($isRequired) {
                        throw new Exception('Please answer every required multiple choice question.');
                    }
                    continue;
                }
                $validOptionIds = array_map(
                    static fn(array $option): int => (int)$option['optionsId'],
                    $question['options']
                );
                if (!in_array($optionId, $validOptionIds, true)) {
                    throw new Exception('Please answer all multiple choice questions.');
                }
                $preparedAnswers[] = [
                    'questionId' => $questionId,
                    'type' => 'mcq',
                    'optionsId' => $optionId,
                ];
            }

            $conn->begin_transaction();

            $sessionId = session_id();
            $stmt = $conn->prepare("INSERT IGNORE INTO respondents (sessionId) VALUES (?)");
            $stmt->bind_param('s', $sessionId);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("SELECT respondentId FROM respondents WHERE sessionId = ? LIMIT 1");
            $stmt->bind_param('s', $sessionId);
            $stmt->execute();
            $respondent = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO responses (surveyId, respondentId, submittedAt) VALUES (?, ?, NOW())");
            $stmt->bind_param('ii', $survey['surveyId'], $respondent['respondentId']);
            $stmt->execute();
            $responseId = $stmt->insert_id;
            $stmt->close();

            $mcqStmt = $conn->prepare("INSERT INTO answers (responseId, questionId, optionsId) VALUES (?, ?, ?)");
            $ratingStmt = $conn->prepare("INSERT INTO answers (responseId, questionId, ratingValue) VALUES (?, ?, ?)");
            $textStmt = $conn->prepare("INSERT INTO answers (responseId, questionId, answerText) VALUES (?, ?, ?)");

            foreach ($preparedAnswers as $answer) {
                if ($answer['type'] === 'mcq') {
                    $mcqStmt->bind_param('iii', $responseId, $answer['questionId'], $answer['optionsId']);
                    $mcqStmt->execute();
                } elseif ($answer['type'] === 'rating') {
                    $ratingStmt->bind_param('iii', $responseId, $answer['questionId'], $answer['ratingValue']);
                    $ratingStmt->execute();
                } else {
                    $text = $answer['answerText'];
                    $textStmt->bind_param('iis', $responseId, $answer['questionId'], $text);
                    $textStmt->execute();
                }
            }

            $mcqStmt->close();
            $ratingStmt->close();
            $textStmt->close();
            $conn->commit();
            if (isLoggedIn()) {
                logAudit($conn, currentUserId(), 'SUBMIT SURVEY: ' . $survey['title'] . " (Response ID $responseId)");
            }
            $submitted = true;
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $survey ? htmlspecialchars($survey['title']) . ' - ' . APP_NAME : 'Survey Browser - ' . APP_NAME ?></title>
    <link rel="stylesheet" href="<?= appUrl('/assets/style.css') ?>">
</head>
<body>
<div class="survey-page">
    <div class="survey-header">
        <div class="survey-brand"><?= appLogo('app-logo survey-logo-img') ?></div>
        <h1>
            <?php if ($survey): ?>
                <?= htmlspecialchars($survey['title']) ?>
            <?php elseif ($token === ''): ?>
                Browse Available Surveys
            <?php else: ?>
                Survey not found
            <?php endif; ?>
        </h1>
        <?php if ($survey && $survey['description']): ?>
            <p><?= htmlspecialchars($survey['description']) ?></p>
        <?php elseif ($token === ''): ?>
            <p>Select a survey below to open its shared link.</p>
        <?php endif; ?>
    </div>
    <div class="survey-body">
        <?php if (!$survey && $token === ''): ?>
            <div style="display:flex;justify-content:flex-start;margin-bottom:18px;">
                <a href="<?= isLoggedIn() ? appUrl('/user/dashboard.php') : appUrl('/login.php') ?>" class="btn btn-outline">
                    <?= appIcon('chevron-left') ?>Back
                </a>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Survey</th>
                                    <th>Creator</th>
                                    <th>Questions</th>
                                    <th>Responses</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($browseSurveys): ?>
                                    <?php foreach ($browseSurveys as $row): ?>
                                        <?php
                                            $description = trim((string)($row['description'] ?? ''));
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
                                                <strong><?= htmlspecialchars($row['title']) ?></strong><br>
                                                <span style="color:var(--neutral-500);font-size:12px;">
                                                    <?= htmlspecialchars($descriptionPreview) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($row['userName']) ?></td>
                                            <td><?= (int)$row['questionCount'] ?></td>
                                            <td><?= (int)$row['responseCount'] ?></td>
                                            <td style="white-space:nowrap;">
                                                <a href="<?= appUrl('/public/take_survey.php?token=' . urlencode($row['shareToken'])) ?>" class="btn btn-primary btn-xs"><?= appIcon('survey') ?>Open</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5">
                                            <div class="empty-state">
                                                <h3>No surveys available</h3>
                                                <p>Check back later for new surveys.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php elseif (!$survey): ?>
            <div style="display:flex;justify-content:flex-start;margin-bottom:18px;">
                <a href="<?= appUrl('/public/take_survey.php') ?>" class="btn btn-outline">
                    <?= appIcon('chevron-left') ?>Back to Surveys
                </a>
            </div>
            <div class="card"><div class="card-body">This survey link is invalid or no longer available. <a href="<?= appUrl('/public/take_survey.php') ?>">Browse surveys</a>.</div></div>
        <?php elseif ($submitted): ?>
            <div class="survey-success">
                <div class="check-icon"><?= appIcon('check') ?></div>
                <h2>Thank you</h2>
                <p>Your response has been submitted.</p>
                <div style="margin-top:20px;">
                    <a href="<?= appUrl('/public/take_survey.php') ?>" class="btn btn-outline"><?= appIcon('chevron-left') ?>Back to Surveys</a>
                </div>
            </div>
        <?php else: ?>
            <?php if ($error): ?><div class="flash flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div style="display:flex;justify-content:flex-start;margin-bottom:18px;">
                <a href="<?= appUrl('/public/take_survey.php') ?>" class="btn btn-outline">
                    <?= appIcon('chevron-left') ?>Back to Surveys
                </a>
            </div>
            <form method="POST">
                <?php foreach ($questions as $index => $question): ?>
                    <div class="question-card">
                        <div class="q-num">Question <?= $index + 1 ?></div>
                        <div class="q-text"><?= htmlspecialchars($question['question']) ?></div>
                        <div class="form-hint" style="margin-bottom:12px;">
                            <?= (int)($question['isRequired'] ?? 1) === 1 ? 'Required' : 'Optional' ?>
                        </div>
                        <?php if ($question['questionType'] === 'mcq'): ?>
                            <ul class="options-list">
                                <?php foreach ($question['options'] as $option): ?>
                                    <?php $checked = ((int)($postedAnswers[$question['questionId']] ?? 0) === (int)$option['optionsId']); ?>
                                    <li class="option-item">
                                        <label>
                                            <input type="radio" name="answers[<?= (int)$question['questionId'] ?>]" value="<?= (int)$option['optionsId'] ?>" <?= $checked ? 'checked' : '' ?> <?= (int)($question['isRequired'] ?? 1) === 1 ? 'required' : '' ?>>
                                            <span><?= htmlspecialchars($option['optionText']) ?></span>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif ($question['questionType'] === 'rating'): ?>
                            <div class="form-group">
                                <label class="form-label" for="q<?= (int)$question['questionId'] ?>">Rate from 1 to 5</label>
                                <select
                                    id="q<?= (int)$question['questionId'] ?>"
                                    name="answers[<?= (int)$question['questionId'] ?>]"
                                    class="form-control"
                                    <?= (int)($question['isRequired'] ?? 1) === 1 ? 'required' : '' ?>
                                >
                                    <option value="">Select a rating</option>
                                    <?php for ($rating = 1; $rating <= 5; $rating++): ?>
                                        <option value="<?= $rating ?>" <?= ((int)($postedAnswers[$question['questionId']] ?? 0) === $rating) ? 'selected' : '' ?>>
                                            <?= $rating ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label class="form-label" for="q<?= (int)$question['questionId'] ?>">Your answer</label>
                                <textarea
                                    id="q<?= (int)$question['questionId'] ?>"
                                    name="answers[<?= (int)$question['questionId'] ?>]"
                                    class="form-control"
                                    rows="4"
                                    <?= (int)($question['isRequired'] ?? 1) === 1 ? 'required' : '' ?>
                                ><?= htmlspecialchars((string)($postedAnswers[$question['questionId']] ?? '')) ?></textarea>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="survey-submit-bar">
                    <span><?= count($questions) ?> question(s)</span>
                    <button type="submit" class="btn btn-primary"><?= appIcon('check') ?>Submit Response</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
