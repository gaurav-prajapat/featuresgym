<?php
/**
 * Payment Gateway Integration Class
 * 
 * This class handles integration with various payment gateways for processing payouts
 */
class PaymentGateway {
    private $gateway;
    private $config;
    private $conn;
    
    /**
     * Constructor
     * 
     * @param string $gateway The payment gateway to use (razorpay, payu, etc.)
     * @param PDO $conn Database connection
     */
    public function __construct($gateway, $conn) {
        $this->gateway = $gateway;
        $this->conn = $conn;
        $this->loadConfig();
    }
    
    
  /**
 * Load gateway configuration from database
 */
private function loadConfig() {
    try {
        $stmt = $this->conn->prepare("
            SELECT setting_key, setting_value, is_encrypted 
            FROM payment_settings 
            WHERE setting_key LIKE ?
        ");
        $stmt->execute([$this->gateway . '_%']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->config = [];
        foreach ($results as $row) {
            $key = str_replace($this->gateway . '_', '', $row['setting_key']);
            // Check if is_encrypted key exists before using it
            $isEncrypted = isset($row['is_encrypted']) ? (bool)$row['is_encrypted'] : false;
            $value = $isEncrypted ? $this->decrypt($row['setting_value']) : $row['setting_value'];
            $this->config[$key] = $value;
        }
    } catch (PDOException $e) {
        throw new Exception("Failed to load payment gateway configuration: " . $e->getMessage());
    }
}

    
    /**
     * Process a payout
     * 
     * @param array $withdrawal Withdrawal data
     * @return array Result with success status and details
     */
    public function processPayout($withdrawal) {
        switch ($this->gateway) {
            case 'razorpay':
                return $this->processRazorpayPayout($withdrawal);
            case 'payu':
                return $this->processPayUPayout($withdrawal);
            case 'manual':
                return ['success' => false, 'message' => 'Manual processing required'];
            default:
                return ['success' => false, 'message' => 'Unsupported payment gateway'];
        }
    }
    
    /**
     * Process payout via Razorpay
     * 
     * @param array $withdrawal Withdrawal data
     * @return array Result with success status and details
     */
    private function processRazorpayPayout($withdrawal) {
        // In a production environment, you would use the Razorpay SDK
        require_once 'vendor/autoload.php';
        $api = new Razorpay\Api\Api($this->config['key_id'], $this->config['key_secret']);
        
        try {
            $methodType = $withdrawal['method_type'];
            $amount = $withdrawal['amount'] * 100; // Convert to paise
            
            if ($methodType === 'bank') {
                // Example of how you would create a payout with the Razorpay SDK
                /*
                $payout = $api->payout->create([
                    'account_number' => $this->config['account_number'],
                    'fund_account_id' => $this->createFundAccount($withdrawal),
                    'amount' => $amount,
                    'currency' => 'INR',
                    'mode' => 'NEFT',
                    'purpose' => 'payout',
                    'queue_if_low_balance' => true,
                    'reference_id' => 'payout_' . $withdrawal['id'],
                    'narration' => 'Gym payout - ' . $withdrawal['gym_name']
                ]);
                
                return [
                    'success' => true,
                    'transaction_id' => $payout->id,
                    'message' => 'Payout processed successfully via Razorpay'
                ];
                */
                
                // For testing/demo purposes, simulate a successful payout
                return [
                    'success' => true,
                    'transaction_id' => 'rzp_payout_' . time() . '_' . $withdrawal['id'],
                    'message' => 'Payout processed successfully via Razorpay'
                ];
            } 
            else if ($methodType === 'upi') {
                // Similar implementation for UPI payouts
                return [
                    'success' => true,
                    'transaction_id' => 'rzp_upi_' . time() . '_' . $withdrawal['id'],
                    'message' => 'UPI payout processed successfully via Razorpay'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Unsupported payment method type for Razorpay'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Razorpay error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Process payout via PayU
     * 
     * @param array $withdrawal Withdrawal data
     * @return array Result with success status and details
     */
    private function processPayUPayout($withdrawal) {
        // Implementation for PayU payouts
        // Similar to Razorpay but with PayU's API
        
        // For testing/demo purposes
        return [
            'success' => true,
            'transaction_id' => 'payu_' . time() . '_' . $withdrawal['id'],
            'message' => 'Payout processed successfully via PayU'
        ];
    }
    
    /**
     * Create a fund account in Razorpay (for bank account payouts)
     * 
     * @param array $withdrawal Withdrawal data
     * @return string Fund account ID
     */
    private function createFundAccount($withdrawal) {
        // In a production environment with Razorpay SDK:
        /*
        $contact = $this->api->contact->create([
            'name' => $withdrawal['account_name'],
            'email' => $withdrawal['owner_email'],
            'type' => 'vendor',
            'reference_id' => 'owner_' . $withdrawal['owner_id']
        ]);
        
        $fundAccount = $this->api->fundAccount->create([
            'contact_id' => $contact->id,
            'account_type' => 'bank_account',
            'bank_account' => [
                'name' => $withdrawal['account_name'],
                'ifsc' => $withdrawal['ifsc_code'],
                'account_number' => $withdrawal['account_number']
            ]
        ]);
        
        return $fundAccount->id;
        */
        
        // For testing/demo purposes
        return 'fa_demo_' . md5($withdrawal['account_number'] . $withdrawal['ifsc_code']);
    }
    
    /**
     * Decrypt sensitive data
     * 
     * @param string $data Encrypted data
     * @return string Decrypted data
     */
    private function decrypt($data) {
        // In a production environment, implement proper decryption
        // This is a placeholder
        return $data;
    }
    
    /**
     * Get available payment gateways
     * 
     * @return array List of available payment gateways
     */
    public static function getAvailableGateways() {
        return [
            'razorpay' => 'Razorpay',
            'payu' => 'PayU',
            'manual' => 'Manual Processing'
        ];
    }
    
    /**
     * Check if a gateway is properly configured
     * 
     * @param string $gateway Gateway name
     * @param PDO $conn Database connection
     * @return bool True if configured, false otherwise
     */
    public static function isGatewayConfigured($gateway, $conn) {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) 
                FROM payment_settings 
                WHERE setting_key LIKE ?
            ");
            $stmt->execute([$gateway . '_%']);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

