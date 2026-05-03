# SmartExam v3 — Setup Guide

## What's New in v3
- **Complete UI/UX Redesign** — Inter font, Indigo color system, modern cards
- **Glassmorphism elements** — Frosted glass topbar, blurred overlays
- **Section chip selector** — Visual toggle buttons for selecting sections
- **Exam card design** — Color-coded top borders by status
- **Animated components** — Fade-in stat cards, hover transitions
- **Better auth pages** — Split-screen hero with stats + clean form panel
- **All v2 features** — Semester access control, forgot password, etc.

## Quick Setup

1. Import database:
```bash
mysql -u root -p < sql/schema.sql
```

2. Configure `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_exam_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', 'http://localhost/smart_exam');
```

3. Default admin login:
   - Email: `admin@smartexam.com`
   - Password: `Admin@1234`

## Access Control Rules
- Exams require: Dept match + Section match + Semester match (if set)
- Quizzes: section required, semester optional
- Midterm/Final: both semester AND section required

## Design System
- Primary: `#4F46E5` (Indigo)
- Font: Inter (Google Fonts)
- Border radius: 12-20px cards, 8px inputs/buttons
- Shadows: Multi-layer soft shadows
- All CSS variables in `assets/css/main.css` `:root`
