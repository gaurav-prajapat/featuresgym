<?php
ob_start();
session_start();

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Get payment ID from URL
$payment_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$payment_id) {
    die("Invalid payment ID.");
}

// Get payment details
$stmt = $conn->prepare("
    SELECT p.*, u.username, u.email, u.phone, g.name as gym_name, g.address as gym_address, 
           g.city as gym_city, g.state as gym_state, g.country as gym_country, g.zip_code as gym_zip,
           CASE WHEN um.id IS NOT NULL THEN 'Membership' ELSE 'Other' END as payment_type,
           um.plan_id, gmp.plan_name, gmp.tier, gmp.duration, um.start_date, um.end_date
    FROM payments p
    JOIN users u ON p.user_id = u.id
    JOIN gyms g ON p.gym_id = g.gym_id
    LEFT JOIN user_memberships um ON p.membership_id = um.id
    LEFT JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
    WHERE p.id = :payment_id
");
$stmt->execute([':payment_id' => $payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die("Payment not found.");
}

// Format the receipt number
$receipt_number = str_pad($payment['id'], 8, '0', STR_PAD_LEFT);

// Get company information
$company_name = "Fitness Hub";
$company_address = "123 Fitness Street, Workout City";
$company_email = "billing@fitnesshub.com";
$company_phone = "+91 9876543210";
$company_website = "www.fitnesshub.com";
$company_gst = "GSTIN: 27AABCF2641P1ZK";

// Calculate tax breakdown if applicable
$subtotal = $payment['base_amount'] ?? $payment['amount'];
$discount = $payment['discount_amount'] ?? 0;
$govt_tax = $payment['govt_tax'] ?? 0;
$gateway_tax = $payment['gateway_tax'] ?? 0;
$total = $payment['amount'];

// Set the content type to PDF
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt #<?= $receipt_number ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
            border: 1px solid #e5e7eb;
        }
        .receipt-header {
            border-bottom: 2px solid #4f46e5;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .receipt-footer {
            border-top: 1px solid #e5e7eb;
            padding-top: 20px;
            margin-top: 30px;
            font-size: 0.875rem;
            color: #6b7280;
        }
        .receipt-table {
            width: 100%;
            border-collapse: collapse;
        }
        .receipt-table th {
            background-color: #f9fafb;
            text-align: left;
            padding: 12px;
            font-weight: 600;
            color: #374151;
        }
        .receipt-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .receipt-table tr:last-child td {
            border-bottom: none;
        }
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 100px;
            color: rgba(239, 68, 68, 0.1);
            pointer-events: none;
            z-index: -1;
        }
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print {
                display: none;
            }
            .receipt-container {
                border: none;
                padding: 20px;
                max-width: 100%;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="py-8">
        <div class="receipt-container bg-white rounded-lg shadow-md relative">
            <!-- Watermark for failed/refunded payments -->
            <?php if ($payment['status'] === 'failed' || $payment['status'] === 'refunded'): ?>
            <div class="watermark">
                <?= strtoupper($payment['status']) ?>
            </div>
            <?php endif; ?>
            
            <!-- Print Button -->
            <div class="absolute top-4 right-4 no-print">
                <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                    <i class="fas fa-print mr-2"></i> Print Receipt
                </button>
            </div>
            
            <!-- Receipt Header -->
            <div class="receipt-header flex justify-between items-start">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900"><?= $company_name ?></h1>
                    <p class="text-gray-600"><?= $company_address ?></p>
                    <p class="text-gray-600"><?= $company_email ?> | <?= $company_phone ?></p>
                    <p class="text-gray-600"><?= $company_website ?></p>
                    <p class="text-gray-600"><?= $company_gst ?></p>
                </div>
                <div class="text-right">
                    <h2 class="text-xl font-bold text-indigo-600">RECEIPT</h2>
                    <p class="text-gray-600">Receipt #: <?= $receipt_number ?></p>
                    <p class="text-gray-600">Date: <?= date('d M Y', strtotime($payment['payment_date'])) ?></p>
                    <p class="text-gray-600">Time: <?= date('h:i A', strtotime($payment['payment_date'])) ?></p>
                    <?php if ($payment['transaction_id'] || $payment['payment_id']): ?>
                    <p class="text-gray-600">Transaction ID: 
                        <?php if ($payment['transaction_id']): ?>
                            <?= htmlspecialchars($payment['transaction_id']) ?>
                        <?php elseif ($payment['payment_id']): ?>
                            <?= htmlspecialchars($payment['payment_id']) ?>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    
                    <?php
                    $statusClass = '';
                    $statusText = '';
                    
                    switch ($payment['status']) {
                        case 'completed':
                            $statusClass = 'bg-green-100 text-green-800';
                            $statusText = 'PAID';
                            break;
                        case 'pending':
                            $statusClass = 'bg-yellow-100 text-yellow-800';
                            $statusText = 'PENDING';
                            break;
                        case 'failed':
                            $statusClass = 'bg-red-100 text-red-800';
                            $statusText = 'FAILED';
                            break;
                        case 'refunded':
                            $statusClass = 'bg-blue-100 text-blue-800';
                            $statusText = 'REFUNDED';
                            break;
                    }
                    ?>
                    <span class="inline-block mt-2 px-3 py-1 text-sm font-semibold rounded-full <?= $statusClass ?>">
                        <?= $statusText ?>
                    </span>
                </div>
            </div>
            
            <!-- Customer Information -->
            <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-base font-semibold text-gray-800 mb-2">Bill To:</h3>
                    <p class="font-medium"><?= htmlspecialchars($payment['username']) ?></p>
                    <p><?= htmlspecialchars($payment['email']) ?></p>
                    <?php if ($payment['phone']): ?>
                    <p><?= htmlspecialchars($payment['phone']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-800 mb-2">Gym:</h3>
                    <p class="font-medium"><?= htmlspecialchars($payment['gym_name']) ?></p>
                    <p><?= htmlspecialchars($payment['gym_address']) ?></p>
                    <p>
                        <?= htmlspecialchars($payment['gym_city']) ?>
                        <?php if ($payment['gym_state']): ?>, <?= htmlspecialchars($payment['gym_state']) ?><?php endif; ?>
                        <?php if ($payment['gym_zip']): ?> - <?= htmlspecialchars($payment['gym_zip']) ?><?php endif; ?>
                    </p>
                    <?php if ($payment['gym_country']): ?>
                    <p><?= htmlspecialchars($payment['gym_country']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Payment Details -->
            <div class="mb-8">
                <h3 class="text-base font-semibold text-gray-800 mb-4">Payment Details:</h3>
                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Details</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="font-medium">
                                <?php if ($payment['payment_type'] === 'Membership'): ?>
                                    <?= htmlspecialchars($payment['plan_name']) ?> Membership
                                <?php else: ?>
                                    Payment
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($payment['payment_type'] === 'Membership'): ?>
                                    <?= htmlspecialchars($payment['tier']) ?> - <?= htmlspecialchars($payment['duration']) ?><br>
                                    <span class="text-sm text-gray-600">
                                        Valid from <?= date('d M Y', strtotime($payment['start_date'])) ?> to <?= date('d M Y', strtotime($payment['end_date'])) ?>
                                    </span>
                                <?php else: ?>
                                    One-time payment
                                <?php endif; ?>
                            </td>
                            <td class="text-right">₹<?= number_format($subtotal, 2) ?></td>
                        </tr>
                        
                        <?php if ($discount > 0): ?>
                        <tr>
                            <td>Discount</td>
                            <td>
                                <?php if ($payment['coupon_code']): ?>
                                    Coupon: <?= htmlspecialchars($payment['coupon_code']) ?>
                                <?php else: ?>
                                    Applied Discount
                                <?php endif; ?>
                            </td>
                            <td class="text-right text-green-600">-₹<?= number_format($discount, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($govt_tax > 0): ?>
                        <tr>
                            <td>GST</td>
                            <td>Government Tax</td>
                            <td class="text-right">₹<?= number_format($govt_tax, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($gateway_tax > 0): ?>
                        <tr>
                            <td>Processing Fee</td>
                            <td>Payment Gateway Charges</td>
                            <td class="text-right">₹<?= number_format($gateway_tax, 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="text-right font-semibold">Total</td>
                            <td class="text-right font-bold text-lg">₹<?= number_format($total, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Payment Method -->
            <div class="mb-8">
                <h3 class="text-base font-semibold text-gray-800 mb-2">Payment Method:</h3>
                <p><?= $payment['payment_method'] ? htmlspecialchars(ucfirst($payment['payment_method'])) : 'N/A' ?></p>
            </div>
            
            <!-- Notes -->
            <div class="mb-8">
                <h3 class="text-base font-semibold text-gray-800 mb-2">Notes:</h3>
                <?php if ($payment['notes']): ?>
                    <p><?= nl2br(htmlspecialchars($payment['notes'])) ?></p>
                <?php else: ?>
                    <p>Thank you for your business! This receipt serves as proof of payment.</p>
                <?php endif; ?>
            </div>
            
            <!-- Receipt Footer -->
            <div class="receipt-footer text-center">
                <p>This is a computer-generated receipt and does not require a signature.</p>
                <p>For any queries regarding this receipt, please contact our support team at support@fitnesshub.com</p>
                <p>&copy; <?= date('Y') ?> <?= $company_name ?>. All rights reserved.</p>
            </div>
        </div>
        
        <!-- Back Button -->
        <div class="mt-6 text-center no-print">
            <a href="view_payment.php?id=<?= $payment_id ?>" class="inline-flex items-center text-indigo-600 hover:text-indigo-900">
                <i class="fas fa-arrow-left mr-2"></i> Back to Payment Details
            </a>
        </div>
    </div>
    
    <script>
        // Auto-print when the page loads (optional)
        window.onload = function() {
            // Uncomment the line below to automatically open the print dialog
            // window.print();
        };
    </script>
</body>
</html>

