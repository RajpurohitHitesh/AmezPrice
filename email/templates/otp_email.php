<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your OTP</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            color: #1E293B;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 32px;
            background: #FFFFFF;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        h1 {
            font-size: 24px;
            margin-bottom: 16px;
        }
        p {
            font-size: 16px;
            margin-bottom: 24px;
        }
        .otp {
            display: inline-block;
            background: #2A3AFF;
            color: #FFFFFF;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        .footer {
            font-size: 12px;
            color: #999;
            text-align: center;
            margin-top: 32px;
        }
        .footer a {
            color: #2A3AFF;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Your Verification Code</h1>
        <p>Please use the following one-time password (OTP) to verify your action:</p>
        <div class="otp">{{otp}}</div>
        <p>This OTP is valid for 5 minutes. Do not share it with anyone.</p>
        <div class="footer">
            &copy; <?php echo date('Y'); ?> AmezPrice. All rights reserved.<br>
            <a href="https://amezprice.com">Visit our website</a>
        </div>
    </div>
</body>
</html>