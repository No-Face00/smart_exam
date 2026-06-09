# 🎓 SmartExam
A comprehensive PHP web application for online exam management and academic integrity. SmartExam gives institutions a full-featured platform to create, schedule, and run exams — with a powerful built-in cheating detection engine, real-time live monitoring, rich analytics, and role-based dashboards for admins, teachers, and students.

---

## 🌐 Demo & Access

**Default Admin Login**
- Email: `admin@smartexam.com`
- Password: `Admin@1234`

---

## ✨ Features

### 🏠 Admin Dashboard
- **Live stats** — total students, teachers, exams, active exams, and live students currently taking tests
- **Real-time cheating alert feed** — latest flags with risk level and student info surfaced immediately
- **Recent exam activity** — status, attempts, and pass rates at a glance
- **System activity log** — every login, exam start, and admin action with IP and timestamp
- **Block / unblock** students and teachers directly from management tables

### 📝 Exam Management
- Create exams of three types: **Quiz**, **Midterm**, and **Final**
- Set title, description, total marks, pass marks, duration, and scheduled start/end times
- **Question randomization** — shuffle question order per student to reduce copying
- Assign exams to one or more **sections** per department
- Semester-based access control:
  - Quizzes: section required, semester optional
  - Midterm & Final: both semester AND section required
- Exam statuses: `draft → scheduled → running → completed → cancelled`
- Export results as **CSV or PDF** (admin and teacher access)

### ❓ Question Bank
- Add multiple-choice questions (options A–B–C–D) with per-question mark weighting
- Set correct answer and display order per exam
- Questions cascade-delete when an exam is removed

### 🎓 Student Experience
- **Dashboard** — upcoming and available exams filtered by department, section, and semester
- **Take Exam** — clean timer-based interface with auto-save answers via AJAX on every selection
- Resume mid-exam if disconnected (attempt persists in the database)
- Countdown timer with automatic submission on timeout
- **Results** — score, pass/fail status, time taken, and question-by-question breakdown
- **All Results** — full history of past attempts with scores and time
- **Leaderboard** — department-filtered ranking with average score, best score, and rank badge
- **Notifications** — in-app alert inbox (info, success, warning, danger types)
- **Semester updater** — self-service semester/section update page

### 👩‍🏫 Teacher Dashboard
- Overview of own exams: total, running, completed, total students, pass count, total flags
- **Live monitoring** — see which students are currently in-progress on your exams, with time elapsed
- Manage questions per exam
- View detailed per-exam results with score distribution
- Run cheating detection on own exams and review flag reports

### 🔍 Cheating Detection Engine
SmartExam's most powerful feature — a multi-check statistical engine that runs as a MySQL stored procedure (`sp_detect_cheating`) and surfaces results in a rich investigation UI.

**Detection Checks (7 total):**

| Check | Risk Level | Score | Description |
|---|---|---|---|
| Shared IP Address | High | 85 | Multiple students submitted from the same IP |
| Fast Submission | High / Medium | 60–95 | Completed in less than 25% of allowed time |
| Close Timestamps | Medium | 65 | Two students submitted within 30 seconds of each other |
| Identical Answer Patterns | High | up to 99 | ≥80% answer match across ≥3 questions compared |
| Multiple Logins | Medium | 60 | ≥3 login events from distinct IPs in 60 min before exam |
| Score-Time Anomaly | High | 80 | Top 20% score achieved in bottom 10% of time (statistical outlier) |
| Wrong-Answer Cluster Match | High | 88 | ≥70% of wrong answers identical between two students |

**Risk Escalation:** If a student triggers 3+ distinct flag types, all their flags are automatically escalated to High risk.

**Investigation Tools:**
- Per-exam flag roster with risk level, flag type, and description
- **Side-by-side answer comparison** between any two students (question by question, highlighting matches and shared wrong answers)
- **Similarity matrix** across all submitted students
- **IP network view** — which students shared IPs across which exams
- Actions: Warn, Ban, or Ignore individual flags; bulk-action all flags for a student
- Re-run detection to refresh flags after manual review

### 📊 Analytics (Admin)
- **Score distribution** histogram (buckets of 10%)
- **Exams per month** — 6-month bar chart
- **Pass vs. Fail per department** — grouped comparison
- **Top-performing students** across all exams
- **Cheating heat map** by department and exam type
- Data views: `v_student_risk_profile`, `v_exam_cheat_health`, `v_ip_network`, `v_answer_similarity`

### 🔔 Notification System
- Four severity types: `info`, `warning`, `success`, `danger`
- Scoped to recipient type (admin / teacher / student) and recipient ID
- Unread badge counters in the topbar
- Mark-as-read on open; full inbox page per role

