<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Composer autoloader (agar composer use kar rahe hain)
require 'vendor/autoload.php';

// Database Configuration
$host = 'localhost';
$db   = 'u805331253_JunctionTools';
$user = 'u805331253_admin'; 
$pass = '=Xr|+;p4|V';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. HONEYPOT SPAM CHECK
    if (!empty($_POST['website_url'])) {
        header("Location: contact.html?status=success");
        exit();
    }

    $name = trim($_POST['name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $message = trim($_POST['message'] ?? '');

    // 2. BASIC VALIDATION
    if (empty($name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($message)) {
        header("Location: contact.html?status=invalid");
        exit();
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);

        // 3. DUPLICATE CHECK (Last 60 seconds)
        $stmtCheck = $pdo->prepare("SELECT id FROM tool_suggestions WHERE email = ? AND created_at > (NOW() - INTERVAL 60 SECOND)");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->rowCount() > 0) {
            header("Location: contact.html?status=duplicate");
            exit();
        }

        // 4. INSERT INTO DATABASE
        $stmt = $pdo->prepare("INSERT INTO tool_suggestions (name, email, message) VALUES (?, ?, ?)");
        $stmt->execute([$name, $email, $message]);

        // 5. SMTP EMAIL NOTIFICATION VIA PHPMailer
        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';     // Apni hosting ka SMTP server (e.g., mail.junctiontools.com)
        $mail->SMTPAuth   = true;
        $mail->Username   = 'suggestions@junctiontools.com';    // Aapki cPanel/Professional Email
        $mail->Password   = 'fDI|xok9;';     // Email ka password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // ya PHPMailer::ENCRYPTION_SMTPS (Port 465 ke liye)
        $mail->Port       = 587;                       // Port 587 ya 465

        // Recipients
        $mail->setFrom('suggestions@junctiontools.com', 'Junction Tools Suggestions');
        $mail->addAddress('suggestions@junctiontools.com', 'Junction Tools'); // Jahan email receive karni hai
        $mail->addReplyTo($email, $name);                    // Taake 'Reply' click karne par user ko jaye

        // Content
        $mail->isHTML(false);
        $mail->Subject = "New Tool Suggestion from " . htmlspecialchars($name);
        $mail->Body    = "You have received a new tool suggestion.\n\n" .
                         "Name: $name\n" .
                         "Email: $email\n\n" .
                         "Message:\n$message";

        $mail->send();

        header("Location: contact.html?status=success");
        exit();

    } catch (\PDOException $e) {
        header("Location: contact.html?status=error");
        exit();
    } catch (Exception $e) {
        // Database mein save ho gaya lekin email send hone mein error aya, phir bhi success redirect kar sakte hain ya error log kar sakte hain
        header("Location: contact.html?status=success");
        exit();
    }
}