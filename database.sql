-- Stores admin credentials
CREATE TABLE admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) COMMENT 'Stores admin credentials';

-- Initial admin insert with bcrypt hash
INSERT INTO admins (first_name, last_name, username, email, password)
VALUES ('Hitesh', 'Rajpurohit', 'HiteshRajpurohit', 'iamrajpurohithitesh@gmail.com', '$2y$10$4fXz4vY9Qz3Xz6Y7z8X9Y.u7vY9Qz3Xz6Y7z8X9Y.u7vY9Qz3Xz6Y');

-- Stores website and Telegram user data
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255),
    username VARCHAR(255) UNIQUE,
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    telegram_id BIGINT UNIQUE,
    cluster INT DEFAULT 0 COMMENT 'For AI clustering',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_interaction TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    update_count INT UNSIGNED DEFAULT 0,
    language_code VARCHAR(10),
    INDEX idx_email (email),
    INDEX idx_telegram_id (telegram_id),
    INDEX idx_last_interaction (last_interaction),
    INDEX idx_created_at (created_at)
) COMMENT 'Stores user data for website and Telegram';

-- Stores product details
CREATE TABLE products (
    asin VARCHAR(20) PRIMARY KEY,
    merchant ENUM('amazon', 'flipkart') NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(100),
    brand VARCHAR(100),
    highest_price DECIMAL(10,2),
    current_price DECIMAL(10,2),
    lowest_price DECIMAL(10,2),
    website_url VARCHAR(255),
    affiliate_link VARCHAR(255),
    price_history JSON,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    stock_status ENUM('in_stock', 'out_of_stock') DEFAULT 'in_stock',
    stock_quantity INT,
    out_of_stock_since TIMESTAMP NULL,
    image_path VARCHAR(255),
    rating DECIMAL(3,1),
    rating_count INT,
    is_flash_deal BOOLEAN DEFAULT FALSE,
    update_count INT UNSIGNED DEFAULT 0,
    tracking_count INT UNSIGNED DEFAULT 0,
    INDEX idx_asin (asin),
    INDEX idx_merchant (merchant),
    INDEX idx_category_merchant (category, merchant),
    INDEX idx_stock (stock_status, stock_quantity),
    INDEX idx_price (current_price, highest_price),
    INDEX idx_rating (rating),
    INDEX idx_tracking (tracking_count),
    INDEX idx_last_updated (last_updated)
) COMMENT 'Stores product details from Amazon and Flipkart';

-- Stores user-tracked products
CREATE TABLE user_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_asin VARCHAR(20) NOT NULL,
    product_url VARCHAR(255),
    price_history_url VARCHAR(255),
    price_threshold DECIMAL(10,2),
    is_favorite BOOLEAN DEFAULT FALSE,
    email_alert BOOLEAN DEFAULT FALSE,
    push_alert BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_product_asin (product_asin)
) COMMENT 'Stores user-tracked products with alert preferences';

-- Stores tracking request limits
CREATE TABLE user_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    asin VARCHAR(20) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) COMMENT 'Tracks user tracking requests for rate limiting';

-- Stores Goldbox products (Amazon deals)
CREATE TABLE goldbox_products (
    asin VARCHAR(20) PRIMARY KEY,
    merchant ENUM('amazon') NOT NULL DEFAULT 'amazon',
    name VARCHAR(255) NOT NULL,
    current_price DECIMAL(10,2),
    discount_percentage INT,
    affiliate_link VARCHAR(255),
    image_url VARCHAR(255),
    last_updated DATETIME,
    INDEX idx_asin (asin),
    INDEX idx_discount_percentage (discount_percentage)
) COMMENT 'Stores Amazon Goldbox deals';

-- Stores Flipbox products (Flipkart deals)
CREATE TABLE flipbox_products (
    asin VARCHAR(20) PRIMARY KEY,
    merchant ENUM('flipkart') NOT NULL DEFAULT 'flipkart',
    name VARCHAR(255) NOT NULL,
    current_price DECIMAL(10,2),
    discount_percentage INT,
    affiliate_link VARCHAR(255),
    image_url VARCHAR(255),
    last_updated DATETIME,
    INDEX idx_asin (asin),
    INDEX idx_discount_percentage (discount_percentage)
) COMMENT 'Stores Flipkart deals';

-- Stores HotDeals bot user data
CREATE TABLE hotdealsbot_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT UNIQUE NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255),
    username VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_interaction TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    update_count INT UNSIGNED DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    language_code VARCHAR(10),
    FOREIGN KEY (telegram_id) REFERENCES users(telegram_id) ON DELETE CASCADE,
    INDEX idx_telegram_id (telegram_id),
    INDEX idx_last_interaction (last_interaction),
    INDEX idx_created_at (created_at)
) COMMENT 'Stores HotDeals bot user data';

-- Stores HotDeals user category preferences
CREATE TABLE hotdealsbot_user_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(100) NOT NULL,
    merchant ENUM('amazon', 'flipkart', 'both') NOT NULL DEFAULT 'both',
    price_range DECIMAL(10,2),
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES hotdealsbot_users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_category (category),
    INDEX idx_active (active)
) COMMENT 'Stores HotDeals user category preferences';

-- Stores AI-generated price predictions
CREATE TABLE predictions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asin VARCHAR(20) NOT NULL,
    predicted_price DECIMAL(10,2),
    prediction_date DATE,
    period VARCHAR(20),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_asin (asin),
    INDEX idx_prediction_date (prediction_date)
) COMMENT 'Stores AI-generated price predictions';

-- Stores detected price drop patterns
CREATE TABLE patterns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asin VARCHAR(20) NOT NULL,
    pattern_description VARCHAR(255),
    confidence DECIMAL(5,2),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_asin (asin)
) COMMENT 'Stores detected price drop patterns';

-- Stores festival and sale event data
CREATE TABLE festivals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(100) NOT NULL,
    event_date DATE NOT NULL,
    event_type ENUM('festival', 'sale') NOT NULL,
    offers_likely BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_date (event_date)
) COMMENT 'Stores festival and sale events for AI predictions';

-- Stores user behavior for AI analysis
CREATE TABLE user_behavior (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    asin VARCHAR(20),
    is_favorite BOOLEAN,
    is_ai_suggested BOOLEAN,
    interaction_type ENUM('buy_now', 'price_history', 'tracking', 'deal_suggested', 'favorite'),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (asin) REFERENCES products(asin) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_asin (asin)
) COMMENT 'Stores user behavior for AI analysis';

-- Stores email subscription preferences
CREATE TABLE email_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    subscribed ENUM('yes', 'no') DEFAULT 'yes',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) COMMENT 'Tracks email subscription preferences for offers';

-- Stores one-time passwords for authentication
CREATE TABLE otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) COMMENT 'Stores OTPs for authentication';

-- Stores push notification subscriptions
CREATE TABLE push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    product_asin VARCHAR(20),
    subscription JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (product_asin) REFERENCES products(asin) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_product_asin (product_asin)
) COMMENT 'Stores push notification subscriptions';

-- Stores VAPID keys for push notifications
CREATE TABLE vapid_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_key TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
) COMMENT 'Stores VAPID keys for push notifications';