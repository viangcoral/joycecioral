<?php
require_once 'includes/config.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('index.php');
}

$user = getCurrentUser();

// Get unread notifications count
$unread_notifications_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notifications_stmt->execute([$user['id']]);
$stats['unread_notifications'] = $unread_notifications_stmt->fetchColumn();

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role_id = (int)$_POST['role_id'];
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;

    // Enforce that faculty users do not get department/program assigned here
    try {
        $rstmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $rstmt->execute([$role_id]);
        $r = $rstmt->fetch();
        if ($r && strtolower($r['name']) === 'faculty') {
            $department_id = null;
            $program_id = null;
        }
    } catch (PDOException $e) {
        // ignore and proceed
    }
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);

    // Validate passwords match
    if ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        $password = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role_id, department_id, program_id, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password, $role_id, $department_id, $program_id, $first_name, $last_name]);
            logActivity("Created user: $username");
            $success_message = "User created successfully!";
        } catch(PDOException $e) {
            $error_message = "Error creating user: " . $e->getMessage();
        }
    }
}

// Handle user deletion (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && is_numeric($_POST['user_id'])) {
    $user_id = (int)$_POST['user_id'];
    // prevent deleting yourself accidentally
    if ($user_id === $user['id']) {
        $error_message = "You cannot delete your own account.";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            logActivity("Deleted user ID: $user_id");
            $success_message = "User deleted successfully!";
        } catch(PDOException $e) {
            $error_message = "Error deleting user: " . $e->getMessage();
        }
    }
}





// Get search and filter parameters
$search_term = $_GET['search'] ?? '';
$role_filter = $_GET['role_filter'] ?? '';
$department_filter = $_GET['department_filter'] ?? '';

// Build query with search and filters
$query = "
    SELECT u.*, r.name as role_name, d.name as department_name, p.name as program_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p ON u.program_id = p.id
    WHERE u.is_active = 1
";
$params = [];

if ($search_term) {
    $query .= " AND (u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $search_param = '%' . $search_term . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($role_filter) {
    $query .= " AND u.role_id = ?";
    $params[] = $role_filter;
}

if ($department_filter) {
    $query .= " AND u.department_id = ?";
    $params[] = $department_filter;
}

