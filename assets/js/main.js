/* SmartExam v3 — Main JavaScript */

// ── Theme ────────────────────────────────────────────────────
function toggleTheme() {
  const html = document.documentElement;
  const isDark = html.getAttribute('data-theme') === 'dark';
  html.setAttribute('data-theme', isDark ? 'light' : 'dark');
  localStorage.setItem('se_theme', isDark ? 'light' : 'dark');
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = isDark ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
}

(function() {
  const t = localStorage.getItem('se_theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
  document.addEventListener('DOMContentLoaded', () => {
    const icon = document.getElementById('themeIcon');
    if (icon) icon.className = t === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
  });
})();

// ── Sidebar ──────────────────────────────────────────────────
function toggleSidebar() {
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('sidebarOverlay');
  if (!sb) return;
  const open = sb.classList.toggle('open');
  if (ov) ov.classList.toggle('open', open);
  document.body.style.overflow = open ? 'hidden' : '';
}

// Auto-close sidebar on resize if wide
window.addEventListener('resize', () => {
  if (window.innerWidth > 768) {
    const sb = document.getElementById('sidebar');
    const ov = document.getElementById('sidebarOverlay');
    if (sb) sb.classList.remove('open');
    if (ov) ov.classList.remove('open');
    document.body.style.overflow = '';
  }
});

// ── Active nav link highlight ────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const path = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-link').forEach(link => {
    if (link.getAttribute('href') && link.getAttribute('href').includes(path) && path !== '') {
      link.classList.add('active');
    }
  });
});

// ── Table filter ─────────────────────────────────────────────
function filterTable(input, tableId) {
  const val = (typeof input === 'string' ? document.getElementById(input)?.value : input.value || '').toLowerCase();
  const rows = document.querySelectorAll('#' + (typeof tableId === 'string' ? tableId : 'examsTable') + ' tbody tr');
  rows.forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
  });
}

// ── Auto-dismiss flash alerts ────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const flashes = document.querySelectorAll('.flash-alert, .flash');
  flashes.forEach(el => {
    if (!el.querySelector('a')) {
      setTimeout(() => {
        el.style.transition = 'opacity .5s, transform .5s';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-8px)';
        setTimeout(() => el.remove(), 500);
      }, 5000);
    }
  });
});

// ── Confirm delete ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });
});

// ── Exam: Countdown Timer ────────────────────────────────────
class ExamTimer {
  constructor(remainingSeconds, onExpire) {
    this.remaining = remainingSeconds;
    this.onExpire  = onExpire;
    this.interval  = null;
    this.display   = document.getElementById('examTimerDisplay');
    this.wrap      = document.getElementById('examTimer');
  }

  start() {
    this._render();
    this.interval = setInterval(() => {
      this.remaining--;
      this._render();
      if (this.remaining <= 0) {
        clearInterval(this.interval);
        if (typeof this.onExpire === 'function') this.onExpire();
      }
    }, 1000);
  }

  _render() {
    if (!this.display) return;
    const m = Math.floor(Math.abs(this.remaining) / 60);
    const s = Math.abs(this.remaining) % 60;
    this.display.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');

    // Colour warnings
    if (this.wrap) {
      this.wrap.classList.remove('timer-warn', 'timer-danger');
      if (this.remaining <= 60)       this.wrap.classList.add('timer-danger');
      else if (this.remaining <= 300) this.wrap.classList.add('timer-warn');
    }
  }
}

// ── Exam: Progress bar & counter ────────────────────────────
function updateProgress() {
  const total     = document.querySelectorAll('.question-card').length;
  const answered  = document.querySelectorAll('.question-card.answered').length;
  const fill      = document.getElementById('progressFill');
  const label     = document.getElementById('progressLabel');
  if (fill)  fill.style.width  = total ? ((answered / total) * 100) + '%' : '0%';
  if (label) label.textContent = answered + ' / ' + total + ' answered';
}

// ── Exam: Submit confirmation ────────────────────────────────
function confirmSubmit(form) {
  const total    = document.querySelectorAll('.question-card').length;
  const answered = document.querySelectorAll('.question-card.answered').length;
  const skipped  = total - answered;
  if (skipped > 0) {
    return confirm(
      'You have ' + skipped + ' unanswered question' + (skipped > 1 ? 's' : '') + '.\n\nSubmit anyway? This cannot be undone.'
    );
  }
  return confirm('Submit your exam? This cannot be undone.');
}

// ── Exam: Auto-save answers via AJAX ────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const options = document.querySelectorAll('.answer-option');
  if (!options.length) return;

  // Debounce map to avoid duplicate concurrent saves
  const saving = {};

  options.forEach(input => {
    input.addEventListener('change', async () => {
      const attemptId  = input.dataset.attemptId;
      const questionId = input.dataset.questionId;
      const option     = input.value;
      const key        = questionId;

      if (saving[key]) return;          // already in-flight
      saving[key] = true;

      // Mark card answered immediately for responsiveness
      const card = input.closest('.question-card');
      if (card) card.classList.add('answered');
      updateProgress();

      try {
        const res = await fetch(
          (window.BASE_URL || '') + '/api/save_answer.php',
          {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ attempt_id: +attemptId, question_id: +questionId, option }),
          }
        );
        if (!res.ok) console.warn('Answer save failed:', res.status);
      } catch (err) {
        console.warn('Answer save error:', err);
      } finally {
        saving[key] = false;
      }
    });
  });
});
