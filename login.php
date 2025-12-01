<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    $user = getCurrentUser();
    switch ($user['role_name']) {
        case 'admin':
        case 'qaa staff':
            redirect('admin_dashboard.php');
            break;
        case 'dean':
            redirect('dean_dashboard.php');
            break;
        case 'program_head':
            redirect('faculty_dashboard.php');
            break;
        case 'auditor':
            redirect('auditor_dashboard.php');
            break;
        case 'faculty':
            redirect('faculty_dashboard.php');
            break;
        default:
            redirect('index.php');
    }
}
// If not logged in, show the login form (same as index.php)
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DDBQAAMS - Login</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>BiPSU Quality Assurance Accreditation Database Management System</h1>
            <p>DDBQAAMS</p>
        </div>

        <form id="loginForm" action="php/login.php" method="POST" class="login-form">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-container">
                    <input type="password" id="password" name="password" required>
                    <span class="password-toggle" onclick="togglePassword()">
                        <img src="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjQiIGhlaWdodD0iMjQiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTEyIDRjLTYuNjMgMCAxMiA1LjM3IDEyIDEyczUuMzcgMTIgMTIgMTJzLTEyIDUuMzctMTIgMTJDNS4zNyAyNCAxIDIwLjYzIDEgMTJTNS4zNyA0IDEyIDRaIiBzdHJva2U9IiM2NjY2NjYiIHN0cm9rZS13aWR0aD0iMiIvPgo8Y2lyY2xlIGN4PSIxMiIgY3k9IjEyIiByPSIzIiBzdHJva2U9IiM2NjY2NjYiIHN0cm9rZS13aWR0aD0iMiIvPgo8L3N2Zz4K" alt="Show Password">
                    </span>
                </div>
            </div>

            <div class="form-group">
                <label for="role_id">Role</label>
                <select id="role_id" name="role_id" onchange="toggleDeptProg()" required>
                    <option value="">Select Role</option>
                    <?php
                    $roles = $pdo->query("SELECT * FROM roles ORDER BY name")->fetchAll();
                    foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>" data-role="<?php echo $role['name']; ?>"><?php echo ucfirst(str_replace('_', ' ', $role['name'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="department_group">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id" onchange="filterPrograms()" required>
                    <option value="">Select Department</option>
                    <?php
                    $departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
                    foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="program_group">
                <label for="program_id">Program</label>
                <select id="program_id" name="program_id" required>
                    <option value="">Select Program</option>
                    <?php
                    $programs = $pdo->query("SELECT p.*, d.name as department_name FROM programs p JOIN departments d ON p.department_id = d.id ORDER BY d.name, p.name")->fetchAll();
                    foreach ($programs as $prog): ?>
                        <option value="<?php echo $prog['id']; ?>" data-dept="<?php echo $prog['department_id']; ?>"><?php echo htmlspecialchars($prog['department_name'] . ' - ' . $prog['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="login-btn">Login</button>
        </form>

        <div class="login-links">
            <p>Don't have an account? <a href="signup.php">Sign up here</a></p>
            <p><a href="landing.php">← Back to Home</a></p>
        </div>

        <div id="errorMessage" class="error-message" style="display: none;"></div>
    </div>

    <script src="js/script.js"></script>
    <script>
        function toggleDeptProg() {
            const roleSelect = document.getElementById('role_id');
            const selectedOption = roleSelect.options[roleSelect.selectedIndex];
            const roleName = selectedOption ? selectedOption.getAttribute('data-role') : '';
            const rn = roleName ? roleName.toLowerCase() : '';
            const deptGroup = document.getElementById('department_group');
            const progGroup = document.getElementById('program_group');
            const deptSelect = document.getElementById('department_id');
            const progSelect = document.getElementById('program_id');

            if (rn === 'admin' || rn === 'qaa_staff' || rn === 'qaa staff' || rn === 'faculty') {
                deptGroup.style.display = 'none';
                progGroup.style.display = 'none';
                deptSelect.required = false;
                progSelect.required = false;
                deptSelect.value = '';
                progSelect.value = '';
            } else if (rn === 'dean') {
                deptGroup.style.display = 'block';
                progGroup.style.display = 'none';
                deptSelect.required = true;
                progSelect.required = false;
                progSelect.value = '';
            } else {
                deptGroup.style.display = 'block';
                progGroup.style.display = 'block';
                deptSelect.required = true;
                progSelect.required = true;
            }
        }

        function filterPrograms() {
            const deptId = document.getElementById('department_id').value;
            const programSelect = document.getElementById('program_id');
            const options = programSelect.querySelectorAll('option');

            options.forEach(option => {
                if (option.value === '' || option.getAttribute('data-dept') === deptId) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                }
            });

            programSelect.value = '';
        }

        // Initialize filter on page load
        document.addEventListener('DOMContentLoaded', function() {
            filterPrograms();
            toggleDeptProg();
        });
    </script>
</body>
</html>
