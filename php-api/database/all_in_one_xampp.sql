-- One-shot import for local XAMPP / phpMyAdmin
CREATE DATABASE IF NOT EXISTS premium_furniture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE premium_furniture;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  phone VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL UNIQUE,
  image TEXT NOT NULL,
  description TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  price INT UNSIGNED NOT NULL,
  description TEXT NOT NULL,
  material VARCHAR(120) NOT NULL,
  color VARCHAR(120) NOT NULL,
  style VARCHAR(120) NOT NULL,
  category VARCHAR(80) NOT NULL,
  images_json JSON NOT NULL,
  rating DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  in_stock TINYINT(1) NOT NULL DEFAULT 1,
  stock_qty INT UNSIGNED NOT NULL DEFAULT 0,
  dimensions VARCHAR(255) NULL,
  INDEX idx_products_category (category),
  INDEX idx_products_slug (slug),
  INDEX idx_products_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  date DATE NOT NULL,
  CONSTRAINT chk_reviews_rating CHECK (rating >= 1 AND rating <= 5),
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_reviews_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  customer_name VARCHAR(160) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  address_json JSON NOT NULL,
  total INT UNSIGNED NOT NULL,
  shipping INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  price INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  limit_key VARCHAR(190) NOT NULL UNIQUE,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  reset_at DATETIME NOT NULL,
  INDEX idx_reset_at (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(64) NOT NULL,
  recipient_email VARCHAR(255) NOT NULL,
  payload_json JSON NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_email_outbox_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(16) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  message TEXT NOT NULL,
  context_json JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_app_logs_level_created (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO categories (name, slug, image, description) VALUES
('Sofas & Seating', 'sofas', 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=800&q=80', 'Comfortable seating for every space'),
('Tables', 'tables', 'https://images.unsplash.com/photo-1617098900591-3f90928e8c54?w=800&q=80', 'Dining and coffee tables in natural materials'),
('Beds & Bedroom', 'beds', 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=800&q=80', 'Rest in Scandinavian comfort'),
('Storage', 'storage', 'https://images.unsplash.com/photo-1603380353725-f8a4d39cc41e?w=800&q=80', 'Thoughtful organization solutions'),
('Lighting', 'lighting', 'https://images.unsplash.com/photo-1513506003901-1e6a229e2d15?w=800&q=80', 'Warm ambience for your home'),
('Textiles', 'textiles', 'https://images.unsplash.com/photo-1615875605825-5eb9bb5d52ac?w=800&q=80', 'Natural fabrics and soft furnishings')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  image = VALUES(image),
  description = VALUES(description);

INSERT INTO products
  (name, slug, price, description, material, color, style, category, images_json, rating, in_stock, stock_qty, dimensions)
VALUES
('Nordic Oak Sofa', 'nordic-oak-sofa', 1299, 'A timeless three-seater sofa crafted from sustainably sourced oak with hand-finished linen upholstery.', 'Oak & Linen', 'Natural', 'Scandinavian', 'sofas', JSON_ARRAY('https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=1200&q=80','https://images.unsplash.com/photo-1540574163026-643ea20ade25?w=1200&q=80'), 4.80, 1, 10, '220cm W x 85cm D x 78cm H'),
('Elm Dining Table', 'elm-dining-table', 899, 'Solid elm wood table with a smooth, organic finish.', 'Elm Wood', 'Honey', 'Modern', 'tables', JSON_ARRAY('https://images.unsplash.com/photo-1617098900591-3f90928e8c54?w=1200&q=80','https://images.unsplash.com/photo-1595428774223-ef52624120d2?w=1200&q=80'), 4.90, 1, 12, '180cm L x 90cm W x 75cm H'),
('Linen Lounge Chair', 'linen-lounge-chair', 549, 'A contemporary take on mid-century design.', 'Ash & Linen', 'Stone Grey', 'Mid-Century', 'sofas', JSON_ARRAY('https://images.unsplash.com/photo-1567538096630-e0c55bd6374c?w=1200&q=80','https://images.unsplash.com/photo-1586023492125-27b2c045efd7?w=1200&q=80'), 4.70, 1, 9, '75cm W x 80cm D x 82cm H'),
('Walnut Platform Bed', 'walnut-platform-bed', 1799, 'Low-profile platform bed in rich walnut.', 'Walnut', 'Dark Walnut', 'Contemporary', 'beds', JSON_ARRAY('https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=1200&q=80','https://images.unsplash.com/photo-1556020685-ae41abfc9365?w=1200&q=80'), 5.00, 1, 6, 'King Size: 200cm L x 180cm W x 45cm H'),
('Oak Sideboard', 'oak-sideboard', 1099, 'Spacious storage with soft-close drawers.', 'White Oak', 'Light Oak', 'Scandinavian', 'storage', JSON_ARRAY('https://images.unsplash.com/photo-1603380353725-f8a4d39cc41e?w=1200&q=80','https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1200&q=80'), 4.60, 0, 0, '160cm W x 45cm D x 80cm H'),
('Arc Floor Lamp', 'arc-floor-lamp', 349, 'Elegant brass arc lamp with a warm fabric shade.', 'Brass & Fabric', 'Brass', 'Modern', 'lighting', JSON_ARRAY('https://images.unsplash.com/photo-1513506003901-1e6a229e2d15?w=1200&q=80','https://images.unsplash.com/photo-1524484485831-a92ffc0de03f?w=1200&q=80'), 4.80, 1, 15, 'Base: 30cm diameter, Reach: 180cm'),
('Merino Wool Throw', 'merino-wool-throw', 129, 'Soft merino wool blanket in neutral tones.', 'Merino Wool', 'Oatmeal', 'Classic', 'textiles', JSON_ARRAY('https://images.unsplash.com/photo-1615875605825-5eb9bb5d52ac?w=1200&q=80','https://images.unsplash.com/photo-1631889993959-41b4e9c6e3c5?w=1200&q=80'), 4.90, 1, 20, '130cm x 180cm'),
('Coffee Table Round', 'coffee-table-round', 449, 'Solid oak round coffee table with a smooth finish.', 'Oak', 'Natural Oak', 'Scandinavian', 'tables', JSON_ARRAY('https://images.unsplash.com/photo-1551298370-9d3d53740c72?w=1200&q=80','https://images.unsplash.com/photo-1618220179428-22790b461013?w=1200&q=80'), 4.70, 1, 11, '90cm diameter x 45cm H')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  price = VALUES(price),
  description = VALUES(description),
  material = VALUES(material),
  color = VALUES(color),
  style = VALUES(style),
  category = VALUES(category),
  images_json = VALUES(images_json),
  rating = VALUES(rating),
  in_stock = VALUES(in_stock),
  stock_qty = VALUES(stock_qty),
  dimensions = VALUES(dimensions);

INSERT INTO reviews (product_id, user_id, user_name, rating, comment, date)
SELECT p.id, NULL, 'Sarah M.', 5, 'Exceptional quality and incredibly comfortable.', '2026-03-15'
FROM products p
WHERE p.slug = 'nordic-oak-sofa'
  AND NOT EXISTS (
    SELECT 1 FROM reviews r
    WHERE r.product_id = p.id AND r.user_name = 'Sarah M.' AND r.date = '2026-03-15'
  );

INSERT INTO reviews (product_id, user_id, user_name, rating, comment, date)
SELECT p.id, NULL, 'James K.', 4, 'Love the minimalist design.', '2026-03-10'
FROM products p
WHERE p.slug = 'nordic-oak-sofa'
  AND NOT EXISTS (
    SELECT 1 FROM reviews r
    WHERE r.product_id = p.id AND r.user_name = 'James K.' AND r.date = '2026-03-10'
  );
