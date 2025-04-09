<?php
/**
 * Payment Processor Class
 * Handles payment initialization, processing, and cancellation
 */
class PaymentProcessor {
    private $conn;
    private $user_id;
    private $gym_id;
    
    /**
     * Constructor
     */
    public function __construct($conn, $user_id, $gym_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
        $this->gym_id = $gym_id;
    }
    
    /**
     * Initialize a payment
     * 
     * @param float $amount Total amount to be paid
     * @param float $base_amount Base amount before taxes/fees
     * @param string $payment_type Type of payment (membership, visit, etc.)
     * @param int|null $related_id ID of the related entity (plan_id, etc.)
     * @return int|bool Payment ID if successful, false otherwise
     */
    public function initializePayment($amount, $base_amount, $payment_type, $related_id = null) {
        try {
            // Validate gym exists and is active
            $stmt = $this->conn->prepare("SELECT status FROM gyms WHERE gym_id = ?");
            $stmt->execute([$this->gym_id]);
            $gym_status = $stmt->fetchColumn();
            
            if (!$gym_status || $gym_status !== 'active') {
                throw new Exception("Gym not found or not active");
            }
            
            // Create payment record
            $stmt = $this->conn->prepare("
                INSERT INTO payments (
                    user_id, gym_id, amount, base_amount, payment_type, 
                    related_entity_type, related_entity_id, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, 'pending', NOW()
                )
            ");
            
            $related_entity_type = null;
            switch ($payment_type) {
                case 'membership':
                    $related_entity_type = 'plan';
                    break;
                case 'visit':
                    $related_entity_type = 'schedule';
                    break;
                case 'product':
                    $related_entity_type = 'product';
                    break;
                case 'service':
                    $related_entity_type = 'service';
                    break;
                default:
                    $related_entity_type = null;
            }
            
            $stmt->execute([
                $this->user_id,
                $this->gym_id,
                $amount,
                $base_amount,
                $payment_type,
                $related_entity_type,
                $related_id
            ]);
            
            return $this->conn->lastInsertId();
        } catch (Exception $e) {
            error_log('Payment initialization error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process a completed payment
     * 
     * @param int $payment_id ID of the payment to process
     * @param array $payment_data Payment gateway response data
     * @return array Status and message
     */
    public function processPayment($payment_id, $payment_data) {
        try {
            // Begin transaction
            $this->conn->beginTransaction();
            
            // Get payment details
            $stmt = $this->conn->prepare("
                SELECT * FROM payments 
                WHERE id = ? AND user_id = ? AND gym_id = ? AND status = 'pending'
            ");
            $stmt->execute([$payment_id, $this->user_id, $this->gym_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception("Payment not found or not in pending status");
            }
            
            // For Razorpay payments, verify signature
            if (isset($payment_data['payment_method']) && $payment_data['payment_method'] === 'razorpay') {
                if (!$this->verifyRazorpaySignature($payment_data)) {
                    throw new Exception("Invalid payment signature");
                }
            }
            
            // Update payment status
            $stmt = $this->conn->prepare("
                UPDATE payments 
                SET 
                    status = 'completed',
                    transaction_id = ?,
                    payment_method = ?,
                    payment_data = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            
            $payment_data_json = json_encode($payment_data);
            
            $stmt->execute([
                $payment_data['payment_id'],
                $payment_data['payment_method'],
                $payment_data_json,
                $payment_id
            ]);
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'status' => 'success',
                'message' => 'Payment processed successfully',
                'payment_id' => $payment_id
            ];
        } catch (Exception $e) {
            // Rollback transaction
            $this->conn->rollBack();
            
            error_log('Payment processing error: ' . $e->getMessage());
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Cancel a payment
     * 
     * @param int $payment_id ID of the payment to cancel
     * @param string $reason Reason for cancellation
     * @return bool True if successful, false otherwise
     */
    public function cancelPayment($payment_id, $reason = 'Payment cancelled by user') {
        try {
            // Get payment details
            $stmt = $this->conn->prepare("
                SELECT * FROM payments 
                WHERE id = ? AND user_id = ? AND gym_id = ? AND status = 'pending'
            ");
            $stmt->execute([$payment_id, $this->user_id, $this->gym_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception("Payment not found or not in pending status");
            }
            
            // Update payment status
            $stmt = $this->conn->prepare("
                UPDATE payments 
                SET 
                    status = 'cancelled',
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$reason, $payment_id]);
            
            return true;
        } catch (Exception $e) {
            error_log('Payment cancellation error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify Razorpay payment signature
     * 
     * @param array $payment_data Payment data from Razorpay
     * @return bool True if signature is valid, false otherwise
     */
    private function verifyRazorpaySignature($payment_data) {
        if (!isset($payment_data['razorpay_payment_id']) || 
            !isset($payment_data['razorpay_order_id']) || 
            !isset($payment_data['razorpay_signature'])) {
            return false;
        }
        
        $razorpay_key_secret = $_ENV['RAZORPAY_KEY_SECRET'] ?? '';
        
        if (empty($razorpay_key_secret)) {
            error_log('Razorpay key secret not configured');
            return false;
        }
        
        // Generate signature
        $generated_signature = hash_hmac(
            'sha256', 
            $payment_data['razorpay_order_id'] . '|' . $payment_data['razorpay_payment_id'], 
            $razorpay_key_secret
        );
        
        // Verify signature
        return hash_equals($generated_signature, $payment_data['razorpay_signature']);
    }
}
