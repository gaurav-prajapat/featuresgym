<?php
class SecurityMiddleware {
    private $conn;
    private $max_login_attempts = 5;
    private $lockout_time = 1800; // 30 minutes
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    /**
     * Check for suspicious activity
     * 
     * @param string $ip_address User's IP address
     * @return bool True if suspicious, false otherwise
     */
    public function checkSuspiciousActivity($ip_address) {
        try {
            // Check if IP is locked out
            $stmt = $this->conn->prepare("
                SELECT COUNT(*) as attempts, MAX(timestamp) as last_attempt
                FROM login_attempts
                WHERE ip_address = ? AND success = 0
                AND timestamp > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ");
            $stmt->execute([$ip_address]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['attempts'] >= $this->max_login_attempts) {
                // Calculate time remaining in lockout
                $last_attempt = strtotime($result['last_attempt']);
                $lockout_ends = $last_attempt + $this->lockout_time;
                $time_remaining = $lockout_ends - time();
                
                if ($time_remaining > 0) {
                    // IP is still locked out
                    $_SESSION['error'] = "Too many failed login attempts. Please try again in " . 
                        ceil($time_remaining / 60) . " minutes.";
                    return true;
                }
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Security check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Record login attempt
     * 
     * @param string $username Username or email
     * @param string $ip_address User's IP address
     * @param bool $success Whether login was successful
     * @return bool Success status
     */
    public function recordLoginAttempt($username, $ip_address, $success) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO login_attempts (username, ip_address, success, timestamp, user_agent)
                VALUES (?, ?, ?, NOW(), ?)
            ");
            return $stmt->execute([
                $username,
                $ip_address,
                $success ? 1 : 0,
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        } catch (PDOException $e) {
            error_log("Record login attempt error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate CSRF token
     * 
     * @param string $token Token from form
     * @return bool True if valid, false otherwise
     */
    public function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Generate CSRF token
     * 
     * @return string New CSRF token
     */
    public function generateCSRFToken() {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }
    
    /**
     * Set security headers
     */
    public function setSecurityHeaders() {
        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://unpkg.com https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self';");
        
        // Prevent clickjacking
        header("X-Frame-Options: DENY");
        
        // XSS protection
        header("X-XSS-Protection: 1; mode=block");
        
        // Prevent MIME type sniffing
        header("X-Content-Type-Options: nosniff");
        
        // Referrer policy
        header("Referrer-Policy: strict-origin-when-cross-origin");
        
        // HSTS (HTTP Strict Transport Security)
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
}
