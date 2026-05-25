<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php';

class EmailApiController
{
    private const SMTP_HOST = 'smtp.gmail.com';
    private const SMTP_PORT = 587;
    private const SMTP_USERNAME = '';
    private const SMTP_PASSWORD = '';
    private const SMTP_ENCRYPTION = PHPMailer::ENCRYPTION_STARTTLS;
    private const SMTP_DEBUG_LEVEL = SMTP::DEBUG_OFF;
    private const FROM_EMAIL = '';
    private const FROM_NAME = "Guillermo's Web System";

    private static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return (string)$value;
    }

    /** @return array{host:string,port:int,username:string,password:string,encryption:string,from_email:string,from_name:string,debug:int} */
    private static function mailConfig(): array
    {
        $encryptionRaw = strtolower((string)self::env('MAIL_ENCRYPTION', 'tls'));
        $encryption = $encryptionRaw === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;

        $username = (string)self::env('MAIL_USERNAME', self::SMTP_USERNAME);
        $fromEmail = (string)self::env('MAIL_FROM_ADDRESS', self::FROM_EMAIL ?: $username);

        return [
            'host' => (string)self::env('MAIL_HOST', self::SMTP_HOST),
            'port' => (int)self::env('MAIL_PORT', (string)self::SMTP_PORT),
            'username' => $username,
            'password' => (string)self::env('MAIL_PASSWORD', self::SMTP_PASSWORD),
            'encryption' => $encryption,
            'from_email' => $fromEmail,
            'from_name' => (string)self::env('MAIL_FROM_NAME', self::FROM_NAME),
            'debug' => self::SMTP_DEBUG_LEVEL,
        ];
    }

    private static function buildMailer(array $mailConfig): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = $mailConfig['debug'];
        $mail->Debugoutput = static function ($str) {
            self::logEvent('SMTP DEBUG: ' . trim($str));
        };
        $mail->isSMTP();
        $mail->Host = $mailConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mailConfig['username'];
        $mail->Password = $mailConfig['password'];
        $mail->SMTPSecure = $mailConfig['encryption'];
        $mail->Port = $mailConfig['port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
        $mail->addReplyTo($mailConfig['from_email'], $mailConfig['from_name']);

        return $mail;
    }

    /**
     * Send a verification email via Gmail SMTP + app password.
     *
     * @param string $email
     * @param string $name
     * @param string $code
    * @return bool|string
     */
    public static function sendVerificationEmail(string $email, string $name, string $code): bool|string
    {
        $sanitizedEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEvent('Invalid recipient email: ' . $email);
            return 'Invalid recipient email address.';
        }

        $normalizedName = trim($name);
        if ($normalizedName === '') {
            $normalizedName = 'Customer';
        }

        $normalizedCode = trim($code);

        $mailConfig = self::mailConfig();
        $mail = self::buildMailer($mailConfig);

        try {
            // --- Recipients ---
            $mail->addAddress($sanitizedEmail, $normalizedName);

            // --- Content ---
            $mail->isHTML(true);
            $mail->Subject = "Your Guillermo's Verification Code";
            $mail->Body = "Hi {$normalizedName},<br><br>Thank you for registering. Your verification code is: <b>{$normalizedCode}</b><br><br>This code will expire in 10 minutes.<br><br>Best regards,<br>Guillermo's Team";
            $mail->AltBody = "Your verification code is: {$normalizedCode}.";

            self::logEvent(sprintf('Attempting to send verification email to %s', $sanitizedEmail));
            $mail->send();
            self::logEvent(sprintf('Verification email sent successfully to %s', $sanitizedEmail));
            return true;
        } catch (PHPMailerException $e) {
            $errorMessage = 'Failed to send verification email. ' . $e->getMessage();
            if (!empty($mail->ErrorInfo)) {
                $errorMessage .= ' | Mailer error: ' . $mail->ErrorInfo;
            }

            self::logEvent($errorMessage);
            return $errorMessage;
        }
    }

    public static function sendPasswordResetEmail(string $email, string $name, string $code)
    {
        $sanitizedEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEvent('Invalid recipient email for password reset: ' . $email);
            return 'Invalid recipient email address.';
        }

        $normalizedName = trim($name);
        if ($normalizedName === '') {
            $normalizedName = 'Customer';
        }

        $normalizedCode = trim($code);

        $mailConfig = self::mailConfig();
        $mail = self::buildMailer($mailConfig);

        try {
            $mail->addAddress($sanitizedEmail, $normalizedName);

            $mail->isHTML(true);
            $mail->Subject = "Reset your Guillermo's password";
            $mail->Body = "Hi {$normalizedName},<br><br>We received a request to reset your password. Your reset code is: <b>{$normalizedCode}</b><br><br>This code will expire in 10 minutes. If you did not request a password reset, you can safely ignore this message.<br><br>Best regards,<br>Guillermo's Team";
            $mail->AltBody = "Your password reset code is: {$normalizedCode}.";

            self::logEvent(sprintf('Attempting to send password reset email to %s', $sanitizedEmail));
            $mail->send();
            self::logEvent(sprintf('Password reset email sent successfully to %s', $sanitizedEmail));
            return true;
        } catch (PHPMailerException $e) {
            $errorMessage = 'Failed to send password reset email. ' . $e->getMessage();
            if (!empty($mail->ErrorInfo)) {
                $errorMessage .= ' | Mailer error: ' . $mail->ErrorInfo;
            }

            self::logEvent($errorMessage);
            return $errorMessage;
        }
    }

    /**
     * Send receipt email with order details
     */
    public static function sendReceiptEmail(string $email, string $name, array $orderDetails): bool|string
    {
        $sanitizedEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEvent('Invalid recipient email for receipt: ' . $email);
            return 'Invalid recipient email address.';
        }

        $normalizedName = trim($name);
        if ($normalizedName === '') {
            $normalizedName = 'Customer';
        }

        $orderId = $orderDetails['order_id'] ?? '';
        $orderDate = $orderDetails['order_date'] ?? '';
        $paymentMethod = $orderDetails['payment_method'] ?? '';
        $status = $orderDetails['status'] ?? '';
        $totalAmount = $orderDetails['total_amount'] ?? 0;
        $change = $orderDetails['change'] ?? 0;
        $items = $orderDetails['items'] ?? [];

        // Defensive logging: if items are missing product names, log for later diagnostics
        if (!empty($items) && is_array($items)) {
            foreach ($items as $i => $it) {
                $pname = $it['Product_Name'] ?? $it['name'] ?? '';
                if (trim($pname) === '' || stripos((string)$pname, 'unknown') !== false) {
                    self::logEvent("Receipt email payload contains item with missing/unknown name for order {$orderId}: index {$i}; item: " . json_encode($it));
                }
            }
        }

        $mailConfig = self::mailConfig();

        // Generate modern HTML receipt content for email
        $htmlBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Order Receipt #$orderId</title>
            <style>
                /* Reset and base styles */
                body, table, td, p, a, li, blockquote {
                    -webkit-text-size-adjust: 100%;
                    -ms-text-size-adjust: 100%;
                }
                table, td {
                    mso-table-lspace: 0pt;
                    mso-table-rspace: 0pt;
                }
                img {
                    -ms-interpolation-mode: bicubic;
                }

                /* Base styles */
                body {
                    margin: 0 !important;
                    padding: 0 !important;
                    background-color: #f8f9fa;
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                }

                /* Container */
                .email-container {
                    max-width: 600px;
                    margin: 0 auto;
                    background-color: #ffffff;
                    border-radius: 16px;
                    overflow: hidden;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                }

                /* Header */
                .header {
                    background: linear-gradient(135deg, #6B4F3F 0%, #8B6F5F 100%);
                    color: white;
                    padding: 40px 30px;
                    text-align: center;
                    position: relative;
                }

                .header::before {
                    content: '🧾';
                    font-size: 3rem;
                    display: block;
                    margin-bottom: 15px;
                }

                .header h1 {
                    margin: 0;
                    font-size: 2.2rem;
                    font-weight: 700;
                    letter-spacing: -0.5px;
                }

                .header p {
                    margin: 8px 0 0;
                    opacity: 0.9;
                    font-size: 1.1rem;
                }

                .order-badge {
                    position: absolute;
                    top: 20px;
                    right: 20px;
                    background: rgba(255,255,255,0.2);
                    padding: 8px 16px;
                    border-radius: 20px;
                    font-size: 0.9rem;
                    font-weight: 600;
                    backdrop-filter: blur(10px);
                }

                /* Progress Tracker */
                .progress-section {
                    padding: 30px;
                    background: #f8f9fa;
                    border-bottom: 1px solid #e9ecef;
                }

                .progress-header {
                    text-align: center;
                    margin-bottom: 20px;
                }

                .progress-header h3 {
                    margin: 0;
                    color: #2c3e50;
                    font-size: 1.2rem;
                    font-weight: 600;
                }

                .progress-steps {
                    display: flex;
                    justify-content: space-between;
                    position: relative;
                    margin-bottom: 15px;
                }

                .progress-steps::before {
                    content: '';
                    position: absolute;
                    top: 15px;
                    left: 0;
                    right: 0;
                    height: 3px;
                    background: #e9ecef;
                    z-index: 1;
                }

                .step {
                    background: #e9ecef;
                    border: 3px solid #e9ecef;
                    border-radius: 50%;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 0.8rem;
                    font-weight: 600;
                    color: #6c757d;
                    position: relative;
                    z-index: 2;
                }

                .step.active {
                    background: #28a745;
                    border-color: #28a745;
                    color: white;
                }

                .step.completed {
                    background: #28a745;
                    border-color: #28a745;
                    color: white;
                }

                .step-labels {
                    display: flex;
                    justify-content: space-between;
                }

                .step-label {
                    font-size: 0.75rem;
                    color: #6c757d;
                    text-align: center;
                    flex: 1;
                }

                .step-label.active {
                    color: #28a745;
                    font-weight: 600;
                }

                /* Info Grid */
                .info-grid {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 20px;
                    padding: 30px;
                    background: white;
                }

                .info-card {
                    flex: 1;
                    min-width: 250px;
                    background: #f8f9fa;
                    border-radius: 12px;
                    padding: 20px;
                    border-left: 4px solid #6B4F3F;
                }

                .info-card h3 {
                    margin: 0 0 8px;
                    font-size: 0.85rem;
                    color: #6c757d;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    font-weight: 600;
                }

                .info-card p {
                    margin: 0;
                    font-size: 1rem;
                    font-weight: 500;
                    color: #2c3e50;
                }

                /* Items Section */
                .items-section {
                    padding: 30px;
                    background: #f8f9fa;
                }

                .section-header {
                    display: flex;
                    align-items: center;
                    margin-bottom: 20px;
                }

                .section-header h2 {
                    margin: 0;
                    font-size: 1.3rem;
                    font-weight: 600;
                    color: #2c3e50;
                }

                .section-icon {
                    margin-right: 12px;
                    font-size: 1.5rem;
                }

                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
                }

                .items-table th {
                    background: linear-gradient(135deg, #6B4F3F 0%, #8B6F5F 100%);
                    color: white;
                    padding: 15px;
                    text-align: left;
                    font-weight: 600;
                    font-size: 0.9rem;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .items-table td {
                    padding: 15px;
                    border-bottom: 1px solid #e9ecef;
                    background: white;
                }

                .item-name {
                    font-weight: 600;
                    color: #2c3e50;
                }

                .item-qty {
                    background: #e9ecef;
                    padding: 4px 8px;
                    border-radius: 6px;
                    font-size: 0.85rem;
                    font-weight: 600;
                    color: #495057;
                    display: inline-block;
                }

                .price {
                    font-weight: 600;
                    color: #28a745;
                }

                .subtotal {
                    font-weight: 700;
                    color: #2c3e50;
                }

                /* Totals Section */
                .totals-section {
                    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                    color: white;
                    padding: 30px;
                }

                .totals-grid {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .total-row {
                    font-size: 1.3rem;
                    font-weight: 700;
                }

                .change-row {
                    background: rgba(255,255,255,0.1);
                    padding: 15px;
                    border-radius: 8px;
                    margin-top: 15px;
                }

                /* CTA Section */
                .cta-section {
                    padding: 30px;
                    text-align: center;
                    background: white;
                }

                .cta-buttons {
                    display: flex;
                    justify-content: center;
                    gap: 15px;
                    margin-top: 20px;
                }

                .cta-button {
                    display: inline-block;
                    padding: 12px 24px;
                    background: linear-gradient(135deg, #6B4F3F 0%, #8B6F5F 100%);
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    font-size: 0.9rem;
                    transition: all 0.3s;
                }

                .cta-button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(102,126,234,0.4);
                }

                .cta-button.secondary {
                    background: #6c757d;
                }

                .cta-button.secondary:hover {
                    background: #5a6268;
                }

                /* Footer */
                .footer {
                    padding: 30px;
                    text-align: center;
                    background: #f8f9fa;
                    border-top: 1px solid #e9ecef;
                }

                .footer h3 {
                    margin: 0 0 10px;
                    color: #2c3e50;
                    font-size: 1.4rem;
                }

                .footer p {
                    margin: 5px 0;
                    color: #6c757d;
                }

                /* Responsive */
                @media only screen and (max-width: 600px) {
                    .email-container { margin: 0; border-radius: 0; }
                    .header { padding: 30px 20px; }
                    .info-grid { padding: 20px; }
                    .info-card { min-width: 100%; }
                    .items-section { padding: 20px; }
                    .totals-section { padding: 20px; }
                    .cta-section { padding: 20px; }
                    .footer { padding: 20px; }
                    .progress-steps { flex-wrap: wrap; gap: 10px; }
                    .cta-buttons { flex-direction: column; }
                }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <!-- Header -->
                <div class='header'>
                    <div class='order-badge'>#$orderId</div>
                    <h1>Order Receipt</h1>
                    <p>Guillermo's Restaurant</p>
                </div>

                <!-- Progress Tracker -->
                <div class='progress-section'>
                    <div class='progress-header'>
                        <h3>📍 Order Status</h3>
                    </div>
                    <div class='progress-steps'>
                        <div class='step " . ($status === 'Pending' || $status === 'Completed' || $status === 'Cancelled' ? 'completed' : '') . "'>1</div>
                        <div class='step " . ($status === 'Completed' ? 'completed' : ($status === 'Pending' ? 'active' : '')) . "'>2</div>
                        <div class='step " . ($status === 'Completed' ? 'completed' : '') . "'>3</div>
                    </div>
                    <div class='step-labels'>
                        <div class='step-label " . ($status === 'Pending' || $status === 'Completed' || $status === 'Cancelled' ? 'active' : '') . "'>Order Placed</div>
                        <div class='step-label " . ($status === 'Completed' ? 'active' : ($status === 'Pending' ? 'active' : '')) . "'>Preparing</div>
                        <div class='step-label " . ($status === 'Completed' ? 'active' : '') . "'>Delivered</div>
                    </div>
                </div>

                <!-- Order Information -->
                <div class='info-grid'>
                    <div class='info-card'>
                        <h3>👤 Customer</h3>
                        <p>" . htmlspecialchars($normalizedName) . "</p>
                    </div>
                    <div class='info-card'>
                        <h3>📅 Order Date</h3>
                            <p>" . (function($raw) {
                                if (!$raw) return '';
                                try {
                                    // If the incoming date string has an explicit timezone, parse as-is.
                                    $hasTz = preg_match('/[Zz]|[+\-]\d{2}(:?\d{2})?$/', trim($raw));
                                    if ($hasTz) {
                                        $dt = new \DateTimeImmutable($raw);
                                    } else {
                                        $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                                    }
                                    return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M d, Y g:i A');
                                } catch (\Throwable $e) {
                                    return date('M d, Y g:i A', strtotime($raw));
                                }
                            })($orderDate) . "</p>
                    </div>
                    <div class='info-card'>
                        <h3>💳 Payment</h3>
                        <p>" . htmlspecialchars($paymentMethod) . "</p>
                    </div>
                    <div class='info-card'>
                        <h3>📊 Status</h3>
                        <p><span style='display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; background-color: " . ($status === 'Completed' ? '#d4edda; color: #155724;' : ($status === 'Pending' ? '#fff3cd; color: #856404;' : '#f8d7da; color: #721c24;')) . "'>" . htmlspecialchars($status) . "</span></p>
                    </div>
                </div>

                <!-- Order Items -->
                <div class='items-section'>
                    <div class='section-header'>
                        <span class='section-icon'>🛒</span>
                        <h2>Order Items</h2>
                    </div>
                    <table class='items-table'>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>";

        foreach ($items as $item) {
            $productName = htmlspecialchars($item['Product_Name'] ?? $item['name'] ?? 'Unknown Item');
            $quantity = $item['Quantity'] ?? $item['quantity'] ?? 0;
            $price = $item['Price'] ?? $item['price'] ?? 0;
            $subtotal = $item['Subtotal'] ?? ($price * $quantity) ?? 0;

            $htmlBody .= "<tr>
                            <td><span class='item-name'>{$productName}</span></td>
                            <td><span class='item-qty'>{$quantity}</span></td>
                            <td><span class='price'>₱" . number_format($price, 2) . "</span></td>
                            <td><span class='subtotal'>₱" . number_format($subtotal, 2) . "</span></td>
                        </tr>";
        }

        $htmlBody .= "</tbody>
                    </table>
                </div>

                <!-- Order Totals -->
                <div class='totals-section'>
                    <div class='totals-grid'>
                        <div></div>
                        <div class='total-row'>
                            Total Amount: ₱" . number_format($totalAmount, 2) . "
                        </div>
                    </div>";

        if ($change > 0) {
            $htmlBody .= "<div class='change-row'>
                        <div class='total-row'>
                            Change: ₱" . number_format($change, 2) . "
                        </div>
                    </div>";
        }

        $htmlBody .= "</div>

                <!-- Call to Action -->
                <div class='cta-section'>
                    <h3>Need to make changes to your order?</h3>
                    <p>Contact us if you need to modify or cancel your order</p>
                    <div class='cta-buttons'>
                        <a href='mailto:" . htmlspecialchars((string)$mailConfig['from_email']) . "?subject=Order #$orderId Inquiry' class='cta-button'>📧 Contact Support</a>
                        <a href='#' class='cta-button secondary'>📱 Call Us</a>
                    </div>
                </div>

                <!-- Footer -->
                <div class='footer'>
                    <h3>Thank You! 🎉</h3>
                    <p>We hope you enjoy your meal from Guillermo's Restaurant</p>
                    <p>For any questions or concerns, please contact our support team</p>
                </div>
            </div>
        </body>
        </html>";

        $mail = self::buildMailer($mailConfig);

        try {
            $mail->addAddress($sanitizedEmail, $normalizedName);

            $mail->isHTML(true);
            $mail->Subject = "Your Guillermo's Order Receipt - Order #$orderId";
            $mail->Body = $htmlBody;
            $mail->AltBody = "Your order #$orderId has been placed successfully. Total: ₱" . number_format($totalAmount, 2);

            self::logEvent(sprintf('Attempting to send receipt email to %s for order %s', $sanitizedEmail, $orderId));
            $mail->send();
            self::logEvent(sprintf('Receipt email sent successfully to %s for order %s', $sanitizedEmail, $orderId));
            return true;
        } catch (PHPMailerException $e) {
            $errorMessage = 'Failed to send receipt email. ' . $e->getMessage();
            if (!empty($mail->ErrorInfo)) {
                $errorMessage .= ' | Mailer error: ' . $mail->ErrorInfo;
            }

            self::logEvent($errorMessage);
            return $errorMessage;
        }
    }

    public static function sendOrderStatusEmail(string $email, string $name, array $orderDetails): bool|string
    {
        $sanitizedEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEvent('Invalid recipient email for order status: ' . $email);
            return 'Invalid recipient email address.';
        }

        $normalizedName = trim($name);
        if ($normalizedName === '') {
            $normalizedName = 'Customer';
        }

        $orderId = $orderDetails['order_id'] ?? '';
        $orderDate = $orderDetails['order_date'] ?? '';
        $paymentMethod = $orderDetails['payment_method'] ?? '';
        $status = $orderDetails['status'] ?? 'Pending';
        $additionalMessage = $orderDetails['message'] ?? '';
        $items = $orderDetails['items'] ?? [];
        $totalAmount = $orderDetails['total_amount'] ?? 0;

        $statusHeadline = sprintf('Your order #%s is now %s', $orderId, strtolower($status));
        $orderDateDisplay = $orderDate ? (function($raw) {
            if (!$raw) return 'recently';
            try {
                $hasTz = preg_match('/[Zz]|[+\-]\d{2}(:?\d{2})?$/', trim($raw));
                if ($hasTz) {
                    $dt = new \DateTimeImmutable($raw);
                } else {
                    $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                }
                return $dt->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M d, Y g:i A');
            } catch (\Throwable $e) {
                return date('M d, Y g:i A', strtotime($raw));
            }
        })($orderDate) : 'recently';

        $itemsMarkup = '';
        if (!empty($items)) {
            $rows = '';
            foreach ($items as $item) {
                $productName = htmlspecialchars($item['Product_Name'] ?? $item['name'] ?? 'Item');
                $quantity = (int)($item['Quantity'] ?? $item['quantity'] ?? 0);
                $price = (float)($item['Price'] ?? $item['price'] ?? 0);
                $subtotal = (float)($item['Subtotal'] ?? ($price * $quantity));

                $rows .= '<tr>' .
                    '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . $productName . '</td>' .
                    '<td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:center;">' . $quantity . '</td>' .
                    '<td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:right;">₱' . number_format($price, 2) . '</td>' .
                    '<td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:right;">₱' . number_format($subtotal, 2) . '</td>' .
                '</tr>';
            }

            $itemsMarkup = '<table style="width:100%;border-collapse:collapse;margin-top:16px;font-size:14px;">' .
                '<thead>' .
                    '<tr>' .
                        '<th style="text-align:left;padding:10px 12px;background:#f1f3f5;border-bottom:1px solid #dee2e6;">Item</th>' .
                        '<th style="text-align:center;padding:10px 12px;background:#f1f3f5;border-bottom:1px solid #dee2e6;">Qty</th>' .
                        '<th style="text-align:right;padding:10px 12px;background:#f1f3f5;border-bottom:1px solid #dee2e6;">Price</th>' .
                        '<th style="text-align:right;padding:10px 12px;background:#f1f3f5;border-bottom:1px solid #dee2e6;">Subtotal</th>' .
                    '</tr>' .
                '</thead>' .
                '<tbody>' . $rows . '</tbody>' .
            '</table>';
        }

        $summaryRow = $totalAmount ? '<p style="margin:12px 0 0;font-weight:600;">Order Total: ₱' . number_format((float)$totalAmount, 2) . '</p>' : '';
        $paymentRow = $paymentMethod ? '<p style="margin:0;color:#6c757d;">Payment Method: ' . htmlspecialchars($paymentMethod) . '</p>' : '';
        $extraMessage = $additionalMessage ? '<p style="margin:16px 0 0;">' . nl2br(htmlspecialchars($additionalMessage)) . '</p>' : '';

        $htmlBody = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Order Update</title></head><body style="background:#f8f9fa;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;margin:0;padding:24px;">' .
            '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,0.08);">' .
                '<div style="background:linear-gradient(135deg,#4c6ef5,#7950f2);color:#fff;padding:28px 24px;">' .
                    '<p style="margin:0 0 8px;font-size:16px;">Hi ' . htmlspecialchars($normalizedName) . ',</p>' .
                    '<h1 style="margin:0;font-size:22px;font-weight:700;">' . htmlspecialchars($statusHeadline) . '</h1>' .
                    '<p style="margin:12px 0 0;font-size:14px;opacity:0.9;">Order placed on ' . htmlspecialchars($orderDateDisplay) . '</p>' .
                '</div>' .
                '<div style="padding:24px;">' .
                    '<p style="margin:0 0 12px;">We have reviewed your order and confirmed that everything is good to proceed. We will let you know once you order has been delivered.</p>' .
                    '<p style="margin:0 0 12px;">Order Reference: <strong>#' . htmlspecialchars((string)$orderId) . '</strong></p>' .
                    $paymentRow .
                    $summaryRow .
                    $itemsMarkup .
                    $extraMessage .
                    '<p style="margin:24px 0 0">If you have any questions, feel free to reply to this email or contact us directly.</p>' .
                    '<p style="margin:12px 0 0;">Warm regards,<br><strong>Guillermo\'s Team</strong></p>' .
                '</div>' .
            '</div>' .
        '</body></html>';

        $altBody = "Order #$orderId is now $status. Placed on $orderDateDisplay.";

        $mailConfig = self::mailConfig();
        $mail = self::buildMailer($mailConfig);

        try {
            $mail->addAddress($sanitizedEmail, $normalizedName);

            $mail->isHTML(true);
            $mail->Subject = "Update on your Guillermo's order #$orderId";
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody;

            self::logEvent(sprintf('Attempting to send %s status email to %s for order %s', $status, $sanitizedEmail, $orderId));
            $mail->send();
            self::logEvent(sprintf('Order status email sent successfully to %s for order %s', $sanitizedEmail, $orderId));
            return true;
        } catch (PHPMailerException $e) {
            $errorMessage = 'Failed to send order status email. ' . $e->getMessage();
            if (!empty($mail->ErrorInfo)) {
                $errorMessage .= ' | Mailer error: ' . $mail->ErrorInfo;
            }

            self::logEvent($errorMessage);
            return $errorMessage;
        }
    }

    /**
     * Send the secure supplier confirmation link via email.
     *
     * @param string $email   Supplier's email address (already provided by admin).
     * @param string $name    Recipient name (Contact_Person or Supplier_Name).
     * @param array  $details {
     *     po_id: int,
     *     supplier_name: string,
     *     order_date: string,
     *     total_amount: float|string,
     *     line_count: int,
     *     notes: string,
     *     url: string
     * }
     * @return bool|string  true on success, error string otherwise.
     */
    public static function sendSupplierPoLinkEmail(string $email, string $name, array $details): bool|string
    {
        $sanitizedEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEvent('Invalid supplier email for PO link: ' . $email);
            return 'Invalid recipient email address.';
        }

        $normalizedName = trim($name) === '' ? 'Supplier' : trim($name);
        $poId       = (int)($details['po_id'] ?? 0);
        $supplier   = htmlspecialchars((string)($details['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $orderDate  = htmlspecialchars((string)($details['order_date'] ?? ''), ENT_QUOTES, 'UTF-8');
        $total      = (float)($details['total_amount'] ?? 0);
        $lineCount  = (int)($details['line_count'] ?? 0);
        $notes      = trim((string)($details['notes'] ?? ''));
        $url        = (string)($details['url'] ?? '');

        if ($url === '') {
            return 'Confirmation link could not be generated.';
        }

        $safeName  = htmlspecialchars($normalizedName, ENT_QUOTES, 'UTF-8');
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeNotes = $notes === '' ? '' : nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'));

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Purchase Order #{$poId}</title>
</head>
<body style="margin:0;padding:0;background:#fff7ec;font-family:'Segoe UI',Tahoma,Geneva,sans-serif;color:#2b2b2b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff7ec;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(77,46,0,.08);">
        <tr>
          <td style="background:#4d2e00;color:#f4e9c9;padding:24px 28px;font-family:'Lobster','Brush Script MT',cursive;font-size:28px;letter-spacing:.5px;">
            Guillermo's Café
            <span style="float:right;font-family:'Segoe UI',sans-serif;font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;opacity:.8;padding-top:10px;">Supplier Portal</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px 28px 8px;">
            <h1 style="margin:0 0 8px;color:#4d2e00;font-size:22px;">Purchase Order #{$poId}</h1>
            <p style="margin:0 0 18px;color:#6c5a3a;font-size:15px;line-height:1.5;">
              Hi {$safeName}, Guillermo's Café would like to place a new purchase order with <strong>{$supplier}</strong>.
              Please review the order and confirm your fulfilment time using the secure link below.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 28px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbf3e3;border-radius:12px;padding:16px 18px;">
              <tr>
                <td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;">Order Date</td>
                <td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;text-align:right;">Lines / Total</td>
              </tr>
              <tr>
                <td style="font-weight:600;color:#4d2e00;font-size:15px;">{$orderDate}</td>
                <td style="font-weight:600;color:#4d2e00;font-size:15px;text-align:right;">{$lineCount} item(s) · ₱
HTML;
        $htmlBody .= number_format($total, 2);
        $htmlBody .= <<<HTML
</td>
              </tr>
            </table>
          </td>
        </tr>
HTML;

        if ($safeNotes !== '') {
            $htmlBody .= <<<HTML
        <tr>
          <td style="padding:18px 28px 0;">
            <div style="background:#fff7ec;border-left:4px solid #c4882a;padding:12px 14px;border-radius:6px;font-size:14px;color:#5a4423;">
              <strong>Notes from buyer:</strong><br>{$safeNotes}
            </div>
          </td>
        </tr>
HTML;
        }

        $htmlBody .= <<<HTML
        <tr>
          <td align="center" style="padding:28px;">
            <a href="{$safeUrl}"
               style="display:inline-block;background:#4d2e00;color:#fff;text-decoration:none;font-weight:600;padding:14px 28px;border-radius:10px;font-size:16px;letter-spacing:.02em;">
               Review &amp; Confirm Order
            </a>
            <p style="margin:14px 0 0;font-size:12px;color:#8a7250;">
              Or copy this link into your browser:<br>
              <span style="word-break:break-all;color:#5a4423;">{$safeUrl}</span>
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 28px 24px;font-size:12px;color:#8a7250;line-height:1.6;">
            This link is unique to your business and should not be shared. No login is required —
            click the button, choose how many days until delivery, and you're done.
          </td>
        </tr>
        <tr>
          <td style="background:#fbf3e3;padding:16px 28px;font-size:11px;color:#8a7250;text-align:center;">
            Guillermo's Café · Supplier Coordination Team
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $altBody = "Hi {$normalizedName},\n\n"
            . "Guillermo's Café has issued Purchase Order #{$poId} to {$details['supplier_name']}.\n"
            . "Please confirm the order using this secure link:\n\n{$url}\n\n"
            . "Thank you,\nGuillermo's Café";

        $mailConfig = self::mailConfig();
        $mail = self::buildMailer($mailConfig);

        try {
            $mail->addAddress($sanitizedEmail, $normalizedName);
            $mail->isHTML(true);
            $mail->Subject = "Guillermo's Café · Purchase Order #{$poId} - Action Required";
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody;

            self::logEvent(sprintf('Sending supplier PO link to %s for PO #%d', $sanitizedEmail, $poId));
            $mail->send();
            self::logEvent(sprintf('Supplier PO link sent successfully to %s for PO #%d', $sanitizedEmail, $poId));
            return true;
        } catch (PHPMailerException $e) {
            $errorMessage = 'Failed to send supplier PO email. ' . $e->getMessage();
            if (!empty($mail->ErrorInfo)) {
                $errorMessage .= ' | Mailer error: ' . $mail->ErrorInfo;
            }
            self::logEvent($errorMessage);
            return $errorMessage;
        }
    }

    /**
     * Send a notification confirming materials were received.
     *
     * @param array $details {
     *     po_id: int, supplier_name: string, received_date: string,
     *     line_count: int, total_amount: float, url: string
     * }
     */
    public static function sendSupplierPoReceivedEmail(string $email, string $name, array $details): bool|string
    {
        $sanitizedEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEvent('Invalid supplier email for PO receipt: ' . $email);
            return 'Invalid recipient email address.';
        }

        $normalizedName = trim($name) === '' ? 'Supplier' : trim($name);
        $poId        = (int)($details['po_id'] ?? 0);
        $supplier    = htmlspecialchars((string)($details['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $receivedOn  = htmlspecialchars((string)($details['received_date'] ?? date('M d, Y')), ENT_QUOTES, 'UTF-8');
        $total       = (float)($details['total_amount'] ?? 0);
        $lineCount   = (int)($details['line_count'] ?? 0);
        $url         = (string)($details['url'] ?? '');
        $safeName    = htmlspecialchars($normalizedName, ENT_QUOTES, 'UTF-8');
        $safeUrl     = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $totalFmt    = number_format($total, 2);

        $linkBlock = $safeUrl === '' ? '' : <<<HTML
        <tr>
          <td align="center" style="padding:20px 28px 28px;">
            <a href="{$safeUrl}"
               style="display:inline-block;background:#4d2e00;color:#fff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:10px;font-size:14px;">
              View Order Details
            </a>
          </td>
        </tr>
HTML;

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>PO #{$poId} Received</title></head>
<body style="margin:0;padding:0;background:#fff7ec;font-family:'Segoe UI',Tahoma,Geneva,sans-serif;color:#2b2b2b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff7ec;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(77,46,0,.08);">
        <tr>
          <td style="background:#4d2e00;color:#f4e9c9;padding:24px 28px;font-family:'Lobster','Brush Script MT',cursive;font-size:28px;letter-spacing:.5px;">
            Guillermo's Café
            <span style="float:right;font-family:'Segoe UI',sans-serif;font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;opacity:.8;padding-top:10px;">Supplier Portal</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px 28px 8px;">
            <div style="display:inline-block;background:#d6f0e2;color:#14633c;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:14px;">
              ✓ Received
            </div>
            <h1 style="margin:0 0 8px;color:#4d2e00;font-size:22px;">Materials received — PO #{$poId}</h1>
            <p style="margin:0 0 18px;color:#6c5a3a;font-size:15px;line-height:1.5;">
              Hi {$safeName}, this is to confirm that Guillermo's Café has received the materials from <strong>{$supplier}</strong>.
              Thank you for fulfilling this order.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 28px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbf3e3;border-radius:12px;padding:16px 18px;">
              <tr>
                <td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;">Received On</td>
                <td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;text-align:right;">Lines / Total</td>
              </tr>
              <tr>
                <td style="font-weight:600;color:#4d2e00;font-size:15px;">{$receivedOn}</td>
                <td style="font-weight:600;color:#4d2e00;font-size:15px;text-align:right;">{$lineCount} item(s) · ₱{$totalFmt}</td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 28px 0;font-size:13px;color:#6c5a3a;line-height:1.55;">
            If anything in the shipment does not match the agreed order — damaged goods, short quantities,
            wrong items, or expired stock — we'll reach out shortly with a refund or replacement request
            using this same secure link.
          </td>
        </tr>
        {$linkBlock}
        <tr>
          <td style="background:#fbf3e3;padding:16px 28px;font-size:11px;color:#8a7250;text-align:center;">
            Guillermo's Café · Supplier Coordination Team
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        $altBody = "Hi {$normalizedName},\n\nGuillermo's Café has received the materials for PO #{$poId} from {$details['supplier_name']} on {$details['received_date']}.\nThank you.\n";

        $mailConfig = self::mailConfig();
        $mail = self::buildMailer($mailConfig);
        try {
            $mail->addAddress($sanitizedEmail, $normalizedName);
            $mail->isHTML(true);
            $mail->Subject = "Guillermo's Café · PO #{$poId} received - thank you";
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody;
            self::logEvent(sprintf('Sending PO received email to %s for PO #%d', $sanitizedEmail, $poId));
            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            $errorMessage = 'Failed to send PO received email. ' . $e->getMessage();
            if (!empty($mail->ErrorInfo)) { $errorMessage .= ' | Mailer error: ' . $mail->ErrorInfo; }
            self::logEvent($errorMessage);
            return $errorMessage;
        }
    }

    /**
     * Notify a supplier that an issue (damage / short qty / etc.) was filed
     * against one of their delivered POs and a refund or replacement is being
     * requested.
     *
     * @param array $details {
     *     po_id: int, supplier_name: string, item_name: string,
     *     issue_type: string, action: string, quantity_affected: float,
     *     buyer_notes: string, url: string
     * }
     */
    public static function sendSupplierIssueEmail(string $email, string $name, array $details): bool|string
    {
        $sanitizedEmail = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        if (!filter_var($sanitizedEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEvent('Invalid supplier email for issue notification: ' . $email);
            return 'Invalid recipient email address.';
        }

        $normalizedName = trim($name) === '' ? 'Supplier' : trim($name);
        $poId        = (int)($details['po_id'] ?? 0);
        $supplier    = htmlspecialchars((string)($details['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $itemName    = htmlspecialchars((string)($details['item_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $issueType   = htmlspecialchars(str_replace('_', ' ', (string)($details['issue_type'] ?? 'Issue')), ENT_QUOTES, 'UTF-8');
        $action      = htmlspecialchars(str_replace('_', ' ', (string)($details['action'] ?? 'Replacement')), ENT_QUOTES, 'UTF-8');
        $qty         = (float)($details['quantity_affected'] ?? 0);
        $buyerNotes  = trim((string)($details['buyer_notes'] ?? ''));
        $url         = (string)($details['url'] ?? '');
        $safeName    = htmlspecialchars($normalizedName, ENT_QUOTES, 'UTF-8');
        $safeUrl     = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeNotes   = $buyerNotes === '' ? '' : nl2br(htmlspecialchars($buyerNotes, ENT_QUOTES, 'UTF-8'));
        $qtyFmt      = number_format($qty, 2);

        $notesBlock = $safeNotes === '' ? '' : <<<HTML
        <tr>
          <td style="padding:18px 28px 0;">
            <div style="background:#fff7ec;border-left:4px solid #c4882a;padding:12px 14px;border-radius:6px;font-size:14px;color:#5a4423;">
              <strong>Details from buyer:</strong><br>{$safeNotes}
            </div>
          </td>
        </tr>
HTML;

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>PO #{$poId} - Action Required</title></head>
<body style="margin:0;padding:0;background:#fff7ec;font-family:'Segoe UI',Tahoma,Geneva,sans-serif;color:#2b2b2b;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff7ec;padding:32px 16px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(77,46,0,.08);">
        <tr>
          <td style="background:#4d2e00;color:#f4e9c9;padding:24px 28px;font-family:'Lobster','Brush Script MT',cursive;font-size:28px;letter-spacing:.5px;">
            Guillermo's Café
            <span style="float:right;font-family:'Segoe UI',sans-serif;font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;opacity:.8;padding-top:10px;">Supplier Portal</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px 28px 8px;">
            <div style="display:inline-block;background:#fde2e2;color:#9b1c1c;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:14px;">
              ⚠ Issue Reported
            </div>
            <h1 style="margin:0 0 8px;color:#4d2e00;font-size:22px;">Action requested on PO #{$poId}</h1>
            <p style="margin:0 0 18px;color:#6c5a3a;font-size:15px;line-height:1.5;">
              Hi {$safeName}, we found an issue with the materials received from <strong>{$supplier}</strong>
              and would like to request a <strong>{$action}</strong>.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 28px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fbf3e3;border-radius:12px;padding:16px 18px;">
              <tr><td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;">Item</td><td style="font-weight:600;color:#4d2e00;font-size:14px;text-align:right;">{$itemName}</td></tr>
              <tr><td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;padding-top:10px;">Issue</td><td style="font-weight:600;color:#9b1c1c;font-size:14px;text-align:right;padding-top:10px;">{$issueType}</td></tr>
              <tr><td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;padding-top:10px;">Quantity affected</td><td style="font-weight:600;color:#4d2e00;font-size:14px;text-align:right;padding-top:10px;">{$qtyFmt}</td></tr>
              <tr><td style="font-size:12px;color:#8a7250;text-transform:uppercase;letter-spacing:.08em;padding-bottom:4px;padding-top:10px;">Action requested</td><td style="font-weight:700;color:#4d2e00;font-size:14px;text-align:right;padding-top:10px;">{$action}</td></tr>
            </table>
          </td>
        </tr>
        {$notesBlock}
        <tr>
          <td align="center" style="padding:28px;">
            <a href="{$safeUrl}"
               style="display:inline-block;background:#4d2e00;color:#fff;text-decoration:none;font-weight:600;padding:14px 28px;border-radius:10px;font-size:16px;letter-spacing:.02em;">
               View &amp; Reply on Portal
            </a>
            <p style="margin:14px 0 0;font-size:12px;color:#8a7250;">
              You can leave a reply on the same secure link previously sent for this order.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#fbf3e3;padding:16px 28px;font-size:11px;color:#8a7250;text-align:center;">
            Guillermo's Café · Supplier Coordination Team
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;

        $altBody = "Hi {$normalizedName},\n\n"
            . "Guillermo's Café has reported a {$issueType} issue with PO #{$poId} ({$details['supplier_name']}).\n"
            . "Item: {$details['item_name']} · Quantity affected: {$qtyFmt}\n"
            . "Action requested: {$action}\n\n"
            . ($buyerNotes === '' ? '' : "Notes: {$buyerNotes}\n\n")
            . "View on portal: {$url}\n";

        $mailConfig = self::mailConfig();
        $mail = self::buildMailer($mailConfig);
        try {
            $mail->addAddress($sanitizedEmail, $normalizedName);
            $mail->isHTML(true);
            $mail->Subject = "Guillermo's Café · PO #{$poId} - {$action} requested";
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody;
            self::logEvent(sprintf('Sending PO issue email to %s for PO #%d', $sanitizedEmail, $poId));
            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            $errorMessage = 'Failed to send PO issue email. ' . $e->getMessage();
            if (!empty($mail->ErrorInfo)) { $errorMessage .= ' | Mailer error: ' . $mail->ErrorInfo; }
            self::logEvent($errorMessage);
            return $errorMessage;
        }
    }

    private static function logEvent(...$args): void
    {
        $logMessage = (string)($args[0] ?? '');
        // Use system temp dir to avoid collisions with project files that may be regular files.
        $logDir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'guillermos_logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . DIRECTORY_SEPARATOR . 'email.log';
        $timestamp = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        @file_put_contents($logFile, sprintf("[%s] %s%s", $timestamp, $logMessage, PHP_EOL), FILE_APPEND);
    }
}
