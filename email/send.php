<?php
require_once '../config/database.php';
require_once '../config/globals.php';
require_once '../config/mail.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

function sendEmail($to, $subject, $message, $type = 'otp') {
    global $mailConfig;
    
// Add logging function
function logmail($message, $data = []) {
    try {
        $logFile = __DIR__ . '/../logs/email.log';
        $timestamp = date('Y-m-d H:i:s');
        $logData = array_merge([
            'timestamp' => $timestamp,
            'message' => $message
        ], $data);
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    } catch (Exception $e) {
        error_log("Email logging error: " . $e->getMessage());
    }
}
    
    // Debug information
    $debug_info = "[" . date('Y-m-d H:i:s') . "] Attempting to send email\n";
    $debug_info .= "To: $to\n";
    $debug_info .= "Subject: $subject\n";
    $debug_info .= "Type: $type\n";
    
    $mailer = new PHPMailer(true);
    $attempts = $mailConfig['retry_attempts'];
    $delay = $mailConfig['retry_delay'];

    // Log mail configuration
    $debug_info .= "Mail Configuration:\n";
    $debug_info .= "Server: " . $mailConfig[$type]['server'] . "\n";
    $debug_info .= "Port: " . $mailConfig[$type]['port'] . "\n";
    $debug_info .= "Username: " . $mailConfig[$type]['username'] . "\n";
    
    file_put_contents($logsDir . '/email.log', $debug_info, FILE_APPEND);

    for ($i = 0; $i < $attempts; $i++) {
        try {
            // Server settings
            $mailer->SMTPDebug = SMTP::DEBUG_SERVER;
            $mailer->Debugoutput = function($str, $level) use ($logsDir) {
                $clean_str = trim(preg_replace('/\r\n|\r|\n/', ' ', $str));
                file_put_contents(
                    $logsDir . '/email.log',
                    "[" . date('Y-m-d H:i:s') . "] [Level $level] $clean_str\n",
                    FILE_APPEND
                );
            };

            $mailer->isSMTP();
            $mailer->Host = $mailConfig[$type]['server'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $mailConfig[$type]['username'];
            $mailer->Password = $mailConfig[$type]['password'];
            
            // SSL Configuration
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;  // Using SMTPS for SSL
            $mailer->Port = $mailConfig[$type]['port'];         // Port 465 for SSL
            
            // Additional settings for Hostinger
            $mailer->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Set timeout
            $mailer->Timeout = 60;
            
            // Recipients
            $mailer->setFrom($mailConfig[$type]['email'], 'AmezPrice');
            $mailer->addAddress($to);
            
            // Content
            $mailer->isHTML(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->Subject = $subject;
            $mailer->Body = $message;
            $mailer->AltBody = strip_tags($message);

            // Clear previous recipients
            $mailer->clearAddresses();
            $mailer->clearAttachments();
            
            // Add recipient again (after clearing)
            $mailer->addAddress($to);

            // Send email
            $result = $mailer->send();
            
            // Log success
            file_put_contents(
                $logsDir . '/email.log',
                "[" . date('Y-m-d H:i:s') . "] Email sent successfully to $to\n",
                FILE_APPEND
            );
            
            return true;

        } catch (Exception $e) {
            $error_log = sprintf(
                "[%s] Attempt %d failed\nError: %s\nMailer Error: %s\n",
                date('Y-m-d H:i:s'),
                $i + 1,
                $e->getMessage(),
                $mailer->ErrorInfo
            );
            
            file_put_contents($logsDir . '/email.log', $error_log, FILE_APPEND);
            
            // Clear recipients before retry
            $mailer->clearAddresses();
            $mailer->clearAttachments();
            
            if ($i < $attempts - 1) {
                file_put_contents(
                    $logsDir . '/email.log',
                    "[" . date('Y-m-d H:i:s') . "] Waiting $delay seconds before retry...\n",
                    FILE_APPEND
                );
                sleep($delay);
            }
        }
    }
    
    // Log final failure
    file_put_contents(
        $logsDir . '/email.log',
        "[" . date('Y-m-d H:i:s') . "] All attempts failed for sending email to $to\n\n",
        FILE_APPEND
    );
    
    return false;
}
?>