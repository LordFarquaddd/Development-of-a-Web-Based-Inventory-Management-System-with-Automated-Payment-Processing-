DROP DATABASE IF EXISTS uniform_store_mgmt_system;
CREATE DATABASE uniform_store_mgmt_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uniform_store_mgmt_system;

SET FOREIGN_KEY_CHECKS = 0;

-- USERS & ACCESS CONTROL

CREATE TABLE roles (
    role_id         INT AUTO_INCREMENT PRIMARY KEY,
    role_name       VARCHAR(50) NOT NULL UNIQUE,           -- Admin, Staff, Customer
    description     VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE users (
    user_id         INT AUTO_INCREMENT PRIMARY KEY,
    role_id         INT NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    last_name       VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    contact_number  VARCHAR(20),
    address         VARCHAR(255),
    status          ENUM('active','inactive','banned') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    log_id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT,
    action          VARCHAR(100) NOT NULL,                  -- e.g. LOGIN, CREATE_ORDER, PAYMENT_CONFIRMED
    table_name      VARCHAR(100),
    record_id       INT,
    details         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 2. CATALOG: SCHOOLS, CATEGORIES, PRODUCTS, VARIANTS

CREATE TABLE schools (
    school_id       INT AUTO_INCREMENT PRIMARY KEY,
    school_name     VARCHAR(150) NOT NULL,
    address         VARCHAR(255),
    contact_person  VARCHAR(100),
    contact_number  VARCHAR(20)
) ENGINE=InnoDB;

CREATE TABLE categories (
    category_id     INT AUTO_INCREMENT PRIMARY KEY,
    category_name   VARCHAR(100) NOT NULL,                  -- School Uniform, PE Uniform, Corporate, Scouting, Accessories
    description     VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE products (
    product_id      INT AUTO_INCREMENT PRIMARY KEY,
    category_id     INT NOT NULL,
    school_id       INT NULL,                               -- NULL if generic (not school-specific)
    product_name    VARCHAR(150) NOT NULL,
    description     TEXT,
    base_price      DECIMAL(10,2) NOT NULL,
    image_url       VARCHAR(255),
    status          ENUM('active','discontinued') NOT NULL DEFAULT 'active',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    FOREIGN KEY (school_id) REFERENCES schools(school_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Each purchasable variant (size/color combo) with its own stock and SKU
CREATE TABLE product_variants (
    variant_id      INT AUTO_INCREMENT PRIMARY KEY,
    product_id      INT NOT NULL,
    sku             VARCHAR(50) NOT NULL UNIQUE,
    size            VARCHAR(20) NOT NULL,                   -- XS, S, M, L, XL, or numeric size
    color           VARCHAR(30),
    price           DECIMAL(10,2) NOT NULL,
    stock_quantity  INT NOT NULL DEFAULT 0,
    reorder_level   INT NOT NULL DEFAULT 10,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    CHECK (stock_quantity >= 0)
) ENGINE=InnoDB;

CREATE TABLE inventory_logs (
    log_id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    variant_id      INT NOT NULL,
    change_qty      INT NOT NULL,                           -- positive = stock in, negative = stock out
    reason          VARCHAR(100) NOT NULL,                  -- SALE, RESTOCK, ADJUSTMENT, RETURN
    reference_table VARCHAR(50),                            -- e.g. 'orders', 'purchase_orders'
    reference_id    INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (variant_id) REFERENCES product_variants(variant_id)
) ENGINE=InnoDB;

-- 3. SUPPLIERS & PURCHASE ORDERS (restocking)

CREATE TABLE suppliers (
    supplier_id     INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name   VARCHAR(150) NOT NULL,
    contact_person  VARCHAR(100),
    phone           VARCHAR(20),
    email           VARCHAR(150),
    address         VARCHAR(255)
) ENGINE=InnoDB;

CREATE TABLE purchase_orders (
    po_id           INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id     INT NOT NULL,
    order_date      DATE NOT NULL,
    status          ENUM('pending','received','cancelled') NOT NULL DEFAULT 'pending',
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_by      INT,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE purchase_order_items (
    po_item_id      INT AUTO_INCREMENT PRIMARY KEY,
    po_id           INT NOT NULL,
    variant_id      INT NOT NULL,
    quantity        INT NOT NULL,
    unit_cost       DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES product_variants(variant_id)
) ENGINE=InnoDB;

-- 4. SHOPPING CART

CREATE TABLE carts (
    cart_id         INT AUTO_INCREMENT PRIMARY KEY,
    customer_id     INT NOT NULL UNIQUE,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cart_items (
    cart_item_id    INT AUTO_INCREMENT PRIMARY KEY,
    cart_id         INT NOT NULL,
    variant_id      INT NOT NULL,
    quantity        INT NOT NULL DEFAULT 1,
    added_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cart_id) REFERENCES carts(cart_id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES product_variants(variant_id),
    UNIQUE KEY uq_cart_variant (cart_id, variant_id),
    CHECK (quantity > 0)
) ENGINE=InnoDB;

-- 5. PROMOTIONS / DISCOUNTS

CREATE TABLE discounts (
    discount_id     INT AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(50) NOT NULL UNIQUE,
    description     VARCHAR(255),
    discount_type   ENUM('percentage','fixed_amount') NOT NULL,
    discount_value  DECIMAL(10,2) NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    usage_limit     INT DEFAULT NULL,
    times_used      INT NOT NULL DEFAULT 0,
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;

-- 6. ORDERS

CREATE TABLE orders (
    order_id        INT AUTO_INCREMENT PRIMARY KEY,
    order_number    VARCHAR(30) NOT NULL UNIQUE,
    customer_id     INT NOT NULL,
    discount_id     INT NULL,
    order_date      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    order_status    ENUM('pending','processing','shipped','delivered','completed','cancelled')
                        NOT NULL DEFAULT 'pending',
    payment_status  ENUM('unpaid','pending','paid','failed','refunded') NOT NULL DEFAULT 'unpaid',
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    shipping_fee    DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
    shipping_address VARCHAR(255),
    notes           VARCHAR(255),
    FOREIGN KEY (customer_id) REFERENCES users(user_id),
    FOREIGN KEY (discount_id) REFERENCES discounts(discount_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE order_items (
    order_item_id   INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL,
    variant_id      INT NOT NULL,
    quantity        INT NOT NULL,
    unit_price      DECIMAL(10,2) NOT NULL,
    line_subtotal   DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES product_variants(variant_id),
    CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE shipments (
    shipment_id     INT AUTO_INCREMENT PRIMARY KEY,
    order_id        INT NOT NULL UNIQUE,
    courier         VARCHAR(100),
    tracking_number VARCHAR(100),
    shipped_at      TIMESTAMP NULL,
    delivered_at    TIMESTAMP NULL,
    status          ENUM('preparing','in_transit','delivered','returned') NOT NULL DEFAULT 'preparing',
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 7. AUTOMATED PAYMENT PROCESSING

CREATE TABLE payment_methods (
    payment_method_id  INT AUTO_INCREMENT PRIMARY KEY,
    method_name         VARCHAR(50) NOT NULL UNIQUE,        -- GCash, Maya, Credit/Debit Card, Bank Transfer, COD
    is_online           BOOLEAN NOT NULL DEFAULT TRUE,
    status               ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB;

CREATE TABLE payments (
    payment_id          INT AUTO_INCREMENT PRIMARY KEY,
    order_id             INT NOT NULL,
    payment_method_id    INT NOT NULL,
    amount                DECIMAL(12,2) NOT NULL,
    currency              VARCHAR(10) NOT NULL DEFAULT 'PHP',
    gateway_name          VARCHAR(50),                       -- e.g. PayMongo, Stripe, PayPal, Xendit
    gateway_transaction_id VARCHAR(150),                     -- external reference/txn id from payment gateway
    payment_status        ENUM('initiated','pending','completed','failed','refunded')
                                NOT NULL DEFAULT 'initiated',
    initiated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at           TIMESTAMP NULL,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(payment_method_id)
) ENGINE=InnoDB;

-- Logs every callback/webhook event from the payment gateway (automated processing trail)
CREATE TABLE payment_gateway_logs (
    log_id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id           INT NOT NULL,
    event_type            VARCHAR(50) NOT NULL,               -- e.g. payment.paid, payment.failed
    raw_payload            JSON,
    received_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE refunds (
    refund_id            INT AUTO_INCREMENT PRIMARY KEY,
    payment_id            INT NOT NULL,
    amount                 DECIMAL(12,2) NOT NULL,
    reason                  VARCHAR(255),
    status                  ENUM('requested','processing','completed','rejected') NOT NULL DEFAULT 'requested',
    requested_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at            TIMESTAMP NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id)
) ENGINE=InnoDB;

-- 8. REVIEWS & NOTIFICATIONS

CREATE TABLE reviews (
    review_id       INT AUTO_INCREMENT PRIMARY KEY,
    product_id      INT NOT NULL,
    customer_id     INT NOT NULL,
    rating          TINYINT NOT NULL,
    comment         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE notifications (
    notification_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL,
    message            VARCHAR(255) NOT NULL,
    is_read             BOOLEAN NOT NULL DEFAULT FALSE,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- 9. INDEXES FOR PERFORMANCE

CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_school ON products(school_id);
CREATE INDEX idx_variants_product ON product_variants(product_id);
CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_orders_status ON orders(order_status, payment_status);
CREATE INDEX idx_order_items_order ON order_items(order_id);
CREATE INDEX idx_payments_order ON payments(order_id);
CREATE INDEX idx_payments_status ON payments(payment_status);


-- 10. TRIGGERS - AUTOMATED PAYMENT & INVENTORY LOGIC


DELIMITER $$

-- When a payment record is marked 'completed', automatically:
--   1) update the related order's payment_status to 'paid'
--   2) move the order forward to 'processing' if it was still 'pending'
--   3) log the event
CREATE TRIGGER trg_payment_completed
AFTER UPDATE ON payments
FOR EACH ROW
BEGIN
    IF NEW.payment_status = 'completed' AND OLD.payment_status <> 'completed' THEN
        UPDATE orders
        SET payment_status = 'paid',
            order_status = IF(order_status = 'pending', 'processing', order_status)
        WHERE order_id = NEW.order_id;

        INSERT INTO audit_logs(user_id, action, table_name, record_id, details)
        VALUES (NULL, 'PAYMENT_COMPLETED', 'payments', NEW.payment_id,
                CONCAT('Order #', NEW.order_id, ' marked as paid via ', NEW.gateway_name));
    END IF;

    IF NEW.payment_status = 'failed' AND OLD.payment_status <> 'failed' THEN
        UPDATE orders SET payment_status = 'failed' WHERE order_id = NEW.order_id;
    END IF;
END$$

-- When a refund is completed, mark the order's payment_status as 'refunded'
CREATE TRIGGER trg_refund_completed
AFTER UPDATE ON refunds
FOR EACH ROW
BEGIN
    IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
        UPDATE orders o
        JOIN payments p ON p.order_id = o.order_id
        SET o.payment_status = 'refunded'
        WHERE p.payment_id = NEW.payment_id;
    END IF;
END$$

CREATE TRIGGER trg_order_item_stock_out
AFTER INSERT ON order_items
FOR EACH ROW
BEGIN
    UPDATE product_variants
    SET stock_quantity = stock_quantity - NEW.quantity
    WHERE variant_id = NEW.variant_id;

    INSERT INTO inventory_logs(variant_id, change_qty, reason, reference_table, reference_id)
    VALUES (NEW.variant_id, -NEW.quantity, 'SALE', 'orders', NEW.order_id);
END$$

-- Automatically increase stock and log inventory movement when a purchase order item is received
CREATE TRIGGER trg_po_item_stock_in
AFTER INSERT ON purchase_order_items
FOR EACH ROW
BEGIN
    UPDATE product_variants
    SET stock_quantity = stock_quantity + NEW.quantity
    WHERE variant_id = NEW.variant_id;

    INSERT INTO inventory_logs(variant_id, change_qty, reason, reference_table, reference_id)
    VALUES (NEW.variant_id, NEW.quantity, 'RESTOCK', 'purchase_orders', NEW.po_id);
END$$

DELIMITER ;



INSERT INTO roles (role_name, description) VALUES
('Admin', 'Full system access'),
('Staff', 'Manages orders, inventory, and payments'),
('Customer', 'Can browse and purchase uniforms');

INSERT INTO users (role_id, first_name, last_name, email, password_hash, contact_number, address) VALUES
(1, 'Maria', 'Santos', 'admin@uniformstore.ph', '$2y$10$examplehash1', '09171234567', 'Quezon City, PH'),
(3, 'Juan', 'Dela Cruz', 'juan.delacruz@email.com', '$2y$10$examplehash2', '09189876543', 'Manila, PH');

INSERT INTO categories (category_name, description) VALUES
('School Uniform', 'College uniforms'),
('PE Uniform', 'Physical education wear'),
('Accessories', 'Neckties, laces, patches, etc.');

INSERT INTO payment_methods (method_name, is_online) VALUES
('GCash', TRUE),
('Maya', TRUE),
('Credit/Debit Card', TRUE),
('Bank Transfer', TRUE),
('Cash on Delivery', FALSE);

INSERT INTO schools (school_name, address, contact_person, contact_number) VALUES
('PLM Cooperation', 'Manila City, PH', 'Coop', '028123456');

INSERT INTO products (category_id, school_id, product_name, description, base_price) VALUES
(1, 1, 'PLM Boys Polo Uniform', 'Official polo uniform, white with school patch', 450.00),
(2, NULL, 'Standard PE T-Shirt', 'Plain PE shirt, unisex', 250.00);

INSERT INTO product_variants (product_id, sku, size, color, price, stock_quantity) VALUES
(1, 'PLM-POLO-S-WHT', 'S', 'White', 450.00, 50),
(1, 'PLM-POLO-M-WHT', 'M', 'White', 450.00, 40),
(2, 'PE-SHIRT-M-BLU', 'M', 'Blue', 250.00, 100);