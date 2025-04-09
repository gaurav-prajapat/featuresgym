<?php
/**
 * CSRF Protection Class
 * Provides protection against Cross-Site Request Forgery attacks
 */
class CSRF {
    private $token_name = 'csrf_token';
    private $nonce_name = 'nonce';
    private $token_length = 32;
    private $token_expiry = 3600; // 1 hour
    
    /**
     * Constructor - Initialize CSRF protection
     */
    public function __construct() {
        if (!isset($_SESSION)) {
            session_start();
        }
        
        // Generate a new token if one doesn't exist or is expired
        if (!$this->hasValidToken()) {
            $this->generateNewToken();
        }
    }
    
    /**
     * Generate a new CSRF token
     */
    private function generateNewToken() {
        $token = bin2hex(random_bytes($this->token_length / 2));
        $_SESSION[$this->token_name] = [
            'value' => $token,
            'expiry' => time() + $this->token_expiry
        ];
        return $token;
    }
    
    /**
     * Check if a valid token exists
     */
    private function hasValidToken() {
        return isset($_SESSION[$this->token_name]) && 
               is_array($_SESSION[$this->token_name]) && 
               isset($_SESSION[$this->token_name]['value']) && 
               isset($_SESSION[$this->token_name]['expiry']) && 
               $_SESSION[$this->token_name]['expiry'] > time();
    }
    
    /**
     * Get the current CSRF token
     */
    public function getToken() {
        if (!$this->hasValidToken()) {
            return $this->generateNewToken();
        }
        return $_SESSION[$this->token_name]['value'];
    }
    
    /**
     * Verify a submitted token
     */
    public function verifyToken($token) {
        if (empty($token) || !$this->hasValidToken()) {
            return false;
        }
        
        // Constant time comparison to prevent timing attacks
        return hash_equals($_SESSION[$this->token_name]['value'], $token);
    }
    
    /**
     * Generate a nonce for inline scripts (for CSP)
     */
    public function getNonce() {
        if (!isset($_SESSION[$this->nonce_name])) {
            $_SESSION[$this->nonce_name] = bin2hex(random_bytes(16));
        }
        return $_SESSION[$this->nonce_name];
    }
}
