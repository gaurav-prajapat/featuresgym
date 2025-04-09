<?php
require_once '../config/database.php';
include '../includes/navbar.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

$ownerId = isset($_GET['id']) ? intval($_GET['id']) : 0; // Sanitize input

if ($ownerId <= 0) {
    header('Location: gym-owners.php'); // Redirect if no owner ID is provided
    exit();
}

// Fetch owner details
$stmt = $conn->prepare("SELECT * FROM gym_owners WHERE id = :ownerId");
$stmt->execute(['ownerId' => $ownerId]);
$owner = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    echo "Owner not found.";
    exit();
}

// Fetch single gym details
$gymQuery = "
SELECT 
    g.*,
    gi.image_path,
    goh.*,
    ge.name, ge.quantity,
    gmp.tier, gmp.duration, gmp.price, gmp.inclusions,
    um.user_id, u.username, u.email
FROM 
    gyms g
LEFT JOIN 
    gym_images gi ON g.gym_id = gi.gym_id
LEFT JOIN 
    gym_operating_hours goh ON g.gym_id = goh.gym_id
LEFT JOIN 
    gym_equipment ge ON g.gym_id = ge.gym_id
LEFT JOIN 
    gym_membership_plans gmp ON g.gym_id = gmp.gym_id
LEFT JOIN 
    user_memberships um ON g.gym_id = um.gym_id
LEFT JOIN 
    users u ON um.user_id = u.id
WHERE g.owner_id = :ownerId
LIMIT 1;
";

$stmt = $conn->prepare($gymQuery);
$stmt->execute(['ownerId' => $ownerId]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch gym images
$imageQuery = "SELECT image_path FROM gym_images WHERE gym_id = :gymId";
$imageStmt = $conn->prepare($imageQuery);
$imageStmt->execute(['gymId' => $gym['gym_id']]);
$gymImages = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch operating hours
$hoursQuery = "SELECT day, morning_open_time, morning_close_time, evening_open_time, evening_close_time FROM gym_operating_hours WHERE gym_id = :gymId";
$hoursStmt = $conn->prepare($hoursQuery);
$hoursStmt->execute(['gymId' => $gym['gym_id']]);
$gymHours = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch equipment
$equipmentQuery = "SELECT equipment_name, quantity FROM gym_equipment WHERE gym_id = :gymId";
$equipmentStmt = $conn->prepare($equipmentQuery);
$equipmentStmt->execute(['gymId' => $gym['gym_id']]);
$gymEquipment = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch membership plans
$membershipQuery = "SELECT tier, duration, price, inclusions FROM gym_membership_plans WHERE gym_id = :gymId";
$membershipStmt = $conn->prepare($membershipQuery);
$membershipStmt->execute(['gymId' => $gym['gym_id']]);
$gymMembershipPlans = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto p-6">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Gym Owner Details</h1>
    <div class="bg-white shadow-md rounded-md p-4">
        <h2 class="text-xl font-semibold text-gray-700"><?php echo htmlspecialchars($owner['name']); ?></h2>
        <p class="text-gray-600"><strong>Email:</strong> <?php echo htmlspecialchars($owner['email']); ?></p>
        <p class="text-gray-600"><strong>Phone:</strong> <?php echo htmlspecialchars($owner['phone']); ?></p>
        <p class="text-gray-600"><strong>Address:</strong> <?php echo htmlspecialchars($owner['address'] . ', ' . $owner['city'] . ', ' . $owner['state'] . ' ' . $owner['zip_code'] . ', ' . $owner['country']); ?></p>
    </div>
    <?php if ($gym): ?>
        <div class="bg-white shadow-md rounded-md p-4">
        <h2 class="text-xl font-semibold text-gray-700"><?php echo htmlspecialchars($gym['name']); ?></h2>
            <p class="text-gray-600"><strong>Address:</strong> <?php echo htmlspecialchars($gym['address'] . ', ' . $gym['city'] . ', ' . $gym['state'] . ' ' . $gym['zip_code'] . ', ' . $gym['country']); ?></p>
            <p class="text-gray-600"><strong>Contact:</strong> <?php echo htmlspecialchars($gym['contact_phone'] . ' | ' . $gym['contact_email']); ?></p>
            <p class="text-gray-600"><strong>Max Capacity:</strong> <?php echo htmlspecialchars($gym['capacity']); ?></p>
            <p class="text-gray-600"><strong>Status:</strong> <?php echo htmlspecialchars($gym['status']); ?></p>

            <h3 class="text-lg font-semibold text-gray-700 mt-4">Images</h3>
            <?php if ($gymImages): ?>
                <div class="flex gap-4">
                    <?php foreach ($gymImages as $image): ?>
                        <img src="../gym/uploads/gym_images/<?php echo htmlspecialchars($image['image_path']); ?>" alt="Gym Image" class="w-32 h-24 object-cover rounded-md">
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-gray-600">No images found.</p>
            <?php endif; ?>

            <h3 class="text-lg font-semibold text-gray-700 mt-4">Operating Hours</h3>
            <?php if ($gymHours): ?>
                <table class="table-auto w-full text-gray-600">
                    <thead>
                        <tr>
                            <th class="px-4 py-2">Day</th>
                            <th class="px-4 py-2">Morning</th>
                            <th class="px-4 py-2">Evening</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gymHours as $hour): ?>
                            <tr>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($hour['day']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($hour['morning_open_time']) . ' - ' . htmlspecialchars($hour['morning_close_time']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($hour['evening_open_time']) . ' - ' . htmlspecialchars($hour['evening_close_time']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-gray-600">No operating hours listed.</p>
            <?php endif; ?>

            <h3 class="text-lg font-semibold text-gray-700 mt-4">Equipment</h3>
            <?php if ($gymEquipment): ?>
                <ul class="list-disc ml-6 text-gray-600">
                    <?php foreach ($gymEquipment as $equipment): ?>
                        <li><?php echo htmlspecialchars($equipment['equipment_name']) . ' (' . htmlspecialchars($equipment['quantity']) . ')'; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-gray-600">No equipment listed.</p>
            <?php endif; ?>

            <h3 class="text-lg font-semibold text-gray-700 mt-4">Membership Plans</h3>
            <?php if ($gymMembershipPlans): ?>
                <table class="table-auto w-full text-gray-600">
                    <thead>
                        <tr>
                            <th class="px-4 py-2">Tier</th>
                            <th class="px-4 py-2">Duration</th>
                            <th class="px-4 py-2">Price</th>
                            <th class="px-4 py-2">Inclusions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gymMembershipPlans as $plan): ?>
                            <tr>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($plan['tier']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($plan['duration']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($plan['price']); ?></td>
                                <td class="border px-4 py-2"><?php echo htmlspecialchars($plan['inclusions']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-gray-600">No membership plans available.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="text-gray-600">No gym data available for this owner.</p>
    <?php endif; ?>
</div>
