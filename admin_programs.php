<?php
require_once 'includes/config.php';

// Ensure we have a PDO instance available as $pdo. Some configs use getDBConnection().
if (!isset($pdo) || !($pdo instanceof PDO)) {
	// Try loading root config which provides getDBConnection()
	if (file_exists(__DIR__ . '/config.php')) {
		require_once __DIR__ . '/config.php';
		try {
			$conn = getDBConnection();
			// map to $pdo to keep compatibility with other admin pages
			$pdo = $conn;
		} catch (Exception $e) {
			die('Database connection error: ' . $e->getMessage());
		}
	} else {
		die('Database configuration not found.');
	}
}

if (!isLoggedIn() || !isAdmin()) {
    redirect('index.php');
}

$user = getCurrentUser();

// Get unread notifications count
$unread_notifications_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_notifications_stmt->execute([$user['id']]);
$stats['unread_notifications'] = $unread_notifications_stmt->fetchColumn();

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['add_program'])) {
		$name = sanitize($_POST['name']);
		$department_id = (int)$_POST['department_id'];
		$description = sanitize($_POST['description']);

		if (empty($name)) {
			$message = 'Program name is required';
			$messageType = 'error';
		} else {
			try {
				$stmt = $pdo->prepare("INSERT INTO programs (name, department_id, description) VALUES (?, ?, ?)");
				$stmt->execute([$name, $department_id ?: null, $description]);
				$message = 'Program added successfully';
				$messageType = 'success';
				logActivity('add_program', "Added program: $name");
			} catch(PDOException $e) {
				$message = 'Error adding program: ' . $e->getMessage();
				$messageType = 'error';
			}
		}
	} elseif (isset($_POST['edit_program'])) {
		$id = (int)$_POST['program_id'];
		$name = sanitize($_POST['name']);
		$department_id = (int)$_POST['department_id'];
		$description = sanitize($_POST['description']);

		if (empty($name)) {
			$message = 'Program name is required';
			$messageType = 'error';
		} else {
			try {
				$stmt = $pdo->prepare("UPDATE programs SET name = ?, department_id = ?, description = ? WHERE id = ?");
				$stmt->execute([$name, $department_id ?: null, $description, $id]);
				$message = 'Program updated successfully';
				$messageType = 'success';
				logActivity('edit_program', "Updated program ID: $id");
			} catch(PDOException $e) {
				$message = 'Error updating program: ' . $e->getMessage();
				$messageType = 'error';
			}
		}
	} elseif (isset($_POST['delete_program'])) {
		$id = (int)$_POST['program_id'];

		try {
			// Get program name first
			$stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ?");
			$stmt->execute([$id]);
			$progName = $stmt->fetchColumn();

			if (!$progName) {
				$message = 'Program not found';
				$messageType = 'error';
			} else {
				// Check if program is being used by documents, audits or capa
				$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE program_id = ?");
				$stmt->execute([$id]);
				$docCount = $stmt->fetchColumn();

				$stmt = $pdo->prepare("SELECT COUNT(*) FROM audits WHERE program_id = ?");
				$stmt->execute([$id]);
				$auditCount = $stmt->fetchColumn();

				$stmt = $pdo->prepare("SELECT COUNT(*) FROM capa WHERE program_id = ?");
				$stmt->execute([$id]);
				$capaCount = $stmt->fetchColumn();

				if ($docCount > 0 || $auditCount > 0 || $capaCount > 0) {
					$message = 'Cannot delete program "' . htmlspecialchars($progName) . '". It is being used by ' . $docCount . ' document(s), ' . $auditCount . ' audit(s) and ' . $capaCount . ' CAPA(s). Please remove associated items first.';
					$messageType = 'error';
				} else {
					$stmt = $pdo->prepare("DELETE FROM programs WHERE id = ?");
					$stmt->execute([$id]);
					$message = 'Program deleted successfully';
					$messageType = 'success';
					logActivity('delete_program', "Deleted program: $progName");
				}
			}
		} catch(PDOException $e) {
			$message = 'Error deleting program: ' . $e->getMessage();
			$messageType = 'error';
		}
	}
}

// Get all programs with department name
try {
	$stmt = $pdo->query("SELECT p.*, d.name as department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY p.name");
	$programs = $stmt->fetchAll();
} catch(PDOException $e) {
	$programs = [];
}

