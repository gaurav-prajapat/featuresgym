<?php

require_once __DIR__ . '/../config/database.php';

class Auth
{
    private $conn;
    private $max_login_attempts = 5;
    private $lockout_time = 900; // 15 minutes
    private $session_duration = 3600; // 1 hour default session duration

    public function __construct($db)
    {
        $this->conn = $db;
    }

    private function validatePassword($password)
    {
        return preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/", $password);
    }

    private function sanitizeInput($data)
    {
        return htmlspecialchars(strip_tags(trim($data)));
    }

    /**
     * Register a new regular user (member)
     */
    public function register($username, $email, $password, $phone = null, $role = 'member', $is_google_auth = false) {
        try {
            // Check if username or email already exists
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            
            if ($stmt->rowCount() > 0) {
                return false; // User already exists
            }
            
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Prepare SQL statement
            $stmt = $this->conn->prepare("
                INSERT INTO users (username, email, password, role, phone, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ");
            
            $result = $stmt->execute([$username, $email, $hashed_password, $role, $phone]);
            
            if ($result) {
                // Log the registration
                $user_id = $this->conn->lastInsertId();
                $this->logActivity($user_id, 'registration', $is_google_auth ? 'User registered via Google' : 'User registered directly');
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Register a new gym owner
     */
    public function registerGymOwner($name, $email, $phone, $password, $address, $city, $state, $country, $zip_code) {
        try {
            // Check if email already exists
            $stmt = $this->conn->prepare("SELECT * FROM gym_owners WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                return false; // Email already exists
            }
            
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Prepare SQL statement
            $stmt = $this->conn->prepare("
                INSERT INTO gym_owners (
                    name, email, phone, password_hash, address, city, state, country, zip_code, 
                    is_verified, is_approved, created_at, terms_agreed
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), 1)
            ");
            
            $result = $stmt->execute([
                $name, $email, $phone, $hashed_password, $address, $city, $state, $country, $zip_code
            ]);
            
            if ($result) {
                $owner_id = $this->conn->lastInsertId();
                // Log the registration (using a different table for gym owners)
                $this->logGymOwnerActivity($owner_id, 'registration', 'Gym owner registered');
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Gym owner registration error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
 * Login for regular users (members and admins)
 */
public function login($email, $password)
{
    try {
        $email = filter_var($this->sanitizeInput($email), FILTER_VALIDATE_EMAIL);

        if ($this->isAccountLocked($email)) {
            throw new Exception("Account is locked. Please try again later.");
        }

        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->incrementLoginAttempts($email);
            return false;
        }

        if (password_verify($password, $user['password'])) {
            $this->resetLoginAttempts($email);
            
            // Start a clean session
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            
            // Set common session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['last_activity'] = time();
            $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
            
            // Role-specific session initialization
            switch ($user['role']) {
                case 'admin':
                    $this->initializeAdminSession($user);
                    break;
                case 'member':
                    $this->initializeMemberSession($user);
                    break;
                default:
                    // Basic session for other roles
                    break;
            }
            
            // Log the login
            $this->logActivity($user['id'], 'login', 'User logged in');
            return true;
        }

        $this->incrementLoginAttempts($email);
        return false;
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        return false;
    }
}

// /**
//  * Login for gym owners
//  */
// public function loginGymOwner($email, $password)
// {
//     try {
//         $email = filter_var($this->sanitizeInput($email), FILTER_VALIDATE_EMAIL);

//         if ($this->isAccountLocked($email)) {
//             throw new Exception("Account is locked. Please try again later.");
//         }

//         $stmt = $this->conn->prepare("
//             SELECT * FROM gym_owners 
//             WHERE email = ? AND status = 'active' AND is_verified = 1 AND is_approved = 1 
//             LIMIT 1
//         ");
//         $stmt->execute([$email]);
//         $owner = $stmt->fetch(PDO::FETCH_ASSOC);

//         if (!$owner) {
//             $this->incrementLoginAttempts($email);
//             return false;
//         }

//         if (password_verify($password, $owner['password_hash'])) {
//             $this->resetLoginAttempts($email);
            
//             // Start a clean session
//             if (session_status() === PHP_SESSION_ACTIVE) {
//                 session_regenerate_id(true);
//             }
            
//             // Set gym owner session variables
//             $_SESSION['owner_id'] = $owner['id'];
//             $_SESSION['owner_name'] = $owner['name'];
//             $_SESSION['owner_email'] = $owner['email'];
//             $_SESSION['owner_phone'] = $owner['phone'];
//             $_SESSION['owner_address'] = $owner['address'];
//             $_SESSION['owner_city'] = $owner['city'];
//             $_SESSION['owner_state'] = $owner['state'];
//             $_SESSION['owner_country'] = $owner['country'];
//             $_SESSION['owner_zip_code'] = $owner['zip_code'];
//             $_SESSION['owner_profile_picture'] = $owner['profile_picture'];
//             $_SESSION['owner_gym_limit'] = $owner['gym_limit'];
//             $_SESSION['owner_account_type'] = $owner['account_type'];
//             $_SESSION['last_activity'] = time();
//             $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
            
//             // Initialize gym data
//             $this->initializeGymOwnerSession($owner['id']);
            
//             // Log the login
//             $this->logGymOwnerActivity($owner['id'], 'login', 'Gym owner logged in');
//             return true;
//         }

//         $this->incrementLoginAttempts($email);
//         return false;
//     } catch (Exception $e) {
//         error_log("Gym owner login error: " . $e->getMessage());
//         return false;
//     }
// }

    
    /**
     * Login for gym owners
     */
    public function loginGymOwner($email, $password)
    {
        try {
            $email = filter_var($this->sanitizeInput($email), FILTER_VALIDATE_EMAIL);

            if ($this->isAccountLocked($email)) {
                throw new Exception("Account is locked. Please try again later.");
            }

            $stmt = $this->conn->prepare("
                SELECT * FROM gym_owners 
                WHERE email = ? AND status = 'active' AND is_verified = 1 AND is_approved = 1 
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$owner) {
                $this->incrementLoginAttempts($email);
                return false;
            }

            if (password_verify($password, $owner['password_hash'])) {
                $this->resetLoginAttempts($email);
                
                // Start a clean session
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                
                // Set gym owner session variables
                $_SESSION['owner_id'] = $owner['id'];
                $_SESSION['owner_name'] = $owner['name'];
                $_SESSION['owner_email'] = $owner['email'];
                $_SESSION['owner_phone'] = $owner['phone'];
                $_SESSION['owner_address'] = $owner['address'];
                $_SESSION['owner_city'] = $owner['city'];
                $_SESSION['owner_state'] = $owner['state'];
                $_SESSION['owner_country'] = $owner['country'];
                $_SESSION['owner_zip_code'] = $owner['zip_code'];
                $_SESSION['owner_profile_picture'] = $owner['profile_picture'];
                $_SESSION['owner_gym_limit'] = $owner['gym_limit'];
                $_SESSION['owner_account_type'] = $owner['account_type'];
                $_SESSION['last_activity'] = time();
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
                
                // Initialize gym data
                $this->initializeGymOwnerSession($owner['id']);
                
                // Log the login
                $this->logGymOwnerActivity($owner['id'], 'login', 'Gym owner logged in');
                return true;
            }

            $this->incrementLoginAttempts($email);
            return false;
        } catch (Exception $e) {
            error_log("Gym owner login error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Initialize session for admin users
     */
    private function initializeAdminSession($user)
    {
        $_SESSION['admin_id'] = $user['id'];
        
        // Get admin permissions if applicable
        // This would depend on your admin permissions structure
    }
    
    /**
     * Initialize session for regular members
     */
    private function initializeMemberSession($user)
    {
        // Get user balance
        $stmt = $this->conn->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $balance = $stmt->fetchColumn();
        $_SESSION['user_balance'] = $balance ?? 0;
        
        // Get active memberships count
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) FROM user_memberships 
            WHERE user_id = ? AND status = 'active' AND payment_status = 'paid'
            AND CURRENT_DATE BETWEEN start_date AND end_date
        ");
        $stmt->execute([$user['id']]);
        $_SESSION['active_memberships_count'] = $stmt->fetchColumn();
    }
    
    /**
     * Initialize session for gym owners
     */
    private function initializeGymOwnerSession($owner_id)
    {
        // Get gym information
        $stmt = $this->conn->prepare("SELECT * FROM gyms WHERE owner_id = ?");
        $stmt->execute([$owner_id]);
        $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($gyms)) {
            // Store primary gym info
            $primaryGym = $gyms[0];
            $_SESSION['gym_id'] = $primaryGym['gym_id'];
            $_SESSION['gym_name'] = $primaryGym['name'];
            $_SESSION['gym_email'] = $primaryGym['email'];
            $_SESSION['gym_phone'] = $primaryGym['phone'];
            $_SESSION['gym_address'] = $primaryGym['address'];
            $_SESSION['gym_city'] = $primaryGym['city'];
            $_SESSION['gym_state'] = $primaryGym['state'];
            $_SESSION['gym_country'] = $primaryGym['country'];
            $_SESSION['gym_postal_code'] = $primaryGym['zip_code'];
            $_SESSION['gym_capacity'] = $primaryGym['capacity'];
            $_SESSION['gym_current_occupancy'] = $primaryGym['current_occupancy'];
            $_SESSION['gym_amenities'] = $primaryGym['amenities'];
            $_SESSION['gym_status'] = $primaryGym['status'];
            
            // Store all gyms for multi-gym owners
            if (count($gyms) > 1) {
                $_SESSION['owner_gyms'] = array_map(function($gym) {
                    return [
                        'gym_id' => $gym['gym_id'],
                        'name' => $gym['name'],
                        'status' => $gym['status']
                    ];
                }, $gyms);
            }
        }
    }

    private function isAccountLocked($email)
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as attempts, MAX(attempt_time) as last_attempt 
            FROM login_attempts 
            WHERE email = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$email, $this->lockout_time]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return false;
        }

        return (int)$result['attempts'] >= $this->max_login_attempts;
    }

    private function incrementLoginAttempts($email)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO login_attempts (email, attempt_time, ip_address) 
            VALUES (?, CURRENT_TIMESTAMP, ?)
        ");
        $stmt->execute([$email, $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    private function resetLoginAttempts($email)
    {
        $stmt = $this->conn->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->execute([$email]);
    }

    /**
     * Logout for all user types
     */
    public function logout()
    {
        // Log the logout activity
        if (isset($_SESSION['user_id'])) {
            $this->logActivity($_SESSION['user_id'], 'logout', 'User logged out');
        } else if (isset($_SESSION['owner_id'])) {
            $this->logGymOwnerActivity($_SESSION['owner_id'], 'logout', 'Gym owner logged out');
        }
        
        // Clear all session data
        $_SESSION = array();
        
        // Delete the session cookie
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
        
        // Destroy the session
        session_destroy();
        
        // Start a new session to allow for flash messages
        session_start();
        
        return true;
    }

    /**
     * Check if user is authenticated and session is valid
     */
    public function isAuthenticated()
    {
        return (isset($_SESSION['user_id']) || isset($_SESSION['owner_id'])) && $this->checkSessionTimeout();
    }

    /**
     * Check if session has timed out
     */
    private function checkSessionTimeout()
    {
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }
        
        if (time() - $_SESSION['last_activity'] > $this->session_duration) {
            $this->logout();
            return false;
        }
        
        // Update last activity time
        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * Check if user has specific role
     */
    public function hasRole($role)
    {
        if ($role === 'gym_partner') {
            return isset($_SESSION['owner_id']);
        }
        
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }

    /**
     * Switch active gym for multi-gym owners
     */
    public function switchGym($gym_id)
    {
        if (!isset($_SESSION['owner_id'])) {
            return false;
        }
        
        // Check if gym belongs to this owner
        $stmt = $this->conn->prepare("SELECT * FROM gyms WHERE gym_id = ? AND owner_id = ?");
        $stmt->execute([$gym_id, $_SESSION['owner_id']]);
        $gym = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$gym) {
            return false;
        }
        
        // Update session with new gym details
        $_SESSION['gym_id'] = $gym['gym_id'];
        $_SESSION['gym_name'] = $gym['name'];
        $_SESSION['gym_email'] = $gym['email'];
        $_SESSION['gym_phone'] = $gym['phone'];
        $_SESSION['gym_address'] = $gym['address'];
        $_SESSION['gym_city'] = $gym['city'];
        $_SESSION['gym_state'] = $gym['state'];
        $_SESSION['gym_country'] = $gym['country'];
        $_SESSION['gym_postal_code'] = $gym['zip_code'];
        $_SESSION['gym_capacity'] = $gym['capacity'];
        $_SESSION['gym_current_occupancy'] = $gym['current_occupancy'];
        $_SESSION['gym_amenities'] = $gym['amenities'];
        $_SESSION['gym_status'] = $gym['status'];
        
        return true;
    }

    /**
     * Log user activity
     */
    private function logActivity($user_id, $action, $details)
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt = $this->conn->prepare("
                INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent, created_at) 
                VALUES (?, 'member', ?, ?, ?, ?, NOW())
            ");

            return $stmt->execute([$user_id, $action, $details, $ip, $user_agent]);
        } catch (PDOException $e) {
            error_log("Activity log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log gym owner activity
     */
    private function logGymOwnerActivity($owner_id, $action, $details)
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt = $this->conn->prepare("
                INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent, created_at) 
                VALUES (?, 'owner', ?, ?, ?, ?, NOW())
            ");

            return $stmt->execute([$owner_id, $action, $details, $ip, $user_agent]);
        } catch (PDOException $e) {
            error_log("Activity log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user is a gym owner
     */
    public function isGymOwner()
    {
        return isset($_SESSION['owner_id']);
    }
    
    /**
     * Check if user is a regular member
     */
    public function isMember()
    {
        return isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'member';
    }
    
    /**
     * Check if user is an admin
     */
    public function isAdmin()
    {
        return isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    
    /**
     * Get current user type
     */
    public function getUserType()
    {
        if (isset($_SESSION['owner_id'])) {
            return 'gym_owner';
        } elseif (isset($_SESSION['user_id'])) {
            return $_SESSION['role'];
        }
        return null;
    }
    
    /**
     * Get current user ID (either member or owner)
     */
    public function getCurrentUserId()
    {
        if (isset($_SESSION['owner_id'])) {
            return $_SESSION['owner_id'];
        } elseif (isset($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        return null;
    }
}

