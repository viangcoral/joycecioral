    <?php
require_once 'includes/config.php';

// Ensure we have a PDO instance available as $pdo. Some configs use getDBConnection().
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
        try {
            $conn = getDBConnection();
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

$message = $_GET['message'] ?? '';
$messageType = $message ? 'success' : '';

// Handle filters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$program_filter = $_GET['program'] ?? '';
$department_filter = $_GET['department'] ?? '';

// Fetch programs and departments for filters
try {
    $stmt = $pdo->query("SELECT id, name, department_id FROM programs ORDER BY name");
    $programs = $stmt->fetchAll();
} catch (PDOException $e) {
    $programs = [];
}

try {
    $stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $stmt->fetchAll();
} catch (PDOException $e) {
    $departments = [];
}

// Handle upload document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $title = sanitize($_POST['title']);
    $description = sanitize($_POST['description']);
    $program_id = !empty($_POST['program_id']) ? (int)$_POST['program_id'] : null;
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

    // File upload handling
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Please select a file to upload.';
        $messageType = 'error';
    } else {
        $file = $_FILES['document'];
        $fileName = $file['name'];
        $fileSize = $file['size'];
        $fileTmp = $file['tmp_name'];

        // Validate file type
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($fileExt, ALLOWED_FILE_TYPES)) {
            $message = 'Invalid file type. Allowed types: ' . implode(', ', ALLOWED_FILE_TYPES);
            $messageType = 'error';
        } elseif ($fileSize > MAX_FILE_SIZE) {
            $message = 'File size too large. Maximum size: ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB';
            $messageType = 'error';
        } else {
            // Generate unique filename
            $newFileName = uniqid() . '_' . time() . '.' . $fileExt;
            $uploadPath = UPLOAD_DIR . $newFileName;

            if (move_uploaded_file($fileTmp, $uploadPath)) {
                // Insert document record
                $stmt = $pdo->prepare("INSERT INTO documents (title, description, file_path, file_type, file_size, uploaded_by, program_id, department_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$title, $description, $newFileName, $fileExt, $fileSize, $user['id'], $program_id, $department_id])) {
                    $documentId = $pdo->lastInsertId();

                    // Create notification for relevant users
                    if ($user['role_name'] === 'faculty') {
                        // Notify QAA officers
                        $stmt = $pdo->query("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'qaa staff')");
                        $qaaUsers = $stmt->fetchAll();
                        foreach ($qaaUsers as $qaaUser) {
                            createNotification($qaaUser['id'], 'New Document Uploaded', "A new document '$title' has been uploaded by " . $user['full_name'], 'info', ['document_id' => $documentId]);
                        }
                    }

                    logActivity('upload_document', "Uploaded document: $title");
                    // Note: PDF conversion on upload removed - LibreOffice not required.
                    // Files will be viewable/downloadable via file_view.php
                    // PDFs display inline in modal, non-PDF files are offered for download.

                    $message = 'Document uploaded successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to save document information.';
                    $messageType = 'error';
                    unlink($uploadPath); // Remove uploaded file
                }
            } else {
                $message = 'Failed to upload file.';
                $messageType = 'error';
            }
        }
    }
}