$query .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get roles, departments, programs for dropdowns
$roles = $pdo->query("SELECT * FROM roles WHERE name NOT IN ('admin') ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$programs = $pdo->query("SELECT * FROM programs ORDER BY name")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - DDBQAAMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>DDBQAAMS</h2>
                <p>Administrator</p>
            </div>
            <ul class="sidebar-menu">
                <li><a href="admin_dashboard.php">Dashboard</a></li>
                <li><a href="admin_users.php" class="active">User Management</a></li>
                <li><a href="admin_departments.php">Department Management</a></li>
                <li><a href="admin_programs.php">Program Management</a></li>
                <li><a href="admin_documents.php">Document Management</a></li>
                <li><a href="admin_audits.php">Audit Scheduling</a></li>
                <li><a href="admin_capa.php">CAPA Management</a></li>
                <li><a href="admin_reports.php">Reports & Analytics</a></li>
                <li><a href="admin_logs.php">Activity Logs</a></li>
            </ul>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>User Management</h1>
                <div class="user-info">
                    <div class="notification-dropdown">
                        <button class="notification-icon" onclick="toggleNotifications()">
                            <i class="fas fa-bell"></i>
                            <span id="notification-badge" class="notification-badge" style="display: <?php echo $stats['unread_notifications'] > 0 ? 'inline' : 'none'; ?>;"><?php echo $stats['unread_notifications']; ?></span>
                        </button>
                        <div id="notification-dropdown-content" class="notification-dropdown-content">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <button onclick="markAllRead()" class="mark-all-read-btn">Mark All Read</button>
                            </div>
                            <div id="notification-list" class="notification-list">
                                <!-- Notifications will be loaded here -->
                            </div>
                            <div class="notification-footer">
                                <a href="#" onclick="viewAllNotifications()">View All Notifications</a>
                            </div>
                        </div>
                    </div>
                    <a href="php/logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <div class="content-grid">
                <div class="card landscape create-user-card">
                    <h3>Create New User</h3>
                    <?php if (isset($success_message)): ?>
                        <div class="success-message"><?php echo $success_message; ?></div>
                    <?php endif; ?>
                    <?php if (isset($error_message)): ?>
                        <div class="error-message"><?php echo $error_message; ?></div>
                    <?php endif; ?>

                    <form method="POST" class="form">

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name">
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="username">Username *</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email *</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="password">Password *</label>
                                <input type="password" id="password" name="password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="role_id">Role *</label>
                                <select id="role_id" name="role_id" onchange="toggleDeptProg()" required>
                                    <option value="">Select Role</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" data-role="<?php echo $role['name']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="department_id">Department</label>
                                <select id="department_id" name="department_id">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="program_id">Program</label>
                                <select id="program_id" name="program_id">
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $prog): ?>
                                        <option value="<?php echo $prog['id']; ?>"><?php echo htmlspecialchars($prog['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <button type="submit" name="create_user" class="btn">Create User</button>
                        <br>
                    </form>
                </div>



                <div class="card landscape">
                    <h3>Existing Users (<?php echo count($users); ?>)</h3>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Program</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><?php echo htmlspecialchars($u['role_name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['department_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($u['program_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Are you sure you want to permanently delete this user?');">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" name="delete_user" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleDeptProg() {
            const roleSelect = document.getElementById('role_id');
            const selectedOption = roleSelect.options[roleSelect.selectedIndex];
            const roleName = selectedOption.getAttribute('data-role');
            const deptGroup = document.getElementById('department_id').closest('.form-group');
            const progGroup = document.getElementById('program_id').closest('.form-group');

                const rn = roleName ? roleName.toLowerCase() : '';
                if (rn === 'admin' || rn === 'qaa_staff' || rn === 'qaa staff' || rn === 'faculty') {
                    // Admin, QAA staff, and Faculty should not choose a specific dept/program here
                    deptGroup.style.display = 'none';
                    progGroup.style.display = 'none';
                } else if (rn === 'dean') {
                    // Dean chooses department but not program
                    deptGroup.style.display = 'block';
                    progGroup.style.display = 'none';
                } else {
                    // Other roles choose both
                    deptGroup.style.display = 'block';
                    progGroup.style.display = 'block';
                }
        }

            // Initialize on load to reflect any pre-selected role
            document.addEventListener('DOMContentLoaded', function() {
                // small timeout to ensure DOM and options are ready
                setTimeout(function() {
                    if (document.getElementById('role_id')) toggleDeptProg();
                }, 10);
            });





        // Notification dropdown functionality
        let notificationsDropdown = null;

        function toggleNotifications() {
            const dropdown = document.getElementById('notification-dropdown-content');
            if (dropdown.style.display === 'block') {
                dropdown.style.display = 'none';
            } else {
                dropdown.style.display = 'block';
                loadNotifications();
            }
        }

        function loadNotifications() {
            fetch('get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    const list = document.getElementById('notification-list');
                    if (data.length === 0) {
                        list.innerHTML = '<div class="no-notifications">No notifications yet.</div>';
                    } else {
                        list.innerHTML = data.map(notif => `
                            <div class="notification-item ${notif.is_read ? 'read' : 'unread'}">
                                <div class="notification-content">
                                    <strong>${notif.title || 'Notification'}</strong><br>
                                    <small>${new Date(notif.created_at).toLocaleString()}</small><br>
                                    ${notif.message}
                                </div>
                                ${!notif.is_read ? `<button onclick="markRead(${notif.id})" class="mark-read-btn">Mark Read</button>` : ''}
                            </div>
                        `).join('');
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    document.getElementById('notification-list').innerHTML = '<div class="no-notifications">Error loading notifications.</div>';
                });
        }

        function markAllRead() {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'all=1'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadNotifications();
                    updateBadge();
                }
            })
            .catch(error => console.error('Error marking all read:', error));
        }

        function markRead(id) {
            fetch(`mark_notification_read.php?id=${id}`, {
                method: 'POST'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadNotifications();
                    updateBadge();
                }
            })
            .catch(error => console.error('Error marking read:', error));
        }

        function updateBadge() {
            fetch('get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    const unreadCount = data.filter(n => !n.is_read).length;
                    const badge = document.getElementById('notification-badge');
                    if (unreadCount > 0) {
                        badge.textContent = unreadCount;
                        badge.style.display = 'inline';
                    } else {
                        badge.style.display = 'none';
                    }
                });
        }

        function viewAllNotifications() {
            // For now, just close the dropdown. In future, could link to full notifications page
            document.getElementById('notification-dropdown-content').style.display = 'none';
            alert('Full notifications page not implemented yet.');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notification-dropdown-content');
            const icon = document.querySelector('.notification-icon');
            if (!icon.contains(event.target) && dropdown.style.display === 'block') {
                dropdown.style.display = 'none';
            }
        });
    </script>

    <style>
        .table-container table {
            background-color: white;
            color: black;
        }
        .table-container table th, .table-container table td {
            background-color: white;
            color: black;
        }
        .table-container table thead tr {
            background-color: white;
            color: black;
        }
        .notification-dropdown {
            position: relative;
            display: inline-block;
        }

        .notification-dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background-color: white;
            min-width: 350px;
            max-width: 500px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            border-radius: 8px;
            z-index: 1000;
            border: 1px solid #e5e7eb;
        }

        .notification-header {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header h4 {
            margin: 0;
            color: #1e3a8a;
        }



        .notification-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s;
        }

        .notification-item:hover {
            background-color: #f9fafb;
        }

        .notification-item.unread {
            background-color: #f0f9ff;
            border-left: 3px solid #1e3a8a;
        }

        .notification-item.read {
            background-color: #ffffff;
        }

        .notification-content {
            margin-bottom: 10px;
        }

        .notification-content strong {
            color: #1e3a8a;
        }

        .notification-content small {
            color: #6b7280;
        }

        .mark-read-btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .mark-read-btn:hover {
            background: #059669;
        }

        .notification-footer {
            padding: 10px 15px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
        }

        .notification-footer a {
            color: #1e3a8a;
            text-decoration: none;
            font-size: 14px;
        }

        .notification-footer a:hover {
            text-decoration: underline;
        }

        .no-notifications {
            padding: 20px;
            text-align: center;
            color: #6b7280;
        }
    </style>
</body>
</html>
