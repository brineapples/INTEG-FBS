<?php
$pageTitle = 'Responses';
require_once __DIR__ . '/../includes/admin_header.php';
require_once __DIR__ . '/../includes/db.php';

$selectedSurveyId = max(0, (int)($_GET['surveyId'] ?? 0));
$selectedSurvey = null;
$questions = [];

$surveys = $conn->query(
    "SELECT s.surveyId, s.title, s.description, s.createdAt,
            COUNT(DISTINCT q.questionId) AS questionCount,
            COUNT(DISTINCT r.responseId) AS responseCount,
            MAX(r.submittedAt) AS lastSubmitted
     FROM surveys s
     LEFT JOIN questions q ON q.surveyId = s.surveyId
     LEFT JOIN responses r ON r.surveyId = s.surveyId
     GROUP BY s.surveyId, s.title, s.description, s.createdAt
     ORDER BY responseCount DESC, lastSubmitted DESC, s.createdAt DESC"
);

if ($selectedSurveyId > 0) {
    $stmt = $conn->prepare(
        "SELECT s.surveyId, s.title, s.description, s.createdAt,
                COUNT(DISTINCT q.questionId) AS questionCount,
                COUNT(DISTINCT r.responseId) AS responseCount,
                COUNT(DISTINCT r.respondentId) AS respondentCount,
                MIN(r.submittedAt) AS firstSubmitted,
                MAX(r.submittedAt) AS lastSubmitted
         FROM surveys s
         LEFT JOIN questions q ON q.surveyId = s.surveyId
         LEFT JOIN responses r ON r.surveyId = s.surveyId
         WHERE s.surveyId = ?
         GROUP BY s.surveyId, s.title, s.description, s.createdAt"
    );
    $stmt->bind_param('i', $selectedSurveyId);
    $stmt->execute();
    $selectedSurvey = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($selectedSurvey) {
        $qStmt = $conn->prepare(
            "SELECT q.questionId, q.question, q.questionType, q.isRequired,
                    COUNT(a.answerId) AS answerCount,
                    AVG(a.ratingValue) AS averageRating
             FROM questions q
             LEFT JOIN answers a ON a.questionId = q.questionId
             LEFT JOIN responses r ON r.responseId = a.responseId AND r.surveyId = q.surveyId
             WHERE q.surveyId = ?
             GROUP BY q.questionId, q.question, q.questionType, q.isRequired
             ORDER BY q.questionId"
        );
        $qStmt->bind_param('i', $selectedSurveyId);
        $qStmt->execute();
        $questions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $qStmt->close();

        foreach ($questions as &$question) {
            $question['options'] = [];
            $question['ratings'] = [];
            $question['textAnswers'] = [];

            if ($question['questionType'] === 'mcq') {
                $optStmt = $conn->prepare(
                    "SELECT qo.optionsId, qo.optionText, COUNT(a.answerId) AS answerCount
                     FROM question_options qo
                     LEFT JOIN answers a ON a.optionsId = qo.optionsId
                     LEFT JOIN responses r ON r.responseId = a.responseId AND r.surveyId = ?
                     WHERE qo.questionId = ?
                     GROUP BY qo.optionsId, qo.optionText
                     ORDER BY qo.optionsId"
                );
                $optStmt->bind_param('ii', $selectedSurveyId, $question['questionId']);
                $optStmt->execute();
                $question['options'] = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $optStmt->close();
            }

            if ($question['questionType'] === 'rating') {
                $ratingStmt = $conn->prepare(
                    "SELECT a.ratingValue, COUNT(a.answerId) AS answerCount
                     FROM answers a
                     JOIN responses r ON r.responseId = a.responseId
                     WHERE a.questionId = ? AND r.surveyId = ? AND a.ratingValue IS NOT NULL
                     GROUP BY a.ratingValue"
                );
                $ratingStmt->bind_param('ii', $question['questionId'], $selectedSurveyId);
                $ratingStmt->execute();
                $ratingRows = $ratingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $ratingStmt->close();

                $ratingCounts = array_fill(1, 5, 0);
                foreach ($ratingRows as $row) {
                    $rating = (int)$row['ratingValue'];
                    if ($rating >= 1 && $rating <= 5) {
                        $ratingCounts[$rating] = (int)$row['answerCount'];
                    }
                }
                $question['ratings'] = $ratingCounts;
            }

            if ($question['questionType'] === 'text') {
                $textStmt = $conn->prepare(
                    "SELECT a.answerText, r.submittedAt, rp.sessionId
                     FROM answers a
                     JOIN responses r ON r.responseId = a.responseId
                     JOIN respondents rp ON rp.respondentId = r.respondentId
                     WHERE a.questionId = ? AND r.surveyId = ? AND a.answerText IS NOT NULL AND TRIM(a.answerText) <> ''
                     ORDER BY r.submittedAt DESC
                     LIMIT 8"
                );
                $textStmt->bind_param('ii', $question['questionId'], $selectedSurveyId);
                $textStmt->execute();
                $question['textAnswers'] = $textStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $textStmt->close();
            }
        }
        unset($question);
    }
}