// Get departments for selection
try {
	$stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
	$departments = $stmt->fetchAll();
} catch(PDOException $e) {
	$departments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Programs - DDBQAAMS</title>
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
                <li><a href="admin_users.php">User Management</a></li>
                <li><a href="admin_departments.php">Department Management</a></li>
                <li><a href="admin_programs.php" class="active" >Program Management</a></li>
                <li><a href="admin_documents.php">Document Management</a></li>
                <li><a href="admin_audits.php">Audit Scheduling</a></li>
                <li><a href="admin_capa.php">CAPA Management</a></li>
                <li><a href="admin_reports.php">Reports & Analytics</a></li>
                <li><a href="admin_logs.php">Activity Logs</a></li>
            </ul>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Program Management</h1>
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
				<?php if ($message): ?>
					<div class="alert alert-<?php echo $messageType; ?>">
						<?php echo htmlspecialchars($message); ?>
					</div>
				<?php endif; ?>

				<div class="card" style="grid-column: 1 / -1;">
					<div class="card-header">
						<h3>Program Management</h3>
					</div>
					<div class="card-body">
						<!-- Add New Program Section -->
						<div style="margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #e6e6e6;">
							<h4 style="margin-top: 0; color: #1e3a8a;">Add New Program</h4>
							<form method="POST" class="form">
								<div class="form-row">
									<div class="form-group">
										<label for="name">Program Name *</label>
										<input type="text" id="name" name="name" required>
									</div>
									<div class="form-group">
										<label for="department_id">Department</label>
										<select id="department_id" name="department_id">
											<option value="">-- Select Department --</option>
											<?php foreach ($departments as $d): ?>
												<option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="form-group">
										<label for="description">Description</label>
										<textarea id="description" name="description" rows="3"></textarea>
									</div>
								</div>
								<button type="submit" name="add_program" class="btn btn-primary">Add Program</button>
							</form>
						</div>

						<!-- Existing Programs Section -->
						<div>
							<h4 style="margin-top: 0; color: #1e3a8a;">Existing Programs</h4>
							<div class="programs-table-container">
								<table class="table">
									<thead>
										<tr>
											<th>ID</th>
											<th>Name</th>
											<th>Department</th>
											<th>Description</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($programs as $p): ?>
											<tr>
												<td><?php echo $p['id']; ?></td>
												<td><?php echo htmlspecialchars($p['name']); ?></td>
												<td><?php echo htmlspecialchars($p['department_name'] ?? ''); ?></td>
												<td><?php echo htmlspecialchars($p['description'] ?? ''); ?></td>
												<td>
													<button class="btn btn-sm btn-secondary" onclick="editProgram(<?php echo $p['id']; ?>, '<?php echo addslashes($p['name']); ?>', '<?php echo $p['department_id'] ?: 0; ?>', '<?php echo addslashes($p['description'] ?? ''); ?>')">Edit</button>
													<button class="btn btn-sm btn-danger" onclick="deleteProgram(<?php echo $p['id']; ?>, '<?php echo addslashes($p['name']); ?>')">Delete</button>
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
		</div>
	</div>

	<!-- Edit Program Modal -->
	<div id="editModal" class="modal" style="display: none;">
		<div class="modal-content">
			<div class="modal-header">
				<h3>Edit Program</h3>
				<span class="close" onclick="closeModal()">&times;</span>
			</div>
			<form method="POST" class="modal-body">
				<input type="hidden" id="edit_program_id" name="program_id">
				<div class="form-group">
					<label for="edit_name">Program Name *</label>
					<input type="text" id="edit_name" name="name" required>
				</div>
				<div class="form-group">
					<label for="edit_department_id">Department</label>
					<select id="edit_department_id" name="department_id">
						<option value="">-- Select Department --</option>
						<?php foreach ($departments as $d): ?>
							<option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-group">
					<label for="edit_description">Description</label>
					<textarea id="edit_description" name="description" rows="3"></textarea>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
					<button type="submit" name="edit_program" class="btn btn-primary">Update Program</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Delete Confirmation Modal -->
	<div id="deleteModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
		<div class="modal-content" style="background-color: #fefefe; margin: 15% auto; padding: 0; border: 1px solid #888; width: 80%; max-width: 500px; border-radius: 8px;">
			<div class="modal-header" style="padding: 15px; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; border-radius: 8px 8px 0 0;">
				<h3 style="margin: 0; color: #dc3545;">Confirm Delete</h3>
				<span class="close" onclick="closeDeleteModal()" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
			</div>
			<div class="modal-body" style="padding: 20px;">
				<p>Are you sure you want to delete the program "<span id="delete_prog_name" style="font-weight: bold;"></span>"?</p>
				<p class="text-danger" style="color: #dc3545; margin: 10px 0;">This action cannot be undone.</p>
			</div>
			<div class="modal-footer" style="padding: 15px; background-color: #f8f9fa; border-top: 1px solid #dee2e6; border-radius: 0 0 8px 8px; text-align: right;">
				<button type="button" class="btn btn-secondary" onclick="closeDeleteModal()" style="margin-right: 10px; padding: 8px 16px; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
				<form method="POST" style="display: inline;">
					<input type="hidden" id="delete_program_id" name="program_id">
					<button type="submit" name="delete_program" class="btn btn-danger" style="padding: 8px 16px; background-color: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Delete Program</button>
				</form>
			</div>
		</div>
	</div>

	<script>
		function editProgram(id, name, departmentId, description) {
			document.getElementById('edit_program_id').value = id;
			document.getElementById('edit_name').value = name;
			document.getElementById('edit_department_id').value = departmentId;
			document.getElementById('edit_description').value = description;
			document.getElementById('editModal').style.display = 'block';
		}

		function closeModal() {
			document.getElementById('editModal').style.display = 'none';
		}

		function deleteProgram(id, name) {
			document.getElementById('delete_program_id').value = id;
			document.getElementById('delete_prog_name').textContent = name;
			document.getElementById('deleteModal').style.display = 'block';
		}

		function closeDeleteModal() {
			document.getElementById('deleteModal').style.display = 'none';
		}

		// Close modal when clicking outside
		window.onclick = function(event) {
			const editModal = document.getElementById('editModal');
			const deleteModal = document.getElementById('deleteModal');
			if (event.target == editModal) {
				editModal.style.display = 'none';
			}
			if (event.target == deleteModal) {
				deleteModal.style.display = 'none';
			}
		}

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
