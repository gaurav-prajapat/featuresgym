<?php
/**
 * Authentication check utility
 * This file checks if a user is logged in and redirects to login page if not
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) || isset($_SESSION['owner_id']) || isset($_SESSION['admin_id']);
}

/**
 * Check if user is a member
 * @return bool True if user is a member, false otherwise
 */
function isMember() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user is a gym owner
 * @return bool True if user is a gym owner, false otherwise
 */
function isGymOwner() {
    return isset($_SESSION['owner_id']);
}

/**
 * Check if user is an admin
 * @return bool True if user is an admin, false otherwise
 */
function isAdmin() {
    return isset($_SESSION['admin_id']) && $_SESSION['role'] === 'admin';
}

/**
 * Redirect to login page if user is not logged in
 * @param string $redirect_url URL to redirect to after login
 * @return void
 */
function requireLogin($redirect_url = '') {
    if (!isLoggedIn()) {
        $_SESSION['error'] = "Please log in to access this page.";
        
        if (!empty($redirect_url)) {
            $_SESSION['redirect_after_login'] = $redirect_url;
        } else {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        }
        
        header('Location: login.php');
        exit();
    }
}

/**
 * Redirect to login page if user is not a member
 * @return void
 */
function requireMember() {
    if (!isMember()) {
        $_SESSION['error'] = "This page is only accessible to members.";
        
        if (isGymOwner()) {
            header('Location: gym/dashboard.php');
        } elseif (isAdmin()) {
            header('Location: admin/index.php');
        } else {
            header('Location: login.php');
        }
        
        exit();
    }
}

/**
 * Redirect to login page if user is not a gym owner
 * @return void
 */
function requireGymOwner() {
    if (!isGymOwner()) {
        $_SESSION['error'] = "This page is only accessible to gym owners.";
        
        if (isMember()) {
            header('Location: dashboard.php');
        } elseif (isAdmin()) {
            header('Location: admin/index.php');
        } else {
            header('Location: gym/login.php');
        }
        
        exit();
    }
}

/**
 * Redirect to login page if user is not an admin
 * @return void
 */
function requireAdmin() {
    if (!isAdmin()) {
        $_SESSION['error'] = "This page is only accessible to administrators.";
        
        if (isMember()) {
            header('Location: dashboard.php');
        } elseif (isGymOwner()) {
            header('Location: gym/dashboard.php');
        } else {
            header('Location: admin/login.php');
        }
        
        exit();
    }
}

// Default behavior: Check if user is logged in
if (basename($_SERVER['PHP_SELF']) !== 'login.php' && 
    basename($_SERVER['PHP_SELF']) !== 'register.php' && 
    basename($_SERVER['PHP_SELF']) !== 'forgot_password.php' && 
    basename($_SERVER['PHP_SELF']) !== 'reset_password.php' && 
    basename($_SERVER['PHP_SELF']) !== 'index.php' && 
    !isLoggedIn()) {
    
    // Only redirect if this file is included directly, not when functions are just being defined
    if (basename($_SERVER['PHP_SELF']) !== 'auth_check.php') {
        requireLogin();
    }
}
