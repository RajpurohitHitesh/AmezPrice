<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Alert</title>
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
            margin-bottom: 16px;
        }
        img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2A3AFF;
            color: #FFFFFF;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 8px 0;
        }
        .btn:hover {
            background: #5868FF;
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
        <h1>🚨 Price Alert!</h1>
        <img src="{{image_path}}" alt="{{name}}">
        <p><strong>{{name}}</strong></p>
        <p>Previous Price: ₹{{previous_price}}</p>
        <p>Current Price: ₹{{current_price}} ({{change}}% {{direction}})</p>
        <p>🔥 {{tracker_count}} users are tracking this!</p>
        <a href="{{affiliate_link}}" class="btn">Buy Now</a><br>
        <a href="{{history_url}}" class="btn">View Price History</a>
        <div class="footer">
            &copy; <?php echo date('Y'); ?> AmezPrice. All rights reserved.<br>
            <a href="https://amezprice.com">Visit our website</a> | <a href="https://amezprice.com/user/account.php?unsubscribe=true">Unsubscribe</a>
        </div>
    </div>
</body>
</html>