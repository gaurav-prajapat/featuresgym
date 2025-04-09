<?php
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize messages
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error_message = "Security validation failed. Please try again.";
    } else {
        try {
            // Begin transaction
            $conn->beginTransaction();

            // Update Razorpay Key ID
            $razorpay_key_id = trim($_POST['razorpay_key_id']);
            $stmt = $conn->prepare("UPDATE payment_settings SET setting_value = ? WHERE setting_key = 'razorpay_key_id'");
            $stmt->execute([$razorpay_key_id]);

            // Update Razorpay Key Secret (encrypt if needed)
            $razorpay_key_secret = trim($_POST['razorpay_key_secret']);
            // Only update if not empty (to avoid overwriting with blank)
            if (!empty($razorpay_key_secret)) {
                $stmt = $conn->prepare("UPDATE payment_settings SET setting_value = ? WHERE setting_key = 'razorpay_key_secret'");
                $stmt->execute([$razorpay_key_secret]);
            }

            // Update payment gateway enabled status
            $payment_gateway_enabled = isset($_POST['payment_gateway_enabled']) ? 1 : 0;
            $stmt = $conn->prepare("UPDATE payment_settings SET setting_value = ? WHERE setting_key = 'payment_gateway_enabled'");
            $stmt->execute([$payment_gateway_enabled]);

            // Update test mode
            $test_mode = isset($_POST['test_mode']) ? 1 : 0;
            $stmt = $conn->prepare("UPDATE payment_settings SET setting_value = ? WHERE setting_key = 'test_mode'");
            $stmt->execute([$test_mode]);

            // Commit transaction
            $conn->commit();

            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'update_payment_settings', 'Updated payment gateway settings', ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);

            $success_message = "Payment settings updated successfully!";
        } catch (PDOException $e) {
            // Rollback transaction on error
            $conn->rollBack();
            $error_message = "Error updating payment settings: " . $e->getMessage();
        }
    }
}

