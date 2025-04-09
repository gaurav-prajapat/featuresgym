<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.html');
    exit;
}

$gymOwnerId = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Fetch gym details for the logged-in gym owner
$stmt = $conn->prepare("SELECT * FROM gyms WHERE owner_id = :owner_id");
$stmt->bindParam(':owner_id', $gymOwnerId); // Fixed parameter name to match the query
$stmt->execute();
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $gym_name = $_POST['gym_name'];
    $location = $_POST['location'];
    $contact_number = $_POST['contact_number'];
    $email = $_POST['email'];
    $website = $_POST['website'];
    $description = $_POST['description'];

    // Update gym details with correct parameter binding
    $stmt = $conn->prepare("UPDATE gyms SET gym_name = :gym_name, location = :location, contact_number = :contact_number, email = :email, website = :website, description = :description WHERE owner_id = :owner_id");

    $stmt->execute([
        ':gym_name' => $gym_name,
        
        ':contact_number' => $contact_number,
        ':email' => $email,
        ':description' => $description,
        ':owner_id' => $gymOwnerId
    ]);

    if ($stmt->rowCount() > 0) {
        echo "<p class='bg-green-500 text-white p-4'>Gym details updated successfully!</p>";
    } else {
        echo "<p class='bg-red-500 text-white p-4'>Failed to update gym details.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<body class="bg-gray-100">
    <div class="container mx-auto p-6">
        <div class="bg-white shadow-lg rounded-lg p-6">
            <h1 class="text-xl font-bold mb-4">Gym Details</h1>
            <form method="POST" action="gym_details.php">
                <div class="mb-4">
                    <label for="gym_name" class="block text-lg font-medium">Gym Name</label>
                    <input type="text" name="gym_name" id="gym_name" value="<?php echo htmlspecialchars($gym['gym_name']); ?>" class="w-full p-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="location" class="block text-lg font-medium">Location</label>
                    <input type="text" name="location" id="location" value="<?php echo htmlspecialchars($gym['location']); ?>" class="w-full p-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="contact_number" class="block text-lg font-medium">Contact Number</label>
                    <input type="text" name="contact_number" id="contact_number" value="<?php echo htmlspecialchars($gym['contact_number']); ?>" class="w-full p-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="email" class="block text-lg font-medium">Email</label>
                    <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($gym['email']); ?>" class="w-full p-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="website" class="block text-lg font-medium">Website</label>
                    <input type="url" name="website" id="website" value="<?php echo htmlspecialchars($gym['website']); ?>" class="w-full p-2 border rounded">
                </div>
                <div class="mb-4">
                    <label for="description" class="block text-lg font-medium">Description</label>
                    <textarea name="description" id="description" rows="4" class="w-full p-2 border rounded"><?php echo htmlspecialchars($gym['description']); ?></textarea>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Update Gym Details</button>
            </form>
        </div>
    </div>
</body>
</html>