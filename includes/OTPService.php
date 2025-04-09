<?php
require_once 'EmailService.php';

class OTPService {
    private $conn;
    private $emailService;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->emailService = new EmailService();
    }
    
    /**
     * Generate a random OTP
     * 
     * @param int $length Length of OTP (default: 6)
     * @return string Generated OTP
     */
    public function generateOTP($length = 6) {
        // Generate a random numeric OTP
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= mt_rand(0, 9);
        }
        return $otp;
    }
    
    /**
     * Store OTP in database
     * 
     * @param string $email User email
     * @param string $otp Generated OTP
     * @return bool Success status
     */
    public function storeOTP($email, $otp) {
        try {
                        // Delete any existing OTPs for this email
                        $stmt = $this->conn->prepare("DELETE FROM otp_verifications WHERE email = ?");
                        $stmt->execute([$email]);
                        
                        // Insert new OTP with expiration time (10 minutes from now)
                        $stmt = $this->conn->prepare("
                            INSERT INTO otp_verifications (email, otp, expires_at, created_at) 
                            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
                        ");
                        return $stmt->execute([$email, $otp]);
                    } catch (Exception $e) {
                        error_log("Error storing OTP: " . $e->getMessage());
                        return false;
                    }
                }
                
                /**
                 * Send OTP email
                 * 
                 * @param string $email User email
                 * @param string $otp Generated OTP
                 * @return bool Success status
                 */
                public function sendOTPEmail($email, $otp) {
                    return $this->emailService->sendOTPEmail($email, $otp);
                }
                
                /**
                 * Verify OTP
                 * 
                 * @param string $email User email
                 * @param string $otp OTP to verify
                 * @return array Result with success status and message
                 */
                public function verifyOTP($email, $otp) {
                    try {
                        // Get OTP record from database
                        $stmt = $this->conn->prepare("
                            SELECT * FROM otp_verifications 
                            WHERE email = ? AND otp = ? AND expires_at > NOW()
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $stmt->execute([$email, $otp]);
                        
                        if ($stmt->rowCount() > 0) {
                            // OTP is valid, delete it to prevent reuse
                            $stmt = $this->conn->prepare("DELETE FROM otp_verifications WHERE email = ?");
                            $stmt->execute([$email]);
                            
                            return [
                                'success' => true,
                                'message' => 'OTP verified successfully'
                            ];
                        } else {
                            // Check if OTP exists but expired
                            $stmt = $this->conn->prepare("
                                SELECT * FROM otp_verifications 
                                WHERE email = ? AND otp = ? AND expires_at <= NOW()
                            ");
                            $stmt->execute([$email, $otp]);
                            
                            if ($stmt->rowCount() > 0) {
                                return [
                                    'success' => false,
                                    'message' => 'OTP has expired. Please request a new one.'
                                ];
                            } else {
                                return [
                                    'success' => false,
                                    'message' => 'Invalid OTP. Please check and try again.'
                                ];
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error verifying OTP: " . $e->getMessage());
                        return [
                            'success' => false,
                            'message' => 'An error occurred while verifying OTP. Please try again.'
                        ];
                    }
                }
                
                /**
                 * Check if an OTP exists and is valid for a given email
                 * 
                 * @param string $email User email
                 * @return bool True if valid OTP exists
                 */
                public function hasValidOTP($email) {
                    try {
                        $stmt = $this->conn->prepare("
                            SELECT COUNT(*) FROM otp_verifications 
                            WHERE email = ? AND expires_at > NOW()
                        ");
                        $stmt->execute([$email]);
                        
                        return (int)$stmt->fetchColumn() > 0;
                    } catch (Exception $e) {
                        error_log("Error checking OTP: " . $e->getMessage());
                        return false;
                    }
                }
                
                /**
                 * Get remaining time for OTP in seconds
                 * 
                 * @param string $email User email
                 * @return int Remaining time in seconds or 0 if no valid OTP
                 */
                public function getOTPRemainingTime($email) {
                    try {
                        $stmt = $this->conn->prepare("
                            SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) as remaining_seconds
                            FROM otp_verifications 
                            WHERE email = ? AND expires_at > NOW()
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $stmt->execute([$email]);
                        
                        if ($stmt->rowCount() > 0) {
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            return max(0, (int)$result['remaining_seconds']);
                        }
                        
                        return 0;
                    } catch (Exception $e) {
                        error_log("Error getting OTP remaining time: " . $e->getMessage());
                        return 0;
                    }
                }
                
                /**
                 * Clean up expired OTPs from the database
                 * This can be called periodically to maintain database cleanliness
                 * 
                 * @return bool Success status
                 */
                public function cleanupExpiredOTPs() {
                    try {
                        $stmt = $this->conn->prepare("DELETE FROM otp_verifications WHERE expires_at <= NOW()");
                        return $stmt->execute();
                    } catch (Exception $e) {
                        error_log("Error cleaning up expired OTPs: " . $e->getMessage());
                        return false;
                    }
                }
            }
            ?>
            