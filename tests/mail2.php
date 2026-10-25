<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/PHPMailer/phpmailer/src/Exception.php';
require __DIR__ . '/../vendor/PHPMailer/phpmailer/src/PHPMailer.php';
require __DIR__ . '/../vendor/PHPMailer/phpmailer/src/SMTP.php';

$mail = new PHPMailer(true);

try{// ab hier wie oben konfigurieren
// Server-Einstellungen
$mail->isSMTP();
$mail->Host       = 'smtp.example.com';
$mail->SMTPAuth   = true;
$mail->Username   = 'absender@example.com';
$mail->Password   = 'CHANGE_ME';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // oder PHPMailer::ENCRYPTION_SMTPS
$mail->Port       = 587; // oder 465 bei SMTPS

// Absender & Empfänger
$mail->setFrom('absender@example.com', 'Dein Name');
$mail->addAddress('empfaenger@example.com', 'Empfänger Name');

// Inhalt
$mail->isHTML(true);
$mail->Subject = 'Testmail von XAMPP';
$mail->Body    = '<b>Hallo!</b> Dies ist eine Testmail aus meinem lokalen XAMPP.';
$mail->AltBody = 'Hallo! Dies ist eine Testmail aus meinem lokalen XAMPP (Textversion).';

$mail->send();
echo 'Mail wurde gesendet';
} catch (Exception $e) {
echo "Mail konnte nicht gesendet werden. Fehler: {$mail->ErrorInfo}";
}