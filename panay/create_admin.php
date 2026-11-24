
<?php

require_once 'config.php';

$username = "admin";
$password = "admin123";
$fullname = "System Administrator";
$role = "admin";

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO users (username, password, full_name, role)
     VALUES (?, ?, ?, ?)"
);

$stmt->bind_param(
    "ssss",
    $username,
    $hashedPassword,
    $fullname,
    $role
);

if ($stmt->execute()) {
    echo "Admin account created successfully!";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();

?>
