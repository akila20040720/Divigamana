CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mobile_e164 VARCHAR(16) NOT NULL UNIQUE,
    ideamart_subscriber_id VARCHAR(120) NULL UNIQUE,
    mspace_subscriber_id VARCHAR(120) NULL UNIQUE,
    otp_verified_demo TINYINT(1) NOT NULL DEFAULT 0,
    phone_verified_real TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    account_status ENUM('active','blocked') NOT NULL DEFAULT 'active',
    blocked_at DATETIME NULL,
    last_login_at DATETIME NULL,
    preferred_locale VARCHAR(10) NOT NULL DEFAULT 'si-LK',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_account_status_created (account_status, created_at),
    INDEX idx_users_last_login_at (last_login_at),
    INDEX idx_users_created_id (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE authentication_otps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    mobile_e164 VARCHAR(16) NOT NULL,
    code_hash VARCHAR(255) NULL,
    delivery_mode ENUM('demo','sms') NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otps_mobile_created (mobile_e164, created_at),
    CONSTRAINT fk_otps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','inactive','cancelled','expired','pending') NOT NULL,
    is_simulated TINYINT(1) NOT NULL DEFAULT 1,
    provider ENUM('simulated','ideamart','mspace') NOT NULL DEFAULT 'simulated',
    provider_status VARCHAR(30) NULL,
    frequency VARCHAR(60) NULL,
    accepted_at DATETIME NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    notified_at VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subscriptions_user_status (user_id, status, ends_at),
    INDEX idx_subscriptions_provider_status (provider, provider_status),
    INDEX idx_subscriptions_provider_accepted (provider, is_simulated, accepted_at),
    INDEX idx_subscriptions_user_latest (user_id, id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_catalog (
    id TINYINT UNSIGNED PRIMARY KEY,
    slug VARCHAR(60) NOT NULL UNIQUE,
    price_lkr DECIMAL(10,2) NOT NULL DEFAULT 20,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    configuration_json JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE horoscope_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    service_id TINYINT UNSIGNED NOT NULL,
    status ENUM('processing','completed','failed') NOT NULL,
    locale VARCHAR(10) NOT NULL,
    questionnaire_json JSON NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_requests_user_idempotency (user_id, idempotency_key),
    INDEX idx_requests_service_status (service_id, status, created_at),
    CONSTRAINT fk_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_service FOREIGN KEY (service_id) REFERENCES service_catalog(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE horoscope_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL UNIQUE,
    result_json JSON NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_results_request FOREIGN KEY (request_id) REFERENCES horoscope_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'LKR',
    status ENUM('simulated_confirmed','authorized','paid','failed','refunded') NOT NULL,
    is_simulated TINYINT(1) NOT NULL DEFAULT 1,
    provider ENUM('simulated','ideamart','mspace') NOT NULL DEFAULT 'simulated',
    external_reference VARCHAR(120) NULL,
    provider_reference VARCHAR(120) NULL,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_provider_external (provider, external_reference),
    INDEX idx_payments_status_created (status, created_at),
    INDEX idx_payments_real_created (provider, is_simulated, created_at, status),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_payments_request FOREIGN KEY (request_id) REFERENCES horoscope_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_usage_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_usage_created (created_at),
    CONSTRAINT fk_ai_usage_request FOREIGN KEY (request_id) REFERENCES horoscope_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rahu_kalaya_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    local_date DATE NOT NULL,
    location_key VARCHAR(80) NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    source_details VARCHAR(255) NOT NULL,
    calculated_at DATETIME NOT NULL,
    UNIQUE KEY uq_rahu_date_location (local_date, location_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consent_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    consent_type VARCHAR(80) NOT NULL,
    consent_text TEXT NOT NULL,
    locale VARCHAR(10) NOT NULL,
    accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash CHAR(64) NULL,
    INDEX idx_consent_user_type (user_id, consent_type),
    CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(100) NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_event_created (event_type, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
