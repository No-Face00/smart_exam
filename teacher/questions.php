<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/Exam.php';

requireLogin('teacher');

$db        = Database::getConnection();
$tid       = currentUser()['id'];
$examModel = new Exam();
$examId    = (int)get('exam_id');

// Verify ownership
$exam = null;
if ($examId) {
    $stmt = $db->prepare("SELECT * FROM exams WHERE exam_id = ? AND teacher_id = ?");
    $stmt->execute([$examId, $tid]);
    $exam = $stmt->fetch();
}

if (!$exam) {
    setFlash('danger', 'Exam not found or access denied.');
    header('Location: exams.php'); exit;
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_question'])) {
        $opts = ['A','B','C','D'];
        $correct = strtoupper(post('correct_option'));
        $errors = [];
        if (!post('question_text')) $errors[] = 'Question text required.';
        if (!post('option_a') || !post('option_b') || !post('option_c') || !post('option_d'))
            $errors[] = 'All four options required.';
        if (!in_array($correct, $opts)) $errors[] = 'Select a correct answer.';

        if (!$errors) {
            $examModel->addQuestion([
                'exam_id'        => $examId,
                'question_text'  => post('question_text'),
                'option_a'       => post('option_a'),
                'option_b'       => post('option_b'),
                'option_c'       => post('option_c'),
                'option_d'       => post('option_d'),
                'correct_option' => $correct,
                'marks'          => max(1,(int)post('marks',1)),
                'display_order'  => (int)post('display_order',0),
            ]);
            setFlash('success', 'Question added!');
        } else {
            setFlash('danger', implode(' ', $errors));
        }
        header('Location: questions.php?exam_id=' . $examId); exit;
    }

    if (isset($_POST['delete_question'])) {
        $qid = (int)$_POST['question_id'];
        // Make sure question belongs to this exam
        $db->prepare("DELETE FROM questions WHERE question_id = ? AND exam_id = ?")
           ->execute([$qid, $examId]);
        setFlash('success', 'Question removed.');
        header('Location: questions.php?exam_id=' . $examId); exit;
    }
}

$questions = $examModel->getQuestions($examId);
$flash     = getFlash();
$qCount    = count($questions);

renderHead('Questions — ' . $exam['title']);
?>
<script>window.BASE_URL='<?= BASE_URL ?>';</script>
<div class="layout">
<?php renderSidebar('teacher','Questions'); ?>

<div class="main-content">
<?php renderTopbar('Question Bank'); ?>

<?php if ($flash): ?>
<div class="flash-alert flash-<?= $flash['type'] ?>"><?= sanitize($flash['msg']) ?></div>
<?php endif; ?>

<!-- Exam Context Banner -->
<div style="background:var(--brand);border-radius:var(--radius);padding:16px 22px;margin-bottom:24px;
            color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <div style="font-size:12px;opacity:.7;margin-bottom:2px;">Managing questions for:</div>
    <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;"><?= sanitize($exam['title']) ?></div>
  </div>
  <div style="display:flex;gap:20px;text-align:center;">
    <div><div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;"><?= $qCount ?></div><div style="font-size:11px;opacity:.7;">Questions</div></div>
    <div><div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;"><?= $exam['duration_mins'] ?>m</div><div style="font-size:11px;opacity:.7;">Duration</div></div>
    <div><div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;"><?= $exam['total_marks'] ?></div><div style="font-size:11px;opacity:.7;">Total Marks</div></div>
  </div>
  <a href="exams.php" class="btn-ghost" style="color:#fff;border-color:rgba(255,255,255,.4);padding:8px 16px;font-size:13px;">
    <i class="bi bi-arrow-left"></i> Back to Exams
  </a>
</div>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start;">

  <!-- Question List -->
  <div class="card">
    <div class="card-header">
      <h3><i class="bi bi-list-ol" style="color:var(--brand)"></i> Questions (<?= $qCount ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
      <?php if ($questions): ?>
      <?php foreach ($questions as $i => $q): ?>
      <div style="padding:18px 20px;border-bottom:1px solid var(--border);" id="q<?= $q['question_id'] ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
              <span style="background:var(--brand);color:#fff;width:26px;height:26px;border-radius:50%;
                           display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;">
                <?= $i+1 ?>
              </span>
              <span style="font-weight:600;font-size:15px;"><?= sanitize($q['question_text']) ?></span>
              <span style="font-size:11px;background:var(--bg);padding:2px 8px;border-radius:6px;color:var(--text-muted);">
                <?= $q['marks'] ?> mark<?= $q['marks']>1?'s':'' ?>
              </span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
              <?php foreach (['A','B','C','D'] as $opt): ?>
              <?php $optKey = 'option_' . strtolower($opt); ?>
              <div style="padding:8px 12px;border-radius:8px;font-size:13px;
                          <?= $q['correct_option']===$opt
                              ? 'background:#ECFDF5;border:1.5px solid var(--success);font-weight:600;color:#065F46;'
                              : 'background:var(--bg);border:1.5px solid var(--border);' ?>">
                <strong><?= $opt ?>.</strong> <?= sanitize($q[$optKey]) ?>
                <?= $q['correct_option']===$opt ? ' <i class="bi bi-check-circle-fill" style="float:right;color:var(--success);"></i>' : '' ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <form method="POST" onsubmit="return confirm('Delete this question?')">
            <input type="hidden" name="question_id" value="<?= $q['question_id'] ?>">
            <input type="hidden" name="delete_question" value="1">
            <button class="btn-primary btn-sm btn-danger" title="Delete question">
              <i class="bi bi-trash"></i>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="text-align:center;padding:48px;color:var(--text-muted);">
        <i class="bi bi-question-circle" style="font-size:40px;display:block;margin-bottom:12px;"></i>
        No questions yet. Add your first question using the form →
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Add Question Form -->
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header">
      <h3><i class="bi bi-plus-circle" style="color:var(--success)"></i> Add Question</h3>
    </div>
    <div class="card-body">
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Question Text *</label>
          <textarea name="question_text" class="form-control" rows="3"
                    placeholder="Enter the question…" required></textarea>
        </div>

        <?php foreach (['A','B','C','D'] as $opt): ?>
        <div class="form-group">
          <label class="form-label">Option <?= $opt ?> *</label>
          <input type="text" name="option_<?= strtolower($opt) ?>" class="form-control"
                 placeholder="Option <?= $opt ?>" required>
        </div>
        <?php endforeach; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group">
            <label class="form-label">Correct Answer *</label>
            <select name="correct_option" class="form-control" required>
              <option value="">— Select —</option>
              <option value="A">A</option>
              <option value="B">B</option>
              <option value="C">C</option>
              <option value="D">D</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Marks</label>
            <input type="number" name="marks" class="form-control" value="1" min="1" max="100">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Display Order</label>
          <input type="number" name="display_order" class="form-control"
                 value="<?= $qCount + 1 ?>" min="0">
        </div>

        <button type="submit" name="add_question" class="btn-primary" style="width:100%;justify-content:center;padding:12px;">
          <i class="bi bi-plus-lg"></i> Add Question
        </button>
      </form>
    </div>
  </div>

</div>
</div>
</div>
<?php renderFooter(); ?>
