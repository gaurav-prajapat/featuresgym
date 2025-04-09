<?php
// Fetch logo path from system settings
$logoQuery = "SELECT setting_value FROM system_settings WHERE setting_key = 'logo_path'";
$logoStmt = $conn->prepare($logoQuery);
$logoStmt->execute();
$logoPath = $logoStmt->fetchColumn();

// Use default logo if no custom logo is set
$logoSrc = !empty($logoPath) ? "../" . $logoPath : "../assets/images/logo.png";
?>
<div class="fixed inset-y-0 left-0 w-64 bg-gray-800 shadow-lg z-10 overflow-y-auto" style="scrollbar-width: thin; scrollbar-color: #4B5563 #1F2937;">
   
<div class="flex items-center justify-center h-16 bg-gray-900 sticky top-0 z-10">
    <a href="dashboard.php" class="flex items-center">
        <img src="<?= htmlspecialchars($logoSrc) ?>" alt="FlexFit Logo" class="h-8 w-auto mr-2">
        <span class="text-white text-xl font-bold">FlexFit Admin</span>
    </a>
</div>
    
    <div class="px-4 py-6">
        <div class="mb-6">
            <div class="flex items-center mb-3">
                <?php if (isset($_SESSION['admin_name'])): ?>
                    <div class="w-10 h-10 rounded-full bg-yellow-500 flex items-center justify-center text-gray-900 font-bold text-lg">
                        <?php echo substr($_SESSION['admin_name'], 0, 1); ?>
                    </div>
                    <div class="ml-3">
                        <p class="text-white font-medium"><?php echo htmlspecialchars($_SESSION['admin_name']); ?></p>
                        <p class="text-gray-400 text-sm">Administrator</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <nav class="space-y-1">
            <!-- Dashboard -->
            <a href="index.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'bg-gray-700' : ''; ?>">
                <i class="fas fa-tachometer-alt w-5 h-5 mr-3 text-gray-400"></i>
                <span>Dashboard</span>
            </a>
            
            <!-- Gym Management -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">Gym Management</h3>
                
                <a href="gyms.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'gyms.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-dumbbell w-5 h-5 mr-3 text-gray-400"></i>
                    <span>All Gyms</span>
                </a>
                
                <a href="pending_gyms.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'pending_gyms.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-clock w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Pending Approvals</span>
                    <?php
                    // Count pending gyms
                    $pendingGymsStmt = $conn->prepare("SELECT COUNT(*) FROM gyms WHERE status = 'pending'");
                    $pendingGymsStmt->execute();
                    $pendingGymsCount = $pendingGymsStmt->fetchColumn();
                    
                    if ($pendingGymsCount > 0):
                    ?>
                    <span class="ml-auto bg-yellow-500 text-black text-xs px-2 py-1 rounded-full">
                        <?php echo $pendingGymsCount; ?>
                    </span>
                    <?php endif; ?>
                </a>
                
                <a href="gym_categories.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'gym_categories.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-tags w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Gym Categories</span>
                </a>
                
                <a href="manage_gym.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_gym.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-cogs w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Manage Gyms</span>
                </a>
                
                <a href="insert_gym_policies.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'insert_gym_policies.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-clipboard-list w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Gym Policies</span>
                </a>
            </div>
            
            <!-- User Management -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">User Management</h3>
                
                <a href="members.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'members.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-users w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Members</span>
                </a>
                
                <a href="gym_owners.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'gym_owners.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-user-tie w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Gym Owners</span>
                </a>
                
                <a href="trainers.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'trainers.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-user-ninja w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Trainers</span>
                </a>
                
                <a href="users.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-user w-5 h-5 mr-3 text-gray-400"></i>
                    <span>All Users</span>
                </a>
                
                <a href="user_roles.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'user_roles.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-user-shield w-5 h-5 mr-3 text-gray-400"></i>
                    <span>User Roles</span>
                </a>
            </div>
            
            <!-- Content Management -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">Content Management</h3>
            
            <a href="tournaments.php" class="flex items-center px-4 py-3 text-white rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'tournaments.php' ? 'bg-gray-700' : 'hover:bg-gray-700'; ?>">
                <i class="fas fa-trophy w-5 h-5 mr-3"></i>
                <span>Tournaments</span>
            </a>
                
                <a href="pending_reviews.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'pending_reviews.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-star w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Pending Reviews</span>
                    <?php
                    // Count pending reviews
                    $pendingReviewsStmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
                    $pendingReviewsStmt->execute();
                    $pendingReviewsCount = $pendingReviewsStmt->fetchColumn();
                    
                    if ($pendingReviewsCount > 0):
                    ?>
                    <span class="ml-auto bg-yellow-500 text-black text-xs px-2 py-1 rounded-full">
                        <?php echo $pendingReviewsCount; ?>
                    </span>
                    <?php endif; ?>
                </a>
                
                <a href="reviews.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'reviews.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-comments w-5 h-5 mr-3 text-gray-400"></i>
                    <span>All Reviews</span>
                </a>
                
                <a href="amenities.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'amenities.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-spa w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Amenities</span>
                </a>
                
                <a href="equipment.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'equipment.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-dumbbell w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Equipment</span>
                </a>
                
                <a href="blog_posts.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'blog_posts.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-blog w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Blog Posts</span>
                </a>
                
                <a href="manage_footer.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_footer.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-shoe-prints w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Manage Footer</span>
                </a>
            </div>
            
            <!-- Financial Management -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">Financial Management</h3>
                
                <a href="transactions.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'transactions.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-money-bill-wave w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Transactions</span>
                </a>
                
                <a href="payouts.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'payouts.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-hand-holding-usd w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Payouts</span>
                </a>
                
                <a href="add-cutoff.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'add-cutoff.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-percentage w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Commission Settings</span>
                </a>
                
                <a href="cut-off-chart.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'cut-off-chart.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-chart-pie w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Cut Off Chart</span>
                </a>
                
                <a href="update-cut-off.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'update-cut-off.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-edit w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Update Cut Offs</span>
                </a>
                
                <a href="manage_withdrawals.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_withdrawals.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-money-check-alt w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Manage Withdrawals</span>
                </a>
                
                <a href="financial_reports.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'financial_reports.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-chart-line w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Financial Reports</span>
                </a>
                <a href="reports.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'financial_reports.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-chart-line w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Reports</span>
                </a>
            </div>
            
            <!-- Booking Management -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">Booking Management</h3>
                
                <a href="all_bookings.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'all_bookings.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-calendar-alt w-5 h-5 mr-3 text-gray-400"></i>
                    <span>All Bookings</span>
                </a>
                
                <a href="class_bookings.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'class_bookings.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-users-class w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Class Bookings</span>
                </a>
                
                <a href="manage_classes.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_classes.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Manage Classes</span>
                </a>
            </div>
            
            <!-- Membership Management -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">Membership Management</h3>
                
                <a href="membership_plans.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'membership_plans.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-id-card w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Membership Plans</span>
                </a>
                
                <a href="active_memberships.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'active_memberships.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-user-check w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Active Memberships</span>
                </a>
                
                <a href="expired_memberships.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'expired_memberships.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-user-times w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Expired Memberships</span>
                </a>
            </div>
            
            <!-- Marketing -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">Marketing</h3>
                
                <a href="promotions.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'promotions.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-bullhorn w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Promotions</span>
                </a>
                
                <a href="coupons.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'coupons.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-ticket-alt w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Coupons</span>
                </a>
                
                <a href="email_campaigns.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'email_campaigns.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-envelope-open-text w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Email Campaigns</span>
                </a>
                
                <a href="notifications.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-bell w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Notifications</span>
                </a>
            </div>
            
            <!-- System Settings -->
            <div class="py-2">
                <h3 class="text-xs uppercase text-gray-500 font-semibold px-4 mb-2">System</h3>
                
                <a href="settings.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-cog w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Settings</span>
                </a>
                <a href="payment_settings.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'payment_settings.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-credit-card  w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Payments Settings</span>
                </a>
                
                <a href="activity_logs.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'activity_logs.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-history w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Activity Logs</span>
                </a>
                
                <a href="system_logs.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'system_logs.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-exclamation-triangle w-5 h-5 mr-3 text-gray-400"></i>
                    <span>System Logs</span>
                </a>
                
                <a href="backup.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-database w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Backup & Restore</span>
                </a>
                <a href="database_manager.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'bg-gray-700' : ''; ?>">
                    <i class="fas fa-database w-5 h-5 mr-3 text-gray-400"></i>
                    <span>Manage database</span>
                </a>
            </div>
            
            <!-- Logout -->
            <a href="logout.php" class="flex items-center px-4 py-3 text-white rounded-lg hover:bg-red-600 transition-colors duration-200">
                <i class="fas fa-sign-out-alt w-5 h-5 mr-3 text-gray-400"></i>
                <span>Logout</span>
            </a>
        </nav>
    </div>
