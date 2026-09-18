<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/security/rate-limit.php';

header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html?status=invalid', true, 303);
    exit;
}

// Basic browser-origin check to reduce cross-site form submissions (CSRF).
// Allow the production site plus local development hosts used by the project.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$allowedHosts = ['junctiontools.com', 'www.junctiontools.com', 'localhost', '127.0.0.1', '::1'];

function jt_is_allowed_origin(string $url, array $allowedHosts): bool {
    if ($url === '') {
        return true;
    }

    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    if ($host === '' || !in_array($host, $allowedHosts, true)) {
        return false;
    }

    return true;
}

if ($origin !== '' && !jt_is_allowed_origin($origin, $allowedHosts)) {
    header('Location: contact.html?status=invalid', true, 303);
    exit;
}

if ($origin === '' && $referer !== '' && !jt_is_allowed_origin($referer, $allowedHosts)) {
    header('Location: contact.html?status=invalid', true, 303);
    exit;
}

// Reject oversized request bodies before parsing user input.
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 32 * 1024) {
    header('Location: contact.html?status=invalid', true, 303);
    exit;
}

if (!jt_rate_limit('contact-form', 5, 300)) {
    header('Location: contact.html?status=duplicate', true, 303);
    exit;
}

// HONEYPOT SPAM CHECK
if (!empty($_POST['website_url'])) {
    header('Location: contact.html?status=success', true, 303);
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

// Strict input length limits prevent oversized payloads and mail abuse.
if (mb_strlen($name) > 120 || mb_strlen($email) > 254 || mb_strlen($message) > 5000) {
    header('Location: contact.html?status=invalid', true, 303);
    exit;
}

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
    header('Location: contact.html?status=invalid', true, 303);
    exit;
}

// Keep secrets out of source control. Configure these in the hosting environment.
$host = getenv('JUNCTIONTOOLS_DB_HOST') ?: 'localhost';
$db = getenv('JUNCTIONTOOLS_DB_NAME') ?: '';
$user = getenv('JUNCTIONTOOLS_DB_USER') ?: '';
$pass = getenv('JUNCTIONTOOLS_DB_PASS') ?: '';
$charset = 'utf8mb4';

if ($db === '' || $user === '' || $pass === '') {
    header('Location: contact.html?status=error', true, 303);
    exit;
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    $stmtCheck = $pdo->prepare(
        "SELECT id FROM tool_suggestions WHERE email = ? AND created_at > (NOW() - INTERVAL 60 SECOND)"
    );
    $stmtCheck->execute([$email]);
    if ($stmtCheck->fetch()) {
        header('Location: contact.html?status=duplicate', true, 303);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO tool_suggestions (name, email, message) VALUES (?, ?, ?)"
    );
    $stmt->execute([$name, $email, $message]);

    $smtpHost = getenv('JUNCTIONTOOLS_SMTP_HOST') ?: '';
    $smtpUser = getenv('JUNCTIONTOOLS_SMTP_USER') ?: '';
    $smtpPass = getenv('JUNCTIONTOOLS_SMTP_PASS') ?: '';
    $mailFrom = getenv('JUNCTIONTOOLS_MAIL_FROM') ?: $smtpUser;
    $mailTo = getenv('JUNCTIONTOOLS_MAIL_TO') ?: $smtpUser;

    // Database submission remains successful even if notification configuration is absent.
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '' && $mailFrom !== '' && $mailTo !== '') {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom($mailFrom, 'JunctionTools Suggestions');
        $mail->addAddress($mailTo, 'JunctionTools');
        $mail->addReplyTo($email, $name);
        $mail->isHTML(false);
        $mail->Subject = 'New Tool Suggestion from ' . $name;
        $mail->Body = "You have received a new tool suggestion.\n\n"
            . "Name: $name\n"
            . "Email: $email\n\n"
            . "Message:\n$message";
        $mail->send();
    }

    header('Location: contact.html?status=success', true, 303);
    exit;
} catch (Throwable $e) {
    // Never expose database/SMTP exception details to the public response.
    error_log('JunctionTools contact form error: ' . $e->getMessage());
    header('Location: contact.html?status=error', true, 303);
    exit;
}
