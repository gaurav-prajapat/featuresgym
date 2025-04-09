<?php
class Auth {
    private $conn;
    private $session_duration = 3600; // 1 hour by default

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    /**
     * Authenticate a user
     * 
     * @param string $username Username or email
     * @param string $password Plain text password
     * @param bool $remember Remember me option
     * @return array|bool User data if authenticated, false otherwise
     */
    public function login($username, $password, $remember = false) {
        try {
            // Prepare statement to prevent SQL injection
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if user exists and password is correct
            if ($user && password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();
                
                // Set remember me cookie if requested
                if ($remember) {
                    $this->setRememberMeCookie($user['id']);
                }
                
                // Update last login timestamp
                $this->updateLastLogin($user['id']);
                
                return $user;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set remember me cookie
     * 
     * @param int $user_id User ID
     * @return bool Success status
     */
    private function setRememberMeCookie($user_id) {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + 60 * 60 * 24 * 30; // 30 days
        
        $token_hash = hash('sha256', $validator);
        
        try {
            // Delete any existing tokens for this user
            $stmt = $this->conn->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            // Insert new token
            $stmt = $this->conn->prepare("INSERT INTO auth_tokens (user_id, selector, token, expires) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $selector, $token_hash, date('Y-m-d H:i:s', $expires)]);
            
            // Set cookie
            setcookie(
                'remember_me',
                $selector . ':' . $validator,
                $expires,
                '/',
                '',
                true, // secure
                true  // httponly
            );
            
            return true;
        } catch (PDOException $e) {
            error_log("Remember me error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update last login timestamp
     * 
     * @param int $user_id User ID
     * @return bool Success status
     */
    private function updateLastLogin($user_id) {
        try {
            $stmt = $this->conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            return $stmt->execute([$user_id]);
        } catch (PDOException $e) {
            error_log("Update last login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user is logged in
     * 
     * @return bool True if logged in, false otherwise
     */
    public function isLoggedIn() {
        // Check if session exists
        if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
            // Check if session has expired
            if (time() - $_SESSION['last_activity'] > $this->session_duration) {
                $this->logout();
                return false;
            }
            
            // Update last activity
            $_SESSION['last_activity'] = time();
            return true;
        }
        
        // Check for remember me cookie
        if (isset($_COOKIE['remember_me'])) {
            return $this->loginFromCookie();
        }
        
        return false;
    }
    
    /**
     * Login from remember me cookie
     * 
     * @return bool Success status
     */
    private function loginFromCookie() {
        list($selector, $validator) = explode(':', $_COOKIE['remember_me']);
        
        try {
            $stmt = $this->conn->prepare("
                SELECT t.*, u.* 
                FROM auth_tokens t
                JOIN users u ON t.user_id = u.id
                WHERE t.selector = ? AND t.expires > NOW()
            ");
            $stmt->execute([$selector]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $tokenBin = hex2bin($validator);
                $tokenCheck = hash('sha256', $tokenBin);
                
                if (hash_equals($result['token'], $tokenCheck)) {
                    // Set session variables
                    $_SESSION['user_id'] = $result['user_id'];
                    $_SESSION['username'] = $result['username'];
                    $_SESSION['role'] = $result['role'];
                    $_SESSION['last_activity'] = time();
                    
                    // Renew the remember me cookie
                    $this->setRememberMeCookie($result['user_id']);
                    
                    return true;
                }
            }
            
            // Invalid cookie, clear it
            $this->clearRememberMeCookie();
            return false;
        } catch (PDOException $e) {
            error_log("Login from cookie error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clear remember me cookie
     */
    private function clearRememberMeCookie() {
        setcookie('remember_me', '', time() - 3600, '/');
    }
    
    /**
     * Logout user
     */
    public function logout() {
        // Clear session
        $_SESSION = array();
        
        // Clear session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        // Destroy session
        session_destroy();
        
        // Clear remember me cookie
        $this->clearRememberMeCookie();
    }
    
    /**
     * Set session duration
     * 
     * @param int $duration Duration in seconds
     */
    public function setSessionDuration($duration) {
        $this->session_duration = $duration;
    }
}