function responsePercent(int $count, int $total): string {
    if ($total <= 0) {
        return '0%';
    }
    $percent = ($count / $total) * 100;
    return rtrim(rtrim(number_format($percent, 1), '0'), '.') . '%';
}

function responseBarWidth(int $count, int $total): string {
    if ($total <= 0) {
        return '0';
    }
    return (string)min(100, max(0, round(($count / $total) * 100, 1)));
}
?>

<?php if ($selectedSurveyId > 0 && $selectedSurvey): ?>
    <?php
        $totalResponses = (int)$selectedSurvey['responseCount'];
        $answeredQuestions = 0;
        foreach ($questions as $question) {
            if ((int)$question['answerCount'] > 0) {
                $answeredQuestions++;
            }
        }
    ?>
    <div class="responses-heading">
        <div>
            <a href="<?= appUrl('/admin/responses.php') ?>" class="btn btn-outline btn-sm">Back to surveys</a>
            <h2><?= htmlspecialchars($selectedSurvey['title']) ?></h2>
            <?php if (!empty($selectedSurvey['description'])): ?>
                <p><?= htmlspecialchars($selectedSurvey['description']) ?></p>
            <?php endif; ?>
        </div>
        <a href="<?= appUrl('/admin/questions.php?surveyId=' . (int)$selectedSurvey['surveyId']) ?>" class="btn btn-outline">Questions</a>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-label">Responses</div><div class="stat-value"><?= $totalResponses ?></div></div>
        <div class="stat-card"><div class="stat-label">Respondents</div><div class="stat-value"><?= (int)$selectedSurvey['respondentCount'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Questions Answered</div><div class="stat-value"><?= $answeredQuestions ?>/<?= (int)$selectedSurvey['questionCount'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Last Submitted</div><div class="stat-value stat-value-sm"><?= $selectedSurvey['lastSubmitted'] ? date('M j, g:i a', strtotime($selectedSurvey['lastSubmitted'])) : '-' ?></div></div>
    </div>

    <?php if (!$questions): ?>
        <div class="card">
            <div class="empty-state">
                <h3>No questions yet</h3>
                <p>Add questions before response analytics can be shown.</p>
                <a href="<?= appUrl('/admin/questions.php?surveyId=' . (int)$selectedSurvey['surveyId']) ?>" class="btn btn-primary">Add Questions</a>
            </div>
        </div>
    <?php else: ?>
        <div class="analytics-grid">
            <div class="analytics-main">
                <?php foreach ($questions as $index => $question): ?>
                    <?php
                        $answerCount = (int)$question['answerCount'];
                        $skipped = max(0, $totalResponses - $answerCount);
                    ?>
                    <div class="question-analytics card">
                        <div class="question-analytics-header">
                            <div>
                                <div class="question-meta">Question <?= $index + 1 ?> - <?= htmlspecialchars(ucfirst($question['questionType'])) ?></div>
                                <h3><?= htmlspecialchars($question['question']) ?></h3>
                            </div>
                            <div class="question-count"><?= $answerCount ?> answer<?= $answerCount === 1 ? '' : 's' ?></div>
                        </div>

                        <?php if ($question['questionType'] === 'mcq'): ?>
                            <div class="option-chart">
                                <?php foreach ($question['options'] as $option): ?>
                                    <?php
                                        $count = (int)$option['answerCount'];
                                        $width = responseBarWidth($count, $answerCount);
                                    ?>
                                    <div class="chart-row">
                                        <div class="chart-label"><?= htmlspecialchars($option['optionText']) ?></div>
                                        <div class="chart-track"><div class="chart-fill" style="width: <?= $width ?>%;"></div></div>
                                        <div class="chart-number"><?= $count ?> <span><?= responsePercent($count, $answerCount) ?></span></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$question['options']): ?>
                                    <div class="empty-inline">No answer options are configured for this question.</div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($question['questionType'] === 'rating'): ?>
                            <div class="rating-summary">
                                <div>
                                    <span class="rating-average"><?= $question['averageRating'] ? number_format((float)$question['averageRating'], 1) : '-' ?></span>
                                    <span class="rating-caption">average rating</span>
                                </div>
                            </div>
                            <div class="rating-chart">
                                <?php foreach ($question['ratings'] as $rating => $count): ?>
                                    <div class="rating-column">
                                        <div class="rating-bar-wrap"><div class="rating-bar" style="height: <?= responseBarWidth((int)$count, $answerCount) ?>%;"></div></div>
                                        <div class="rating-label"><?= $rating ?></div>
                                        <div class="rating-count"><?= (int)$count ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-answer-list">
                                <?php if ($question['textAnswers']): ?>
                                    <?php foreach ($question['textAnswers'] as $answer): ?>
                                        <div class="text-answer">
                                            <p><?= nl2br(htmlspecialchars($answer['answerText'])) ?></p>
                                            <span><?= htmlspecialchars($answer['sessionId']) ?> - <?= date('M j, Y g:i a', strtotime($answer['submittedAt'])) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-inline">No written responses for this question yet.</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($skipped > 0): ?>
                            <div class="question-footnote"><?= $skipped ?> response<?= $skipped === 1 ? '' : 's' ?> skipped this question.</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    <?php endif; ?>
<?php else: ?>
    <?php if ($selectedSurveyId > 0): ?>
        <div class="flash flash-error">Survey not found.</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2>Responses</h2>
        </div>
        <div class="card-body responses-intro">
            <h3>Select a survey to view analytics</h3>
            <p>Open a survey to review response counts, answer percentages, rating distributions, and written feedback.</p>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Survey</th>
                        <th>Questions</th>
                        <th>Responses</th>
                        <th>Last Submitted</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($surveys && $surveys->num_rows): ?>
                    <?php while ($survey = $surveys->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($survey['title']) ?></strong>
                                <?php if (!empty($survey['description'])): ?>
                                    <div style="font-size:12px;color:var(--neutral-500);margin-top:2px;"><?= htmlspecialchars(mb_substr($survey['description'], 0, 80)) ?><?= mb_strlen($survey['description']) > 80 ? '...' : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$survey['questionCount'] ?></td>
                            <td><?= (int)$survey['responseCount'] ?></td>
                            <td><?= $survey['lastSubmitted'] ? date('M j, Y g:i a', strtotime($survey['lastSubmitted'])) : '-' ?></td>
                            <td><?= date('M j, Y', strtotime($survey['createdAt'])) ?></td>
                            <td><a href="<?= appUrl('/admin/responses.php?surveyId=' . (int)$survey['surveyId']) ?>" class="btn btn-primary btn-sm">View analytics</a></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6"><div class="empty-state"><h3>No surveys yet</h3><p>Create a survey before response analytics can be shown.</p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
