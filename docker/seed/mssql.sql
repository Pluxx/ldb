-- SQL Server demo seed, applied by the "mssql-init" service (sqlcmd, UTF-8: -f 65001).
-- Idempotent: skipped when the database already exists.
IF DB_ID(N'ldb_demo') IS NOT NULL
BEGIN
  PRINT 'ldb_demo already exists, skipping seed';
  SET NOEXEC ON;
END
GO
CREATE DATABASE ldb_demo COLLATE Latin1_General_100_CI_AS_SC_UTF8;
GO
USE ldb_demo;
GO
CREATE TABLE customers (
  id INT IDENTITY(1,1) PRIMARY KEY,
  name NVARCHAR(120) NOT NULL,
  email NVARCHAR(190) NOT NULL UNIQUE,
  country CHAR(2) NOT NULL,
  locale NVARCHAR(10) NOT NULL,
  notes NVARCHAR(MAX) NULL,
  created_at DATETIMEOFFSET NOT NULL DEFAULT SYSDATETIMEOFFSET()
);

CREATE TABLE products (
  id INT IDENTITY(1,1) PRIMARY KEY,
  sku NVARCHAR(40) NOT NULL UNIQUE,
  name NVARCHAR(160) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  attributes NVARCHAR(MAX) NULL
);
CREATE INDEX idx_products_name ON products(name);

CREATE TABLE orders (
  id INT IDENTITY(1,1) PRIMARY KEY,
  customer_id INT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  status NVARCHAR(20) NOT NULL DEFAULT 'new' CHECK (status IN ('new','paid','shipped','cancelled')),
  total DECIMAL(12,2) NOT NULL DEFAULT 0,
  ordered_at DATETIME2 NOT NULL DEFAULT SYSDATETIME()
);

CREATE TABLE order_items (
  id INT IDENTITY(1,1) PRIMARY KEY,
  order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id INT NOT NULL REFERENCES products(id),
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL
);

CREATE TABLE audit_log (
  id INT IDENTITY(1,1) PRIMARY KEY,
  order_id INT NOT NULL,
  message NVARCHAR(255) NOT NULL,
  logged_at DATETIMEOFFSET NOT NULL DEFAULT SYSDATETIMEOFFSET()
);
GO

INSERT INTO customers (name, email, country, locale, notes) VALUES
 (N'山田 太郎', N'taro@example.jp', 'JP', N'ja', N'こんにちは、世界'),
 (N'王小明', N'wang@example.cn', 'CN', N'zh-Hans', N'简体中文：你好，世界'),
 (N'陳大文', N'chen@example.tw', 'TW', N'zh-Hant', N'繁體中文：你好，世界'),
 (N'김민준', N'minjun@example.kr', 'KR', N'ko', N'안녕하세요, 세계'),
 (N'Nguyễn Thị Hương', N'huong@example.vn', 'VN', N'vi', N'Xin chào thế giới — Việt Nam'),
 (N'Иван Петров', N'ivan@example.ru', 'RU', N'ru', N'Привет, мир'),
 (N'محمد الأحمد', N'mohammed@example.sa', 'SA', N'ar', N'مرحبا بالعالم'),
 (N'דוד כהן', N'david@example.il', 'IL', N'he', N'שלום עולם'),
 (N'Emoji Fan 🎉', N'emoji@example.com', 'US', N'en', N'Party 🎉🚀🐙 😀 👨‍👩‍👧‍👦');

INSERT INTO products (sku, name, price, stock, attributes) VALUES
 (N'TEA-001', N'抹茶 (Matcha) 100g', 24.50, 120, N'{"origin":"京都","organic":true}'),
 (N'TEA-002', N'龙井茶 Longjing', 18.00, 80, N'{"origin":"杭州"}'),
 (N'FOOD-001', N'Phở bò gói', 3.20, 500, N'{"origin":"Hà Nội"}'),
 (N'FOOD-002', N'Борщ консервированный', 4.75, 60, NULL),
 (N'FOOD-003', N'كنافة نابلسية', 12.90, 40, N'{"origin":"نابلس"}'),
 (N'FOOD-004', N'חומוס מוכן', 5.40, 90, NULL),
 (N'SNACK-001', N'김치 Kimchi 🌶️', 7.80, 200, N'{"spicy":true}');

INSERT INTO orders (customer_id, status, total) VALUES
 (1,'paid',49.00),(2,'shipped',18.00),(5,'new',9.60),(6,'paid',4.75),(7,'cancelled',12.90),(9,'paid',15.60);

INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES
 (1,1,2,24.50),(2,2,1,18.00),(3,3,3,3.20),(4,4,1,4.75),(5,5,1,12.90),(6,7,2,7.80);
GO

CREATE VIEW v_order_summary AS
SELECT o.id AS order_id, c.name AS customer, c.country, o.status, o.total,
       COUNT(i.id) AS line_count, o.ordered_at
FROM orders o
JOIN customers c ON c.id = o.customer_id
LEFT JOIN order_items i ON i.order_id = o.id
GROUP BY o.id, c.name, c.country, o.status, o.total, o.ordered_at;
GO

CREATE FUNCTION fn_order_total(@p_order_id INT) RETURNS DECIMAL(12,2) AS
BEGIN
  RETURN (SELECT COALESCE(SUM(quantity * unit_price), 0) FROM order_items WHERE order_id = @p_order_id);
END;
GO

CREATE PROCEDURE sp_recalc_order @p_order_id INT AS
BEGIN
  UPDATE orders SET total = dbo.fn_order_total(@p_order_id) WHERE id = @p_order_id;
END;
GO

CREATE TRIGGER orders_status_audit ON orders AFTER UPDATE AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO audit_log (order_id, message)
  SELECT i.id, CONCAT(N'status ', d.status, N' -> ', i.status)
  FROM inserted i JOIN deleted d ON d.id = i.id
  WHERE i.status <> d.status;
END;
GO
SET NOEXEC OFF;
GO
