Quick Setup
Import database:
mysql -u root -p < sql/schema.sql
Configure config/database.php:
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_exam_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('BASE_URL', 'http://localhost/smart_exam');
Default admin login:
Email: admin@smartexam.com
Password: Admin@1234
Access Control Rules
Exams require: Dept match + Section match + Semester match (if set)
Quizzes: section required, semester optional
Midterm/Final: both semester AND section required
Design System
Primary: #4F46E5 (Indigo)
Font: Inter (Google Fonts)
Border radius: 12-20px cards, 8px inputs/buttons
Shadows: Multi-layer soft shadows
All CSS variables in assets/css/main.css :root
