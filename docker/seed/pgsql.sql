-- Runs against POSTGRES_DB (ldb_demo).
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

CREATE TABLE customers (
  id SERIAL PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  country CHAR(2) NOT NULL,
  locale VARCHAR(10) NOT NULL,
  notes TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE products (
  id SERIAL PRIMARY KEY,
  sku VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  price NUMERIC(10,2) NOT NULL,
  stock INTEGER NOT NULL DEFAULT 0,
  attributes JSONB
);
CREATE INDEX idx_products_name ON products(name);

CREATE TYPE order_status AS ENUM ('new','paid','shipped','cancelled');

CREATE TABLE orders (
  id SERIAL PRIMARY KEY,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  status order_status NOT NULL DEFAULT 'new',
  total NUMERIC(12,2) NOT NULL DEFAULT 0,
  ordered_at TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE order_items (
  id SERIAL PRIMARY KEY,
  order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  product_id INTEGER NOT NULL REFERENCES products(id),
  quantity INTEGER NOT NULL DEFAULT 1,
  unit_price NUMERIC(10,2) NOT NULL
);

CREATE TABLE audit_log (
  id SERIAL PRIMARY KEY,
  order_id INTEGER NOT NULL,
  message VARCHAR(255) NOT NULL,
  logged_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

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

CREATE FUNCTION fn_order_total(p_order_id INTEGER) RETURNS NUMERIC AS $$
  SELECT COALESCE(SUM(quantity * unit_price), 0) FROM order_items WHERE order_id = p_order_id;
$$ LANGUAGE sql STABLE;

CREATE PROCEDURE sp_recalc_order(p_order_id INTEGER) AS $$
  UPDATE orders SET total = fn_order_total(p_order_id) WHERE id = p_order_id;
$$ LANGUAGE sql;

CREATE FUNCTION trg_orders_status_audit() RETURNS trigger AS $$
BEGIN
  IF NEW.status <> OLD.status THEN
    INSERT INTO audit_log (order_id, message)
    VALUES (NEW.id, 'status ' || OLD.status || ' -> ' || NEW.status);
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER orders_status_audit AFTER UPDATE ON orders
FOR EACH ROW EXECUTE FUNCTION trg_orders_status_audit();