// Fetch current settings
$stmt = $conn->prepare("SELECT * FROM payment_settings");
$stmt->execute();
$settings = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = "Payment Gateway Settings";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= $page_title ?> - Admin Dashboard</title>

    <!-- Preload critical assets -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">

    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- Custom styles -->
    <style>
        :root {
            --primary-color: #4e73df;
            --primary-dark: #2e59d9;
            --secondary-color: #858796;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
        }

        body {
            background-color: #f8f9fc;
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 1rem;
            line-height: 1.6;
            color: #333;
        }

        .card {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }

        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e3e6f0;
            padding: 1.25rem 1.5rem;
            border-top-left-radius: 0.75rem !important;
            border-top-right-radius: 0.75rem !important;
        }

        .card-header h6 {
            font-weight: 700;
            font-size: 1.1rem;
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-control,
        .form-select {
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d3e2;
            font-size: 0.9rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #bac8f3;
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
            margin-top: 0.25em;
        }

        .btn {
            border-radius: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-info {
            background-color: var(--info-color);
            border-color: var(--info-color);
            color: #fff;
        }

        .btn-info:hover {
            background-color: #2aa6b9;
            border-color: #2aa6b9;
            color: #fff;
        }

        .alert {
            border-radius: 0.5rem;
            border: none;
            padding: 1rem 1.5rem;
        }

        .alert-success {
            background-color: rgba(28, 200, 138, 0.15);
            border-left: 4px solid var(--success-color);
            color: #0f6848;
        }

        .alert-danger {
            background-color: rgba(231, 74, 59, 0.15);
            border-left: 4px solid var(--danger-color);
            color: #a52a1a;
        }

        .alert-info {
            background-color: rgba(54, 185, 204, 0.15);
            border-left: 4px solid var(--info-color);
            color: #1e6b76;
        }

        .test-result-container {
            border-radius: 0.5rem;
            padding: 1rem;
            margin-top: 1rem;
            transition: all 0.3s ease;
        }

        .spinner-border {
            width: 1.5rem;
            height: 1.5rem;
            border-width: 0.2em;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card-body {
                padding: 1.25rem;
            }

            .btn {
                padding: 0.625rem 1.25rem;
            }
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            body {
                background-color: #1a1c23;
                color: #e2e8f0;
            }

            .card {
                background-color: #252836;
                box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.3);
            }

            .card-header {
                background-color: #252836;
                border-bottom: 1px solid #374151;
            }

            .form-control,
            .form-select {
                background-color: #1e2130;
                border-color: #374151;
                color: #e2e8f0;
            }

            .form-control:focus,
            .form-select:focus {
                background-color: #1e2130;
                border-color: #4e73df;
                color: #e2e8f0;
            }

            .text-muted {
                color: #9ca3af !important;
            }

            .alert-info {
                background-color: rgba(54, 185, 204, 0.1);
                color: #93e1ed;
            }
        }
    </style>
</head>

<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>

    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800"><?= htmlspecialchars($page_title) ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Payment Settings</li>
                </ol>
            </nav>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle me-2"></i>
                    <div><?= htmlspecialchars($success_message) ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <div><?= htmlspecialchars($error_message) ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-credit-card me-2"></i>Razorpay Configuration
                        </h6>
                        <div class="dropdown no-arrow">
                            <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink"
                                data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-v fa-sm fa-fw text-gray-400"></i>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="dropdownMenuLink">
                                <li><a class="dropdown-item" href="https://dashboard.razorpay.com/" target="_blank">
                                        <i class="fas fa-external-link-alt me-2"></i>Razorpay Dashboard
                                    </a></li>
                                <li><a class="dropdown-item" href="https://razorpay.com/docs/" target="_blank">
                                        <i class="fas fa-book me-2"></i>Documentation
                                    </a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="#" id="test-payment-btn">
                                        <i class="fas fa-vial me-2"></i>Test Configuration
                                    </a></li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="payment-settings-form">
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token"
                                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input type="checkbox" class="form-check-input" id="payment_gateway_enabled"
                                            name="payment_gateway_enabled"
                                            <?= isset($settings['payment_gateway_enabled']) && $settings['payment_gateway_enabled']['setting_value'] == 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="payment_gateway_enabled">Enable Payment
                                            Gateway</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check form-switch mb-3">
                                        <input type="checkbox" class="form-check-input" id="test_mode" name="test_mode"
                                            <?= isset($settings['test_mode']) && $settings['test_mode']['setting_value'] == 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="test_mode">Test Mode</label>
                                    </div>
                                    <div class="form-text text-muted">
                                        <i class="fas fa-info-circle me-1"></i> When enabled, payments will be processed
                                        in test mode.
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="razorpay_key_id" class="form-label">Razorpay Key ID</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                                    <input type="text" class="form-control" id="razorpay_key_id" name="razorpay_key_id"
                                        value="<?= htmlspecialchars($settings['razorpay_key_id']['setting_value'] ?? '') ?>"
                                        autocomplete="off" spellcheck="false">
                                </div>
                                <div class="form-text text-muted">Your Razorpay API Key ID</div>
                            </div>

                            <div class="mb-4">
                                <label for="razorpay_key_secret" class="form-label">Razorpay Key Secret</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control" id="razorpay_key_secret"
                                        name="razorpay_key_secret"
                                        placeholder="<?= empty($settings['razorpay_key_secret']['setting_value']) ? 'No key set' : '••••••••••••••••' ?>"
                                        autocomplete="off" spellcheck="false">
                                    <button class="btn btn-outline-secondary" type="button" id="toggle-secret">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text text-muted">Your Razorpay API Key Secret. Leave blank to keep
                                    current value.</div>
                            </div>

                            <div class="alert alert-info d-flex align-items-center" role="alert">
                                <i class="fas fa-info-circle me-3 fs-4"></i>
                                <div>
                                    You can obtain your API keys from the <a href="https://dashboard.razorpay.com/"
                                        target="_blank" class="alert-link">Razorpay Dashboard</a>.
                                    For testing, use test mode keys that start with <code>rzp_test_</code>.
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Save Settings
                                </button>
                                <button type="button" id="inline-test-btn" class="btn btn-info">
                                    <i class="fas fa-vial me-2"></i> Test Configuration
                                </button>
                            </div>
                        </form>

                        <div id="test-result" class="test-result-container mt-4" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-info-circle me-2"></i>Payment Gateway Information
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <h5 class="fw-bold">Current Status</h5>
                            <div class="d-flex align-items-center mt-3">
                                <div class="me-3">
                                    <?php if (isset($settings['payment_gateway_enabled']) && $settings['payment_gateway_enabled']['setting_value'] == 1): ?>
                                        <span class="badge bg-success p-2"><i class="fas fa-check-circle me-1"></i>
                                            Enabled</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger p-2"><i class="fas fa-times-circle me-1"></i>
                                            Disabled</span>
                                    <?php endif; ?>
                                </div>
                                <div class="me-3">
                                    <?php if (isset($settings['test_mode']) && $settings['test_mode']['setting_value'] == 1): ?>
                                        <span class="badge bg-warning text-dark p-2"><i class="fas fa-flask me-1"></i> Test
                                            Mode</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary p-2"><i class="fas fa-globe me-1"></i> Live
                                            Mode</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h5 class="fw-bold">Key Information</h5>
                            <p class="mb-2">
                                <span class="fw-semibold">Key ID:</span>
                                <?php if (!empty($settings['razorpay_key_id']['setting_value'])): ?>
                                    <span class="text-success">Set</span>
                                <?php else: ?>
                                    <span class="text-danger">Not set</span>
                                <?php endif; ?>
                            </p>
                            <p class="mb-0">
                                <span class="fw-semibold">Key Secret:</span>
                                <?php if (!empty($settings['razorpay_key_secret']['setting_value'])): ?>
                                    <span class="text-success">Set</span>
                                <?php else: ?>
                                    <span class="text-danger">Not set</span>
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="mb-4">
                            <h5 class="fw-bold">Razorpay Resources</h5>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item bg-transparent border-bottom">
                                    <a href="https://dashboard.razorpay.com/" target="_blank"
                                        class="text-decoration-none">
                                        <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                                    </a>
                                </li>
                                <li class="list-group-item bg-transparent border-bottom">
                                    <a href="https://razorpay.com/docs/" target="_blank" class="text-decoration-none">
                                        <i class="fas fa-book me-2"></i> Documentation
                                    </a>
                                </li>
                                <li class="list-group-item bg-transparent border-bottom">
                                    <a href="https://razorpay.com/docs/payments/dashboard/settings/api-keys/"
                                        target="_blank" class="text-decoration-none">
                                        <i class="fas fa-key me-2"></i> API Keys Guide
                                    </a>
                                </li>
                                <li class="list-group-item bg-transparent">
                                    <a href="https://razorpay.com/docs/payments/payment-gateway/test-mode/"
                                        target="_blank" class="text-decoration-none">
                                        <i class="fas fa-vial me-2"></i> Test Mode Guide
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-credit-card me-2"></i>Test Cards
                        </h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-3">Use these cards for testing payments in test mode:</p>

                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Card Number</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>4111 1111 1111 1111</code></td>
                                        <td>Visa</td>
                                    </tr>
                                    <tr>
                                        <td><code>5267 3181 8797 5449</code></td>
                                        <td>Mastercard</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-2 small text-muted">
                            <p class="mb-1">For all test cards:</p>
                            <ul class="ps-3 mb-0">
                                <li>CVV: Any 3 digits</li>
                                <li>Expiry: Any future date</li>
                                <li>OTP: 1234</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Test Payment Modal -->
    <div class="modal fade" id="testPaymentModal" tabindex="-1" aria-labelledby="testPaymentModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="testPaymentModalLabel">Test Payment Configuration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-vial fa-3x text-primary mb-3"></i>
                        <h5>Verify Your Razorpay Integration</h5>
                        <p class="text-muted">This will test your API credentials by making a connection to Razorpay.
                        </p>
                    </div>

                    <div id="modal-test-result" class="alert d-none"></div>

                    <div class="d-flex justify-content-center">
                        <button id="run-test-btn" class="btn btn-primary">
                            <i class="fas fa-vial me-2"></i> Run Test
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" defer></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Toggle password visibility
            const toggleSecret = document.getElementById('toggle-secret');
            const secretInput = document.getElementById('razorpay_key_secret');

            if (toggleSecret && secretInput) {
                toggleSecret.addEventListener('click', function () {
                    const type = secretInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    secretInput.setAttribute('type', type);
                    toggleSecret.querySelector('i').classList.toggle('fa-eye');
                    toggleSecret.querySelector('i').classList.toggle('fa-eye-slash');
                });
            }

            // Test payment configuration (inline)
            const inlineTestBtn = document.getElementById('inline-test-btn');
            const testResult = document.getElementById('test-result');

            if (inlineTestBtn && testResult) {
                inlineTestBtn.addEventListener('click', function () {
                    testResult.innerHTML = '<div class="d-flex align-items-center"><div class="spinner-border text-primary me-3" role="status"><span class="visually-hidden">Loading...</span></div><div>Testing payment configuration...</div></div>';
                    testResult.style.display = 'block';
                    testResult.className = 'test-result-container mt-4 alert alert-info';

                    testPaymentConfig(testResult);
                });
            }

            // Test payment configuration (modal)
            const testPaymentBtn = document.getElementById('test-payment-btn');
            const runTestBtn = document.getElementById('run-test-btn');
            const modalTestResult = document.getElementById('modal-test-result');
            const testPaymentModal = new bootstrap.Modal(document.getElementById('testPaymentModal'));

            if (testPaymentBtn) {
                testPaymentBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    testPaymentModal.show();
                });
            }

            if (runTestBtn && modalTestResult) {
                runTestBtn.addEventListener('click', function () {
                    modalTestResult.innerHTML = '<div class="d-flex align-items-center"><div class="spinner-border text-primary me-3" role="status"><span class="visually-hidden">Loading...</span></div><div>Testing payment configuration...</div></div>';
                    modalTestResult.className = 'alert alert-info d-flex align-items-center';
                    modalTestResult.style.display = 'block';

                    testPaymentConfig(modalTestResult);
                });
            }

            // Function to test payment configuration
            function testPaymentConfig(resultElement) {
                // Get current values from form
                const keyId = document.getElementById('razorpay_key_id').value.trim();
                const testMode = document.getElementById('test_mode').checked;

                // Validate key format
                const isTestKey = keyId.startsWith('rzp_test_');
                const isLiveKey = keyId.startsWith('rzp_live_');

                if (!keyId) {
                    showTestResult(resultElement, 'error', 'Razorpay Key ID is required.');
                    return;
                }

                if (!isTestKey && !isLiveKey) {
                    showTestResult(resultElement, 'error', 'Invalid Razorpay Key ID format. Keys should start with "rzp_test_" or "rzp_live_".');
                    return;
                }

                if (testMode && !isTestKey) {
                    showTestResult(resultElement, 'warning', 'You are using a live key in test mode. For testing, use a key that starts with "rzp_test_".');
                    return;
                }

                if (!testMode && isTestKey) {
                    showTestResult(resultElement, 'warning', 'You are using a test key in live mode. For production, use a key that starts with "rzp_live_".');
                    return;
                }

                // Make AJAX request to test the configuration
                fetch('test_payment_config.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?>'
                    },
                    body: JSON.stringify({
                        key_id: keyId,
                        test_mode: testMode
                    })
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showTestResult(resultElement, 'success', data.message);
                        } else {
                            showTestResult(resultElement, 'error', data.message);
                        }
                    })
                    .catch(error => {
                        showTestResult(resultElement, 'error', 'Error testing payment configuration: ' + error.message);
                    });
            }

            // Function to show test result
            function showTestResult(element, type, message) {
                let icon, alertClass;

                switch (type) {
                    case 'success':
                        icon = '<i class="fas fa-check-circle me-2"></i>';
                        alertClass = 'alert-success';
                        break;
                    case 'error':
                        icon = '<i class="fas fa-exclamation-circle me-2"></i>';
                        alertClass = 'alert-danger';
                        break;
                    case 'warning':
                        icon = '<i class="fas fa-exclamation-triangle me-2"></i>';
                        alertClass = 'alert-warning';
                        break;
                    default:
                        icon = '<i class="fas fa-info-circle me-2"></i>';
                        alertClass = 'alert-info';
                }

                element.innerHTML = icon + message;
                element.className = element.classList.contains('test-result-container')
                    ? 'test-result-container mt-4 alert ' + alertClass
                    : 'alert ' + alertClass + ' d-flex align-items-center';
                element.style.display = 'block';
            }

            // Form validation
            const form = document.getElementById('payment-settings-form');
            if (form) {
                form.addEventListener('submit', function (event) {
                    const keyId = document.getElementById('razorpay_key_id').value.trim();
                    const keySecret = document.getElementById('razorpay_key_secret').value.trim();
                    const testMode = document.getElementById('test_mode').checked;

                    // Validate key ID
                    if (!keyId) {
                        event.preventDefault();
                        showTestResult(testResult, 'error', 'Razorpay Key ID is required.');
                        return;
                    }

                    // Validate key format
                    const isTestKey = keyId.startsWith('rzp_test_');
                    const isLiveKey = keyId.startsWith('rzp_live_');

                    if (!isTestKey && !isLiveKey) {
                        event.preventDefault();
                        showTestResult(testResult, 'error', 'Invalid Razorpay Key ID format. Keys should start with "rzp_test_" or "rzp_live_".');
                        return;
                    }

                    // Warn about test/live mode mismatch
                    if (testMode && !isTestKey) {
                        if (!confirm('You are using a live key in test mode. This is not recommended. Do you want to continue?')) {
                            event.preventDefault();
                            return;
                        }
                    }

                    if (!testMode && isTestKey) {
                        if (!confirm('You are using a test key in live mode. This is not recommended. Do you want to continue?')) {
                            event.preventDefault();
                            return;
                        }
                    }
                });
            }
        });
    </script>

</body>

</html>