### 🔐 Authentication & Security
- Role-based login: **Admin**, **Teacher**, **Student** — each with separate session checks
- `password_hash()` / `password_verify()` with bcrypt (cost 12)
- **Forgot password** flow with time-limited email token (`password_resets` table)
- Account block/unblock by admin
- Every login, exam start, and sensitive action written to `submission_logs` with IP and user-agent
- Student registration requires matching department, semester, and section

### 🎨 UI / Design System (v3)
- **Font:** Inter (Google Fonts, weights 300–900)
- **Color:** Indigo-first (`#4F46E5`) with semantic success/danger/warning tokens
- **Dark mode** — full `[data-theme="dark"]` CSS variable override, persisted in `localStorage`
- **Glassmorphism topbar** — frosted glass effect with blur overlay
- **Sidebar** — dark `#0F172A` background, collapsible on mobile with overlay
- **Exam cards** — color-coded top border by status
- **Section chip selector** — visual toggle buttons for multi-section assignment
- **Animated stat cards** — fade-in on load, hover lift transitions
- **Multi-layer soft shadows** — five shadow levels (xs → xl + brand glow)
- **Split-screen auth pages** — hero panel with live stats + clean form panel

---

## 🛠 Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | PHP 8.x |
| **Database** | MySQL 8.0+ / MariaDB 10.4+ |
| **Frontend** | HTML5, CSS3, Vanilla JavaScript |
| **CSS Framework** | Bootstrap Icons (icon set only) |
| **Fonts** | Inter via Google Fonts |
| **Query Layer** | PDO with prepared statements |
| **Session** | PHP native sessions |
| **Export** | CSV (native) / PDF (server-rendered) |
| **Charts** | Chart.js (analytics page) |

---

## 📁 Project Structure
smart_exam/
├── index.php                          # Login entry point (role selector)
├── register.php                       # Student self-registration
├── forgot_password.php                # Password reset request
├── reset_password.php                 # Token-based password reset
├── logout.php                         # Session destroy + redirect
├── debug_detect.php                   # Dev tool: manual detection trigger
│
├── config/
│   ├── database.php                   # PDO connection singleton + BASE_URL
│   └── session.php                    # Session start, helper functions
│
├── includes/
│   ├── Auth.php                       # Login, register, logout, password reset, action logging
│   ├── Exam.php                       # Exam CRUD, access control, attempt management, questions
│   ├── CheatingEngine.php             # Stored-procedure caller, flag queries, action apply, similarity matrix
│   ├── Notification.php               # Create, fetch, and mark notifications
│   └── layout.php                     # renderHead(), renderSidebar(), renderTopbar(), flash helpers
│
├── admin/
│   ├── dashboard.php                  # Live stats, recent flags, exam activity, system log
│   ├── exams.php                      # Exam list, create, edit, cancel
│   ├── students.php                   # Student management, block/unblock
│   ├── teachers.php                   # Teacher management, block/unblock
│   ├── cheating.php                   # Global cheating overview across all exams
│   ├── investigate.php                # Per-exam flag roster + action panel
│   ├── compare.php                    # Side-by-side student answer comparison
│   ├── analytics.php                  # Score distribution, monthly charts, dept pass/fail
│   ├── logs.php                       # Full submission_logs browser
│   └── notifications.php             # Admin notification inbox
│
├── teacher/
│   ├── dashboard.php                  # Teacher stats + live attempt monitor
│   ├── exams.php                      # Own exam management
│   ├── questions.php                  # Question CRUD per exam
│   ├── results.php                    # Per-exam results table
│   └── cheating.php                   # Flag review for own exams
│
├── student/
│   ├── dashboard.php                  # Available exams (dept + section + semester filtered)
│   ├── exams.php                      # Full exam list with status badges
│   ├── take_exam.php                  # Timed exam interface with AJAX answer-save
│   ├── result.php                     # Single exam result with question breakdown
│   ├── results.php                    # All past results history
│   ├── leaderboard.php                # Department-filtered rank table
│   ├── notifications.php             # Student notification inbox
│   └── update_semester.php           # Self-service semester/section update
│
├── api/
│   ├── save_answer.php                # AJAX endpoint: save a single answer
│   ├── get_sections.php               # AJAX endpoint: sections by department
│   └── export.php                     # CSV/PDF export for exam results
│
├── sql/
│   ├── schema.sql                     # Full database schema + seed data
│   └── cheating_engine.sql           # Stored procedures + detection views
│
└── assets/
    ├── css/
    │   ├── main.css                   # Design system: variables, layout, components
    │   └── bootstrap-icons.min.css    # Icon font
    └── js/
        └── main.js                    # Theme toggle, sidebar, table filter, timer, AJAX helpers