</div>

<!-- Mobile menu button -->
<div class="lg:hidden fixed top-0 left-0 z-20 p-4">
    <button id="mobile-menu-button" class="text-white focus:outline-none">
        <i class="fas fa-bars text-2xl"></i>
    </button>
</div>

<script>
    // Mobile menu toggle
    document.getElementById('mobile-menu-button')?.addEventListener('click', function() {
        const sidebar = document.querySelector('.fixed.inset-y-0.left-0');
        sidebar.classList.toggle('-translate-x-full');
        sidebar.classList.toggle('transform');
        sidebar.classList.toggle('transition-transform');
    });
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.querySelector('.fixed.inset-y-0.left-0');
        const mobileButton = document.getElementById('mobile-menu-button');
        
        if (window.innerWidth < 1024 && 
            !sidebar.contains(event.target) && 
            !mobileButton.contains(event.target) &&
            !sidebar.classList.contains('-translate-x-full')) {
            sidebar.classList.add('-translate-x-full', 'transform', 'transition-transform');
        }
    });
    
    // Add scrollbar styling
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.querySelector('.fixed.inset-y-0.left-0');
        
        // Check if scrollbar is needed
        if (sidebar.scrollHeight > sidebar.clientHeight) {
            sidebar.classList.add('overflow-y-auto');
        }
    });

     // Mobile menu toggle
     const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    menuToggle.addEventListener('click', () => {
        if (sidebar.classList.contains('-translate-x-full')) {
            sidebar.classList.remove('-translate-x-full');
        } else {
            sidebar.classList.add('-translate-x-full');
        }
    });
    
    // Hide sidebar on mobile by default
    if (window.innerWidth < 1024) {
        sidebar.classList.add('-translate-x-full');
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth < 1024 && 
            !sidebar.contains(e.target) && 
            e.target !== menuToggle && 
            !menuToggle.contains(e.target)) {
            sidebar.classList.add('-translate-x-full');
        }
    });
</script>

