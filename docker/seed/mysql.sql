SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS ldb_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ldb_demo;

CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  country CHAR(2) NOT NULL,
  locale VARCHAR(10) NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  attributes JSON NULL,
  INDEX idx_products_name (name)
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  status ENUM('new','paid','shipped','cancelled') NOT NULL DEFAULT 'new',
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  message VARCHAR(255) NOT NULL,
  logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO customers (name, email, country, locale, notes) VALUES
 ('山田 太郎', 'taro@example.jp', 'JP', 'ja', 'こんにちは、世界'),
 ('王小明', 'wang@example.cn', 'CN', 'zh-Hans', '简体中文：你好，世界'),
 ('陳大文', 'chen@example.tw', 'TW', 'zh-Hant', '繁體中文：你好，世界'),
 ('김민준', 'minjun@example.kr', 'KR', 'ko', '안녕하세요, 세계'),
 ('Nguyễn Thị Hương', 'huong@example.vn', 'VN', 'vi', 'Xin chào thế giới — Việt Nam'),
 ('Иван Петров', 'ivan@example.ru', 'RU', 'ru', 'Привет, мир'),
 ('محمد الأحمد', 'mohammed@example.sa', 'SA', 'ar', 'مرحبا بالعالم'),
 ('דוד כהן', 'david@example.il', 'IL', 'he', 'שלום עולם'),
 ('Emoji Fan 🎉', 'emoji@example.com', 'US', 'en', 'Party 🎉🚀🐙 😀 👨‍👩‍👧‍👦');

INSERT INTO products (sku, name, price, stock, attributes) VALUES
 ('TEA-001', '抹茶 (Matcha) 100g', 24.50, 120, '{"origin":"京都","organic":true}'),
 ('TEA-002', '龙井茶 Longjing', 18.00, 80, '{"origin":"杭州"}'),
 ('FOOD-001', 'Phở bò gói', 3.20, 500, '{"origin":"Hà Nội"}'),
 ('FOOD-002', 'Борщ консервированный', 4.75, 60, NULL),
 ('FOOD-003', 'كنافة نابلسية', 12.90, 40, '{"origin":"نابلس"}'),
 ('FOOD-004', 'חומוס מוכן', 5.40, 90, NULL),
 ('SNACK-001', '김치 Kimchi 🌶️', 7.80, 200, '{"spicy":true}');

INSERT INTO orders (customer_id, status, total) VALUES
 (1,'paid',49.00),(2,'shipped',18.00),(5,'new',9.60),(6,'paid',4.75),(7,'cancelled',12.90),(9,'paid',15.60);

INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES
 (1,1,2,24.50),(2,2,1,18.00),(3,3,3,3.20),(4,4,1,4.75),(5,5,1,12.90),(6,7,2,7.80);

CREATE VIEW v_order_summary AS
SELECT o.id AS order_id, c.name AS customer, c.country, o.status, o.total,
       COUNT(i.id) AS line_count, o.ordered_at
FROM orders o
JOIN customers c ON c.id = o.customer_id
LEFT JOIN order_items i ON i.order_id = o.id
GROUP BY o.id, c.name, c.country, o.status, o.total, o.ordered_at;

DELIMITER $$
CREATE TRIGGER trg_orders_status_audit AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
  IF NEW.status <> OLD.status THEN
    INSERT INTO audit_log (order_id, message)
    VALUES (NEW.id, CONCAT('status ', OLD.status, ' -> ', NEW.status));
  END IF;
END$$

CREATE FUNCTION fn_order_total(p_order_id INT UNSIGNED) RETURNS DECIMAL(12,2)
READS SQL DATA
BEGIN
  RETURN (SELECT COALESCE(SUM(quantity * unit_price), 0) FROM order_items WHERE order_id = p_order_id);
END$$

CREATE PROCEDURE sp_recalc_order(IN p_order_id INT UNSIGNED)
BEGIN
  UPDATE orders SET total = fn_order_total(p_order_id) WHERE id = p_order_id;
END$$
DELIMITER ;
