<?php
session_start();
require_once '../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['error'] = "Please log in to access the admin dashboard.";
    header("Location: login.php");
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$email_type = isset($_GET['email_type']) ? $_GET['email_type'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset = ($page - 1) * $limit;

// Build query
$query = "SELECT el.*, u.username 
          FROM email_logs el
          LEFT JOIN users u ON el.id = u.id
          WHERE 1=1";
$params = [];

if ($status) {
    $query .= " AND el.status = ?";
    $params[] = $status;
}

if ($email_type) {
    $query .= " AND el.email_type = ?";
    $params[] = $email_type;
}

if ($search) {
    $query .= " AND (el.recipient LIKE ? OR el.subject LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($date_from) {
    $query .= " AND DATE(el.sent_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(el.sent_at) <= ?";
    $params[] = $date_to;
}

// Count total records for pagination
$countQuery = str_replace("SELECT el.*, u.username", "SELECT COUNT(*)", $query);
$stmt = $conn->prepare($countQuery);
$stmt->execute($params);
$totalRecords = $stmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Get records with pagination
$query .= " ORDER BY el.updated_at DESC LIMIT $offset, $limit";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get email types for filter
$stmt = $conn->prepare("SELECT DISTINCT email_type FROM email_logs WHERE email_type IS NOT NULL");
$stmt->execute();
$emailTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get queue status
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM email_queue
");
$stmt->execute();
$queueStatus = $stmt->fetch(PDO::FETCH_ASSOC);

// Process queue manually if requested
$queueProcessed = false;
if (isset($_POST['process_queue']) && $_POST['process_queue'] === '1') {
    require_once '../includes/EmailService.php';
    $emailService = new EmailService();
    $result = $emailService->processQueue(50);
    $queueProcessed = true;
    
    // Refresh queue status
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
        FROM email_queue
    ");
    $stmt->execute();
    $queueStatus = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Clear old logs if requested
if (isset($_POST['clear_old_logs']) && $_POST['clear_old_logs'] === '1') {
    $days = isset($_POST['days']) ? (int)$_POST['days'] : 30;
    $stmt = $conn->prepare("DELETE FROM email_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    $clearedLogs = $stmt->rowCount();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Logs - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-sent {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-failed {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        .status-queued {
            background-color: #e0f2fe;
            color: #0369a1;
        }
        .card-counter {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            padding: 20px 10px;
            background-color: #fff;
            height: 100px;
            border-radius: 5px;
            transition: .3s linear all;
        }
        .card-counter.primary {
            background-color: #007bff;
            color: #FFF;
        }
        .card-counter.danger {
            background-color: #ef5350;
            color: #FFF;
        }
        .card-counter.success {
            background-color: #66bb6a;
            color: #FFF;
        }
        .card-counter.info {
            background-color: #26c6da;
            color: #FFF;
        }
        .card-counter i {
            font-size: 5em;
            opacity: 0.2;
        }
        .card-counter .count-numbers {
            position: absolute;
            right: 35px;
            top: 20px;
            font-size: 32px;
            display: block;
        }
        .card-counter .count-name {
            position: absolute;
            right: 35px;
            top: 65px;
            font-style: italic;
            text-transform: capitalize;
            opacity: 0.5;
            display: block;
            font-size: 18px;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="row">
            <div class="col-md-12">
                <h2><i class="fas fa-envelope me-2"></i> Email Logs</h2>
                <p class="text-muted">View and manage email logs and queue</p>
                
                <?php if (isset($queueProcessed) && $queueProcessed): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <strong>Success!</strong> Email queue processing initiated.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($clearedLogs)): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <strong>Success!</strong> Cleared <?= $clearedLogs ?> old email logs.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Email Queue Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card-counter primary">
                    <i class="fa fa-envelope-open"></i>
                    <span class="count-numbers"><?= $queueStatus['total'] ?? 0 ?></span>
                    <span class="count-name">Total Emails</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-counter info">
                    <i class="fa fa-clock"></i>
                    <span class="count-numbers"><?= $queueStatus['pending'] ?? 0 ?></span>
                    <span class="count-name">Pending</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-counter success">
                    <i class="fa fa-check-circle"></i>
                    <span class="count-numbers"><?= $queueStatus['sent'] ?? 0 ?></span>
                    <span class="count-name">Sent</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card-counter danger">
                    <i class="fa fa-exclamation-triangle"></i>
                    <span class="count-numbers"><?= $queueStatus['failed'] ?? 0 ?></span>
                    <span class="count-name">Failed</span>
                </div>
            </div>
        </div>
        
        <!-- Queue Actions -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i> Queue Management</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="mb-3">
                            <input type="hidden" name="process_queue" value="1">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-play me-2"></i> Process Queue Manually
                            </button>
                            <small class="text-muted d-block mt-2">This will process up to 50 pending emails in the queue.</small>
                        </form>
                        
                        <hr>
                        
                        <form method="post" class="d-flex align-items-center">
                            <input type="hidden" name="clear_old_logs" value="1">
                            <div class="input-group me-2">
                                <input type="number" name="days" class="form-control" value="30" min="1" max="365">
                                <span class="input-group-text">days</span>
                            </div>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash-alt me-2"></i> Clear Old Logs
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-paper-plane me-2"></i> Send Test Email</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="send_test_email.php">
                            <div class="mb-3">
                                <label for="test_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="test_email" name="test_email" required>
                            </div>
                            <button type="submit" class="btn btn-info text-white">
                                <i class="fas fa-paper-plane me-2"></i> Send Test Email
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i> Filter Email Logs</h5>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>Sent</option>
                            <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="queued" <?= $status === 'queued' ? 'selected' : '' ?>>Queued</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="email_type" class="form-label">Email Type</label>
                        <select class="form-select" id="email_type" name="email_type">
                            <option value="">All Types</option>
                            <?php foreach ($emailTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= $email_type === $type ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by email or subject...">
                    </div>
                    
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">Date From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">Date To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label for="limit" class="form-label">Records Per Page</label>
                        <select class="form-select" id="limit" name="limit">
                            <option value="20" <?= $limit === 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-2"></i> Apply Filters
                        </button>
                        <a href="email_logs.php" class="btn btn-secondary">
                            <i class="fas fa-redo me-2"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Email Logs Table -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i> Email Log Records</h5>
                <span class="badge bg-primary"><?= $totalRecords ?> Records Found</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Recipient</th>
                                <th>Subject</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>User</th>
                                <th>Date/Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($logs) > 0): ?>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= $log['id'] ?></td>
                                    <td><?= htmlspecialchars($log['recipient_email']) ?></td>
                                    <td><?= htmlspecialchars($log['subject']) ?></td>
                                    <td>
                                        <?php if ($log['email_type']): ?>
                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $log['email_type']))) ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-light text-dark">General</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['status'] === 'sent'): ?>
                                        <span class="status-badge status-sent">Sent</span>
                                        <?php elseif ($log['status'] === 'failed'): ?>
                                        <span class="status-badge status-failed">Failed</span>
                                        <?php else: ?>
                                        <span class="status-badge status-queued">Queued</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['user_id'] && $log['username']): ?>
                                        <a href="user_details.php?id=<?= $log['user_id'] ?>"><?= htmlspecialchars($log['username']) ?></a>
                                        <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info text-white view-details" 
                                                data-id="<?= $log['id'] ?>" 
                                                data-recipient="<?= htmlspecialchars($log['recipient_email']) ?>"
                                                data-subject="<?= htmlspecialchars($log['subject']) ?>"
                                                data-status="<?= htmlspecialchars($log['status']) ?>"
                                                data-error="<?= htmlspecialchars($log['error_message'] ?? '') ?>"
                                                data-bs-toggle="modal" data-bs-target="#emailDetailsModal">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <?php if ($log['status'] === 'failed'): ?>
                                        <a href="retry_email.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-warning">
                                            <i class="fas fa-redo"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No email logs found matching your criteria.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card-footer">
                <nav aria-label="Email logs pagination">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page-1 ?>&status=<?= urlencode($status) ?>&email_type=<?= urlencode($email_type) ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&limit=<?= $limit ?>">Previous</a>
                        </li>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1) {
                            echo '<li class="page-item"><a class="page-link" href="?page=1&status=' . urlencode($status) . '&email_type=' . urlencode($email_type) . '&search=' . urlencode($search) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&limit=' . $limit . '">1</a></li>';
                            if ($startPage > 2) {
                                echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                            }
                        }
                        
                        for ($i = $startPage; $i <= $endPage; $i++) {
                            echo '<li class="page-item ' . ($page == $i ? 'active' : '') . '"><a class="page-link" href="?page=' . $i . '&status=' . urlencode($status) . '&email_type=' . urlencode($email_type) . '&search=' . urlencode($search) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&limit=' . $limit . '">' . $i . '</a></li>';
                        }
                        
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) {
                                echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                            }
                            echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . '&status=' . urlencode($status) . '&email_type=' . urlencode($email_type) . '&search=' . urlencode($search) . '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . '&limit=' . $limit . '">' . $totalPages . '</a></li>';
                        }
                        ?>
                        
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page+1 ?>&status=<?= urlencode($status) ?>&email_type=<?= urlencode($email_type) ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&limit=<?= $limit ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Email Details Modal -->
    <div class="modal fade" id="emailDetailsModal" tabindex="-1" aria-labelledby="emailDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="emailDetailsModalLabel">Email Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="fw-bold">Recipient:</label>
                        <p id="modal-recipient"></p>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">Subject:</label>
                        <p id="modal-subject"></p>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold">Status:</label>
                        <p id="modal-status"></p>
                    </div>
                    <div class="mb-3" id="error-container">
                        <label class="fw-bold">Error Message:</label>
                        <div class="alert alert-danger" id="modal-error"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle view details button click
            const viewButtons = document.querySelectorAll('.view-details');
            const errorContainer = document.getElementById('error-container');
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const recipient = this.getAttribute('data-recipient');
                    const subject = this.getAttribute('data-subject');
                    const status = this.getAttribute('data-status');
                    const error = this.getAttribute('data-error');
                    
                    document.getElementById('modal-recipient').textContent = recipient;
                    document.getElementById('modal-subject').textContent = subject;
                    
                    // Set status with appropriate styling
                    const statusElement = document.getElementById('modal-status');
                    statusElement.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                    statusElement.className = '';
                    
                    if (status === 'sent') {
                        statusElement.classList.add('text-success', 'fw-bold');
                    } else if (status === 'failed') {
                        statusElement.classList.add('text-danger', 'fw-bold');
                    } else {
                        statusElement.classList.add('text-info', 'fw-bold');
                    }
                    
                    // Show/hide error message
                    if (error && status === 'failed') {
                        errorContainer.style.display = 'block';
                        document.getElementById('modal-error').textContent = error;
                    } else {
                        errorContainer.style.display = 'none';
                    }
                });
            });
        });
    </script>
</body>
</html>