// Handle actions: update status, delete document, bulk operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $document_id = (int)$_POST['document_id'];
        $status = sanitize($_POST['status']);
        $allowed = ['draft','submitted','approved','rejected','archived'];
        if (!in_array($status, $allowed)) {
            $message = 'Invalid status.';
            $messageType = 'error';
        } else {
            $notes = sanitize($_POST['notes'] ?? '');
            try {
                $stmt = $pdo->prepare("UPDATE documents SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$status, $document_id]);

                // Insert status change log if notes provided
                if (!empty($notes)) {
                    $stmt = $pdo->prepare("INSERT INTO document_status_logs (document_id, old_status, new_status, notes, changed_by) VALUES (?, (SELECT status FROM documents WHERE id = ?), ?, ?, ?)");
                    $stmt->execute([$document_id, $document_id, $status, $notes, $_SESSION['user_id']]);
                }

                $message = 'Document status updated.';
                $messageType = 'success';
                // notify owner
                $stmt = $pdo->prepare("SELECT uploaded_by, title FROM documents WHERE id = ?");
                $stmt->execute([$document_id]);
                $doc = $stmt->fetch();
                if ($doc) {
                    createNotification($doc['uploaded_by'], 'Document Status Updated', "Your document '{$doc['title']}' status is now " . ucfirst($status), 'info', ['document_id' => $document_id]);
                }
                logActivity('update_document_status', "Updated document $document_id status to $status");
            } catch (PDOException $e) {
                $message = 'Error updating status: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif (isset($_POST['delete_document'])) {
        $document_id = (int)$_POST['document_id'];
        try {
            $stmt = $pdo->prepare("SELECT file_path, title FROM documents WHERE id = ?");
            $stmt->execute([$document_id]);
            $doc = $stmt->fetch();
            if (!$doc) {
                $message = 'Document not found.';
                $messageType = 'error';
            } else {
                $filePath = UPLOAD_DIR . $doc['file_path'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                // Remove cached converted PDF if present
                $convertedPath = __DIR__ . DIRECTORY_SEPARATOR . rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . 'converted' . DIRECTORY_SEPARATOR . pathinfo($doc['file_path'], PATHINFO_FILENAME) . '.pdf';
                if (file_exists($convertedPath)) {
                    @unlink($convertedPath);
                }
                $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
                $stmt->execute([$document_id]);
                $message = 'Document deleted successfully.';
                $messageType = 'success';
                logActivity('delete_document', "Deleted document: {$doc['title']}");
            }
        } catch (PDOException $e) {
            $message = 'Error deleting document: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Build query with filters
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "d.title LIKE ?";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $where[] = "d.status = ?";
    $params[] = $status_filter;
}

if (!empty($program_filter)) {
    $where[] = "d.program_id = ?";
    $params[] = $program_filter;
}

if (!empty($department_filter)) {
    $where[] = "d.department_id = ?";
    $params[] = $department_filter;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Get total count
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM documents d $whereClause");
    $countStmt->execute($params);
    $total_documents = $countStmt->fetch()['total'];
} catch (PDOException $e) {
    $total_documents = 0;
}

// Fetch documents with filters
try {
    $stmt = $pdo->prepare("SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS uploader_name, p.name as program_name, dept.name as department_name
                           FROM documents d
                           LEFT JOIN users u ON d.uploaded_by = u.id
                           LEFT JOIN programs p ON d.program_id = p.id
                           LEFT JOIN departments dept ON d.department_id = dept.id
                           $whereClause
                           ORDER BY d.uploaded_at DESC");
    $stmt->execute($params);
    $documents = $stmt->fetchAll();
} catch (PDOException $e) {
    $documents = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Documents - DDBQAAMS</title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .table-container {
            max-height: 60vh;
            overflow-y: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            position: relative;
        }
        .table-container .table td {
            vertical-align: middle;
        }
        .table-container .table thead th {
            color: #ffffffff !important;
            background-color: #ffffff;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 3;
            box-shadow: 0 2px 0 rgba(0,0,0,0.04);
            border-bottom: 1px solid #e6e6e6;
        }
        .content-grid .card {
            width: 100%;
        }
    </style>
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
                <li><a href="admin_programs.php">Program Management</a></li>
                <li><a href="admin_documents.php" class="active" >Document Management</a></li>
                <li><a href="admin_audits.php">Audit Scheduling</a></li>
                <li><a href="admin_capa.php">CAPA Management</a></li>
                <li><a href="admin_reports.php">Reports & Analytics</a></li>
                <li><a href="admin_logs.php">Activity Logs</a></li>
            </ul>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Document Management</h1>
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

                <!-- Documents Table Card -->
                <div class="card" style="grid-column: span 2;">
        <div class="card-header">
            <h3>All Documents (<?php echo $total_documents; ?>)</h3>
        </div>
        <div class="card-body">
            <h3>Search and Filters</h3>
            <form method="GET" class="filter-form">
                <div class="form-row">
                    <div class="form-group col-md-3">
                        <input type="text" name="search" placeholder="Search by title..." value="<?php echo htmlspecialchars($search); ?>" class="form-control">
                    </div>
                    <div class="form-group col-md-2">
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="draft" <?php echo $status_filter == 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="submitted" <?php echo $status_filter == 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="archived" <?php echo $status_filter == 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <select name="department" id="filter_department" class="form-control">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-2">
                        <select name="program" id="filter_program" class="form-control">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?php echo $prog['id']; ?>" data-dept="<?php echo $prog['department_id']; ?>" <?php echo $program_filter == $prog['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($prog['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-3">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="admin_documents.php" class="btn btn-secondary">Clear</a>
                    </div>
                </div>
            </form>
            <div style="float:right;"><button onclick="openUploadModal()" class="btn btn-success">Upload New Document</button></div>
            <div style="clear:both;"></div>
            <div class="table-container">
                <table class="table" style="width: 100%;">
                    <thead style="background-color: white;">
                        <tr>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Program</th>
                            <th>Department</th>
                            <th>Uploaded By</th>
                            <th>Upload Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                <td><span class="status-badge status-<?php echo $doc['status']; ?>"><?php echo ucfirst($doc['status']); ?></span></td>
                                <td><?php echo htmlspecialchars($doc['program_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($doc['department_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($doc['uploader_name'] ?? ''); ?></td>
                                <td><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></td>
                                <td>
                                    <?php
                                        // Build a web-accessible URL to the uploads folder relative to the site root.
                                        // dirname($_SERVER['PHP_SELF']) usually returns something like '/ddbqaams'
                                        $scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                                        $uploadFilename = rawurlencode(basename($doc['file_path']));
                                        // If script is in the web root, $scriptDir may be '/'; normalize to empty string
                                        if ($scriptDir === '/' || $scriptDir === '.') $scriptDir = '';
                                        // Use file_view.php to stream/convert files for preview inside iframe
                                        $webPath = $scriptDir . '/file_view.php?f=' . $uploadFilename;
                                    ?>
                                    <button class="btn btn-sm btn-secondary" onclick="openViewModal('<?php echo $webPath; ?>', '<?php echo htmlspecialchars($doc['title']); ?>')" title="View Document"><i class="fas fa-eye"></i></button>
                                    <a href="<?php echo UPLOAD_DIR . $doc['file_path']; ?>" download class="btn btn-sm btn-secondary" title="Download Document"><i class="fas fa-download"></i></a>
                                    <button class="btn btn-sm btn-secondary" onclick="openStatusModal(<?php echo $doc['id']; ?>, '<?php echo $doc['status']; ?>')" title="Update Status"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $doc['id']; ?>)" title="Delete Document"><i class="fas fa-trash"></i></button>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (empty($documents)): ?>
                    <div class="text-center mt-3">No documents found.</div>
                <?php endif; ?>

            </div>
        </div>
    </div> <!-- end card -->
            </div> <!-- end content-grid -->
        </div> <!-- end main-content -->
    </div> <!-- end dashboard -->

    <!-- Status Modal -->
    <div id="statusModal" class="modal" style="display:none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Document Status</h3>
                <span class="close" onclick="closeStatusModal()">&times;</span>
            </div>
            <form method="POST" class="modal-body">
                <input type="hidden" name="document_id" id="status_document_id">
                <div class="form-group">
                    <label for="status_select">Status</label>
                    <select id="status_select" name="status">
                        <option value="draft">Draft</option>
                        <option value="submitted">Submitted</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Add notes about this status change..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload Modal -->
    <div id="uploadModal" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Upload New Document</h3>
                <span class="close" onclick="closeUploadModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" action="admin_documents.php">
                    <div class="form-group">
                        <label for="modal_title">Document Title *</label>
                        <input type="text" id="modal_title" name="title" required>
                    </div>

                    <div class="form-group">
                        <label for="modal_description">Description</label>
                        <textarea id="modal_description" name="description" rows="4"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="modal_department_id">Department</label>
                            <select id="modal_department_id" name="department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="modal_program_id">Program</label>
                            <select id="modal_program_id" name="program_id">
                                <option value="">Select Program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>" data-dept="<?php echo $program['department_id']; ?>"><?php echo htmlspecialchars($program['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="modal_document">Document File *</label>
                        <input type="file" id="modal_document" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" required>
                        <small class="form-help">Allowed file types: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX. Maximum size: <?php echo (MAX_FILE_SIZE / 1024 / 1024); ?>MB. PDFs preview in browser; other formats download for local viewing.</small>
                    </div>

                    <div class="modal-footer">
                        <div style="float:left; margin-top:8px;">
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Document</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Document Modal -->
    <div id="viewModal" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 90%; max-height: 90%;">
            <div class="modal-header">
                <h3 id="viewModalTitle">View Document</h3>
                <button class="modal-close-btn" onclick="closeViewModal()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div id="viewModalContent">
                    <!-- iframe used for PDFs -->
                    <iframe id="documentViewer" src="" style="width: 100%; height: 600px; border: none; display: none;"></iframe>

                    <!-- fallback for non-PDF files -->
                    <div id="viewFallback" style="display: none; padding: 30px; text-align: center;">
                        <p style="margin:0 0 15px 0; font-size: 16px; font-weight: bold;">This file type cannot be previewed in the browser.</p>
                        <p style="margin:0 0 20px 0; color: #666;">Download the file to open it with the appropriate application (Microsoft Word, Excel, PowerPoint, etc.):</p>
                        <a id="downloadLink" href="#" download class="btn btn-primary" style="padding: 10px 20px; font-size: 16px;">
                            <i class="fas fa-download"></i> Download File
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="document_id" id="delete_document_id">
        <input type="hidden" name="delete_document" value="1">
    </form>

    <script>
        function openStatusModal(id, current) {
            document.getElementById('status_document_id').value = id;
            document.getElementById('status_select').value = current;
            document.getElementById('notes').value = '';
            document.getElementById('statusModal').style.display = 'block';
        }
        function closeStatusModal() { document.getElementById('statusModal').style.display = 'none'; }
        function openUploadModal() { document.getElementById('uploadModal').style.display = 'block'; }
        function closeUploadModal() { document.getElementById('uploadModal').style.display = 'none'; }
        function openViewModal(filePath, title) {
            document.getElementById('viewModalTitle').textContent = title;
            var viewer = document.getElementById('documentViewer');
            var fallback = document.getElementById('viewFallback');
            var downloadLink = document.getElementById('downloadLink');

            // Determine file extension from the file_view.php URL parameter
            var f = filePath.match(/[?&]f=([^&]+)/);
            var filename = f ? f[1] : '';
            var ext = '';
            try {
                ext = filename.split('.').pop().toLowerCase();
            } catch (err) {
                ext = '';
            }

            // If PDF, show iframe preview. Otherwise show download fallback.
            if (ext === 'pdf') {
                fallback.style.display = 'none';
                viewer.style.display = 'block';
                viewer.src = encodeURI(filePath);

                // Try to prevent contextmenu inside iframe where possible
                try {
                    viewer.addEventListener('load', function() {
                        try {
                            viewer.contentWindow.document.addEventListener('contextmenu', function(e) { e.preventDefault(); });
                        } catch (err) {
                            // Cross-origin — cannot access inner document; silently ignore
                        }
                    });
                    viewer.addEventListener('contextmenu', function(e) { e.preventDefault(); });
                } catch (e) {
                    // ignore
                }
            } else {
                // Non-PDF: hide iframe and show fallback with download link
                viewer.style.display = 'none';
                viewer.src = '';
                fallback.style.display = 'block';
                // Link to the actual file so clicking downloads it
                downloadLink.href = filePath;
            }

            document.getElementById('viewModal').style.display = 'block';
        }
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
            document.getElementById('documentViewer').src = '';
        }
        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this document?')) {
                document.getElementById('delete_document_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        }



        // Close modal when clicking outside
        window.onclick = function(event) {
            var statusModal = document.getElementById('statusModal');
            var uploadModal = document.getElementById('uploadModal');
            if (event.target == statusModal) statusModal.style.display = 'none';
            if (event.target == uploadModal) uploadModal.style.display = 'none';
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

        // Filter programs based on department selection in modal
        document.getElementById('modal_department_id').addEventListener('change', function() {
            const deptId = this.value;
            const programSelect = document.getElementById('modal_program_id');
            const options = programSelect.querySelectorAll('option');

            options.forEach(option => {
                if (deptId === '') {
                    // Show all programs when no department selected
                    option.style.display = 'block';
                } else {
                    // Show only programs under selected department
                    if (option.value === '' || option.getAttribute('data-dept') === deptId) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                }
            });

            programSelect.value = '';
        });

        // Filter programs based on department selection in filter form
        document.getElementById('filter_department').addEventListener('change', function() {
            const deptId = this.value;
            const programSelect = document.getElementById('filter_program');
            const options = programSelect.querySelectorAll('option');

            options.forEach(option => {
                if (deptId === '') {
                    // Show all programs when no department selected
                    option.style.display = 'block';
                } else {
                    // Show only programs under selected department
                    if (option.value === '' || option.getAttribute('data-dept') === deptId) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                }
            });

            programSelect.value = '';
        });

        // Prevent selecting program without department
        document.getElementById('filter_program').addEventListener('change', function() {
            const deptSelect = document.getElementById('filter_department');
            if (deptSelect.value === '') {
                alert('Select a department first');
                this.value = '';
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

        .modal {
            background-color: rgba(0,0,0,0.1);
        }

        .modal-body {
            background-color: white;
        }

        .modal-header h3 {
            color: white;
        }

        /* Enhanced close button for modals */
        .modal-close-btn {
            background: none;
            border: none;
            font-size: 28px;
            color: white;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .modal-close-btn:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg) scale(1.1);
        }

        .modal-close-btn:active {
            background-color: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg) scale(0.95);
        }

        .modal-close-btn i {
            pointer-events: none;
        }
    </style>
</body>
</html>