---

## 🚀 Getting Started

### Prerequisites
- PHP 8.0+
- MySQL 8.0+ or MariaDB 10.4+
- Apache / Nginx with `mod_rewrite` (or PHP built-in server for local dev)
- A web server configured to point to the project root

### Installation

**1. Clone the repository**
```bash
git clone https://github.com/your-username/SmartExam.git
cd SmartExam
```

**2. Import the database**
```bash
# Base schema (tables + seed data)
mysql -u root -p < sql/schema.sql

# Cheating detection stored procedures + views
mysql -u root -p < sql/cheating_engine.sql
```

**3. Configure the database connection**

Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_exam_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', 'http://localhost/smart_exam');
```

**4. Set document root**

Point your web server or virtual host to the `smart_exam/` folder, or run locally:
```bash
php -S localhost:8000
```

**5. Open in browser**
http://localhost:8000

---

## 🔐 Default Credentials

| Role | Email | Password |
|---|---|---|
| Admin | `admin@smartexam.com` | `Admin@1234` |
| Teacher | *(register via admin panel)* | — |
| Student | *(self-register via `/register.php`)* | — |

---

## 🗃️ Database Overview

**Core tables (15):**

| Table | Purpose |
|---|---|
| `departments` | CSE, EEE, BBA, MATH — department registry |
| `semesters` | Spring/Summer/Fall terms with active flag |
| `sections` | A/B/C sections per department |
| `admins` | Admin accounts |
| `teachers` | Teacher accounts with employee code and department |
| `students` | Student accounts linked to dept + semester + section |
| `exams` | Exam definitions with type, schedule, randomization |
| `exam_sections` | Many-to-many: exams ↔ sections |
| `questions` | MCQ questions with 4 options and correct answer |
| `exam_attempts` | Per-student attempt with status, score, time taken |
| `student_answers` | Individual answer selections (auto-saved via AJAX) |
| `cheating_flags` | Detection results with risk score and action taken |
| `notifications` | In-app alerts per recipient |
| `submission_logs` | Full audit log with IP, user-agent, and JSON extra_data |
| `password_resets` | Time-limited reset tokens |

**Database views (4):**

| View | Purpose |
|---|---|
| `v_student_risk_profile` | Per-student cumulative flag summary with `CRITICAL / HIGH / MEDIUM / LOW / CLEAN` rating |
| `v_exam_cheat_health` | Per-exam integrity score, cheat rate %, and flag counts |
| `v_ip_network` | Student pairs sharing IPs across exams |
| `v_answer_similarity` | Pairwise similarity % for every exam |

**Stored procedures (3):**

| Procedure | Purpose |
|---|---|
| `sp_detect_cheating(exam_id)` | Run all 7 detection checks for one exam |
| `sp_detect_all_exams()` | Batch run detection across all completed/running exams |
| `sp_rerun_detection(exam_id)` | Clear existing flags and re-run detection |

---

## 🎨 Design System

All tokens are CSS custom properties in `assets/css/main.css`:

```css
/* Brand */
--primary:    #4F46E5   /* Indigo */
--secondary:  #06B6D4   /* Cyan */
--success:    #10B981
--danger:     #EF4444
--warning:    #F59E0B
--purple:     #8B5CF6

/* Layout */
--sidebar-bg: #0F172A
--bg:         #F1F5F9   /* light mode */
--bg:         #0B1120   /* dark mode */
--sidebar-w:  264px
--topbar-h:   64px

/* Typography */
font-family: 'Inter', sans-serif;   /* weights 300–900 */

/* Radius */
--radius-sm:  8px
--radius:     12px
--radius-lg:  16px
--radius-xl:  20px
```

---

## 🗺️ Roadmap

- [ ] Email delivery for password reset (currently token-only)
- [ ] Essay / short-answer question type
- [ ] Live webcam proctoring integration
- [ ] Teacher registration self-service flow
- [ ] Per-question time analytics
- [ ] Scheduled auto-run of cheating detection after exam completion
- [ ] REST API for mobile client
- [ ] Multi-language support

---

## 👥 Authors

- **No-Face00** — Initial development

---

## 🙏 Acknowledgments

- PHP and MySQL communities
- Bootstrap Icons for the comprehensive icon set
- Inter typeface by Rasmus Andersson
- Chart.js for analytics visualizations
- All contributors and testers

---

## 📞 Support & Contact

For issues, questions, or feature requests:

- Open an issue on GitHub
- Review the existing documentation in `SETUP.md`
- Check PHP and MySQL community forums

---

Happy examining! 🎓✨
