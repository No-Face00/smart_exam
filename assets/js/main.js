// assets/js/main.js — SmartExam frontend logic

/* ─────────────────────────────────────────
   Sidebar toggle
───────────────────────────────────────── */
function toggleSidebar() {
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('sidebarOverlay');
  if (!sb) return;
  sb.classList.toggle('open');
  ov.classList.toggle('open');
}

/* ─────────────────────────────────────────
   Dark / Light theme
───────────────────────────────────────── */
function toggleTheme() {
  const root = document.documentElement;
  const current = root.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';
  root.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
  updateThemeIcon(next);
}

function updateThemeIcon(theme) {
  const btn = document.querySelector('.theme-toggle i');
  if (!btn) return;
  btn.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
}

(function initTheme() {
  const saved = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  document.addEventListener('DOMContentLoaded', () => updateThemeIcon(saved));
})();

/* ─────────────────────────────────────────
   Exam Timer
───────────────────────────────────────── */
class ExamTimer {
  constructor(durationSecs, onEnd) {
    this.remaining = durationSecs;
    this.onEnd     = onEnd;
    this.el        = document.getElementById('examTimerDisplay');
    this.interval  = null;
  }

  start() {
    this.render();
    this.interval = setInterval(() => {
      this.remaining--;
      this.render();
      if (this.remaining <= 0) {
        clearInterval(this.interval);
        this.onEnd();
      }
    }, 1000);
  }

  render() {
    if (!this.el) return;
    const h = Math.floor(this.remaining / 3600);
    const m = Math.floor((this.remaining % 3600) / 60);
    const s = this.remaining % 60;
    this.el.textContent =
      (h > 0 ? String(h).padStart(2,'0') + ':' : '') +
      String(m).padStart(2,'0') + ':' +
      String(s).padStart(2,'0');

    const wrap = document.getElementById('examTimer');
    if (wrap) {
      wrap.classList.remove('warning','danger');
      if (this.remaining < 120) wrap.classList.add('danger');
      else if (this.remaining < 300) wrap.classList.add('warning');
    }
  }

  stop() { clearInterval(this.interval); }
}

/* ─────────────────────────────────────────
   Auto-save answers (AJAX)
───────────────────────────────────────── */
let saveTimeout = null;

function autoSaveAnswer(attemptId, questionId, option) {
  clearTimeout(saveTimeout);
  saveTimeout = setTimeout(() => {
    fetch(BASE_URL + '/api/save_answer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ attempt_id: attemptId, question_id: questionId, option })
    }).catch(console.error);
  }, 600);
}

/* ─────────────────────────────────────────
   Exam progress tracker
───────────────────────────────────────── */
function updateProgress() {
  const total    = document.querySelectorAll('.question-card').length;
  const answered = document.querySelectorAll('.question-card.answered').length;
  const pct      = total ? Math.round((answered / total) * 100) : 0;
  const fill     = document.getElementById('progressFill');
  const label    = document.getElementById('progressLabel');
  if (fill)  fill.style.width = pct + '%';
  if (label) label.textContent = answered + ' / ' + total + ' answered';
}

document.addEventListener('change', function(e) {
  if (e.target.matches('.answer-option')) {
    const card = e.target.closest('.question-card');
    if (card) {
      card.classList.add('answered');
      updateProgress();
    }
    const attemptId  = e.target.dataset.attemptId;
    const questionId = e.target.dataset.questionId;
    autoSaveAnswer(attemptId, questionId, e.target.value);
  }
});

/* ─────────────────────────────────────────
   Submit confirmation
───────────────────────────────────────── */
function confirmSubmit(form) {
  const total    = document.querySelectorAll('.question-card').length;
  const answered = document.querySelectorAll('.question-card.answered').length;
  const unanswered = total - answered;

  if (unanswered > 0) {
    return confirm(`You have ${unanswered} unanswered question(s).\n\nSubmit anyway?`);
  }
  return confirm('Submit your exam now? This cannot be undone.');
}

/* ─────────────────────────────────────────
   Flash auto-dismiss
───────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  const flash = document.querySelector('.flash-alert');
  if (flash) {
    setTimeout(() => {
      flash.style.opacity = '0';
      flash.style.transition = 'opacity .5s';
      setTimeout(() => flash.remove(), 500);
    }, 4000);
  }

  updateProgress();
});

/* ─────────────────────────────────────────
   Search / filter tables
───────────────────────────────────────── */
function filterTable(inputId, tableId) {
  const val = document.getElementById(inputId).value.toLowerCase();
  const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
  rows.forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
  });
}

/* expose BASE_URL for AJAX — set via inline script on each page */
window.BASE_URL = window.BASE_URL || '';
