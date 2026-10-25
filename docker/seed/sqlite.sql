-- Create the demo SQLite db inside the app container's volume (/var/www/data):
--   docker compose exec -T app sh -c 'php -r "(new PDO(\"sqlite:/var/www/data/demo.sqlite\"))->exec(file_get_contents(\"/var/www/html/docker/seed/sqlite.sql\"));" && chown www-data /var/www/data/demo.sqlite /var/www/data'
-- Then connect in LDB with driver SQLite, path /var/www/data/demo.sqlite.
PRAGMA foreign_keys = ON;
CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, country TEXT NOT NULL, locale TEXT NOT NULL, notes TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL UNIQUE, name TEXT NOT NULL, price NUMERIC NOT NULL, stock INTEGER NOT NULL DEFAULT 0);
CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE, status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new','paid','shipped','cancelled')), total NUMERIC NOT NULL DEFAULT 0, ordered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE, product_id INTEGER NOT NULL REFERENCES products(id), quantity INTEGER NOT NULL DEFAULT 1, unit_price NUMERIC NOT NULL);
CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, message TEXT NOT NULL, logged_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX idx_products_name ON products(name);

INSERT INTO customers (name, email, country, locale, notes) VALUES
 ('山田 太郎','taro@example.jp','JP','ja','こんにちは、世界'),
 ('王小明','wang@example.cn','CN','zh-Hans','简体中文：你好，世界'),
 ('陳大文','chen@example.tw','TW','zh-Hant','繁體中文：你好，世界'),
 ('김민준','minjun@example.kr','KR','ko','안녕하세요, 세계'),
 ('Nguyễn Thị Hương','huong@example.vn','VN','vi','Xin chào thế giới'),
 ('Иван Петров','ivan@example.ru','RU','ru','Привет, мир'),
 ('محمد الأحمد','mohammed@example.sa','SA','ar','مرحبا بالعالم'),
 ('דוד כהן','david@example.il','IL','he','שלום עולם'),
 ('Emoji Fan 🎉','emoji@example.com','US','en','Party 🎉🚀🐙');
INSERT INTO products (sku, name, price, stock) VALUES ('TEA-001','抹茶 (Matcha) 100g',24.50,120),('FOOD-001','Phở bò gói',3.20,500),('FOOD-002','Борщ',4.75,60);
INSERT INTO orders (customer_id, status, total) VALUES (1,'paid',49.00),(5,'new',9.60),(6,'paid',4.75);
INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (1,1,2,24.50),(2,2,3,3.20),(3,3,1,4.75);

CREATE VIEW v_order_summary AS
SELECT o.id AS order_id, c.name AS customer, o.status, o.total, COUNT(i.id) AS line_count
FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN order_items i ON i.order_id = o.id GROUP BY o.id;

CREATE TRIGGER trg_orders_status_audit AFTER UPDATE OF status ON orders
WHEN NEW.status <> OLD.status
BEGIN
  INSERT INTO audit_log (order_id, message) VALUES (NEW.id, 'status ' || OLD.status || ' -> ' || NEW.status);
END;
