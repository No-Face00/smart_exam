# SmartExam System — Setup Guide (XAMPP)

## Requirements
- XAMPP (Apache + MySQL + PHP 8.1+)
- Browser (Chrome/Firefox recommended)

---

## Step 1: Install XAMPP
Download from: https://www.apachefriends.org/
Install and start **Apache** and **MySQL** modules.

---

## Step 2: Copy Project Files
Copy the `smart_exam/` folder into:
```
C:\xampp\htdocs\smart_exam\
```

---

## Step 3: Create the Database

1. Open your browser → `http://localhost/phpmyadmin`
2. Click **New** → create a database named `smart_exam_db`
3. Select `smart_exam_db` → click **Import**
4. Upload `smart_exam/sql/schema.sql`
5. Click **Go** — all tables, views, and stored procedure will be created

---

## Step 4: Configure Database Connection

Edit `smart_exam/config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_exam_db');
define('DB_USER', 'root');      // your MySQL username
define('DB_PASS', '');          // your MySQL password (empty by default)
define('BASE_URL', 'http://localhost/smart_exam');
```

---

## Step 5: Login

Open: `http://localhost/smart_exam/index.php`

**Admin Login:**
- Email: `admin@smartexam.com`
- Password: `Admin@1234`
- Role: Admin

---

## Step 6: Setup Workflow

1. **Admin** → Add departments (already seeded)
2. **Admin** → Add Teachers (Admin > Teachers > Add Teacher)
3. **Teacher** → Login → Create an Exam
4. **Teacher** → Add Questions to the exam
5. **Admin / Teacher** → Add Students
6. **Students** → Register or login → Take exam
7. **After exam** → Admin/Teacher clicks "Run Cheating Detection" for that exam
8. **Admin** → Cheating Flags page → Review & take action

---

## Project Structure

```
smart_exam/
├── config/
│   ├── database.php        # PDO connection singleton
│   └── session.php         # Auth helpers, flash messages
├── includes/
│   ├── Auth.php            # Login / register logic
│   ├── Exam.php            # Exam CRUD + submission + detection
│   └── layout.php          # Shared HTML components
├── admin/
│   ├── dashboard.php       # Admin overview
│   ├── students.php        # Student management
│   ├── teachers.php        # Teacher management
│   ├── exams.php           # Exam oversight
│   ├── cheating.php        # ⭐ Cheating flags review
│   └── logs.php            # System activity logs
├── teacher/
│   ├── dashboard.php
│   ├── exams.php           # Create/manage exams
│   ├── questions.php       # Add MCQ questions
│   └── results.php         # View results + run detection
├── student/
│   ├── dashboard.php
│   ├── take_exam.php       # ⭐ Timed exam with auto-save
│   ├── result.php          # Score + answer review
│   └── results.php         # History
├── api/
│   └── save_answer.php     # AJAX auto-save endpoint
├── assets/
│   ├── css/main.css        # Full design system
│   └── js/main.js          # Timer, auto-save, dark mode
├── sql/
│   └── schema.sql          # ⭐ Complete DB schema + detection SP
└── index.php               # Login page
```

---

## Cheating Detection — How It Works

The stored procedure `sp_detect_cheating(exam_id)` runs 4 SQL checks:

| Check | Trigger | Risk |
|-------|---------|------|
| Shared IP | Same IP used by 2+ students | High (85) |
| Fast Submission | Submitted in < 20% of allowed time | Medium (70) |
| Close Timestamps | Submitted within 30 seconds of another | Medium (65) |
| Identical Answers | ≥ 80% same answers with another student | High (90) |

Call it from Teacher panel after an exam is completed.

---

## Security Notes
- All DB queries use **PDO prepared statements** (SQL injection safe)
- Passwords hashed with **bcrypt** (cost 12)
- Sessions use **HttpOnly + SameSite=Strict** cookies
- All output uses `htmlspecialchars()` (XSS safe)

---

## Phase 3 Additions

### New Files
- `admin/analytics.php` — Charts: score dist, daily activity, pass/fail by dept, flag types, top exams
- `admin/notifications.php` — Send/broadcast notifications with templates
- `admin/exams.php` — Admin exam overview with CSV/PDF export
- `includes/Notification.php` — Notification model (send, broadcast, mark read)
- `teacher/cheating.php` — Teacher cheating report with one-click export
- `api/export.php` — CSV and printable PDF export for any exam
- `student/results.php` — Full results history
- `student/exams.php` — Exam listing with live countdown
- `student/notifications.php` — Student notification inbox

### Export Usage
- **CSV**: `GET /api/export.php?exam_id=N&format=csv`
- **PDF**: `GET /api/export.php?exam_id=N&format=pdf` (opens print dialog)

### Charts (Chart.js 4.4)
- Daily attempt activity (line)
- Score distribution 0–100% in buckets (bar, color-coded red/amber/green)
- Pass/Fail by department (grouped bar)
- Exams created per month (bar)
- Cheating flags by type (doughnut)
