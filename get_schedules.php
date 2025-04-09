    <?php
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Get the current page from the URL (default is page 1)
$upcoming_page = isset($_GET['upcoming_page']) ? (int) $_GET['upcoming_page'] : 1;
$past_page = isset($_GET['past_page']) ? (int) $_GET['past_page'] : 1;
$limit = 9;  // Number of results per page

// Get total number of upcoming schedules
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM schedules 
    WHERE user_id = ? 
    AND start_date >= CURDATE()
");
$stmt->execute([$user_id]);
$total_upcoming = $stmt->fetchColumn();
$total_upcoming_pages = ceil($total_upcoming / $limit);

// Get total number of past schedules
$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM schedules 
    WHERE user_id = ? 
    AND start_date < CURDATE()
");
$stmt->execute([$user_id]);
$total_past = $stmt->fetchColumn();
$total_past_pages = ceil($total_past / $limit);

// Get upcoming schedules for the current page
$upcoming_offset = ($upcoming_page - 1) * $limit;
$stmt = $conn->prepare("
    SELECT s.*, g.name as gym_name, g.address, g.city, g.state, g.zip_code
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.user_id = :user_id
    AND s.start_date >= CURDATE()
    ORDER BY s.start_date ASC, s.start_time ASC
    LIMIT :limit OFFSET :offset
");

$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $upcoming_offset, PDO::PARAM_INT);
$stmt->execute();

$upcoming_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get past schedules for the current page
$past_offset = ($past_page - 1) * $limit;
$stmt = $conn->prepare("
    SELECT s.*, g.name as gym_name, g.address, g.city, g.state, g.zip_code
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    WHERE s.user_id = :user_id
    AND s.start_date < CURDATE()
    ORDER BY s.start_date DESC, s.start_time DESC
    LIMIT :limit OFFSET :offset
");

$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $past_offset, PDO::PARAM_INT);
$stmt->execute();

$past_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare response
$response = [
    'upcoming' => [
        'schedules' => $upcoming_schedules,
        'pagination' => [
            'current_page' => $upcoming_page,
            'total_pages' => $total_upcoming_pages,
            'total_records' => $total_upcoming
        ]
    ],
    'past' => [
        'schedules' => $past_schedules,
        'pagination' => [
            'current_page' => $past_page,
            'total_pages' => $total_past_pages,
            'total_records' => $total_past
        ]
    ]
];

header('Content-Type: application/json');
echo json_encode($response);
