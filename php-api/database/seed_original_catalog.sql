USE premium_furniture;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
TRUNCATE TABLE reviews;
TRUNCATE TABLE products;
TRUNCATE TABLE categories;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO categories (id, name, slug, image, description) VALUES
(1, 'Sofas & Seating', 'sofas', 'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=800&q=80', 'Comfortable seating for every space'),
(2, 'Tables', 'tables', 'https://images.unsplash.com/photo-1617098900591-3f90928e8c54?w=800&q=80', 'Dining and coffee tables in natural materials'),
(3, 'Beds & Bedroom', 'beds', 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=800&q=80', 'Rest in Scandinavian comfort'),
(4, 'Storage', 'storage', 'https://images.unsplash.com/photo-1603380353725-f8a4d39cc41e?w=800&q=80', 'Thoughtful organization solutions'),
(5, 'Lighting', 'lighting', 'https://images.unsplash.com/photo-1513506003901-1e6a229e2d15?w=800&q=80', 'Warm ambience for your home'),
(6, 'Textiles', 'textiles', 'https://images.unsplash.com/photo-1615875605825-5eb9bb5d52ac?w=800&q=80', 'Natural fabrics and soft furnishings');

INSERT INTO products
(id, name, slug, price, description, material, color, style, category, images_json, rating, in_stock, stock_qty, dimensions)
VALUES
(
  1,
  'Nordic Oak Sofa',
  'nordic-oak-sofa',
  1299,
  'A timeless three-seater sofa crafted from sustainably sourced oak with hand-finished linen upholstery. Deep cushions and clean lines create a calm, inviting presence.',
  'Oak & Linen',
  'Natural',
  'Scandinavian',
  'sofas',
  '["https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=1200&q=80","https://images.unsplash.com/photo-1540574163026-643ea20ade25?w=1200&q=80","https://images.unsplash.com/photo-1493663284031-b7e3aefcae8e?w=1200&q=80"]',
  4.80,
  1,
  10,
  '220cm W × 85cm D × 78cm H'
),
(
  2,
  'Elm Dining Table',
  'elm-dining-table',
  899,
  'Solid elm wood table with a smooth, organic finish. Seats six comfortably while maintaining an airy, minimal footprint.',
  'Elm Wood',
  'Honey',
  'Modern',
  'tables',
  '["https://images.unsplash.com/photo-1617098900591-3f90928e8c54?w=1200&q=80","https://images.unsplash.com/photo-1595428774223-ef52624120d2?w=1200&q=80"]',
  4.90,
  1,
  12,
  '180cm L × 90cm W × 75cm H'
),
(
  3,
  'Linen Lounge Chair',
  'linen-lounge-chair',
  549,
  'A contemporary take on mid-century design. Cushioned armrests and a gentle recline make this chair perfect for reading.',
  'Ash & Linen',
  'Stone Grey',
  'Mid-Century',
  'sofas',
  '["https://images.unsplash.com/photo-1567538096630-e0c55bd6374c?w=1200&q=80","https://images.unsplash.com/photo-1586023492125-27b2c045efd7?w=1200&q=80"]',
  4.70,
  1,
  9,
  '75cm W × 80cm D × 82cm H'
),
(
  4,
  'Walnut Platform Bed',
  'walnut-platform-bed',
  1799,
  'Low-profile platform bed in rich walnut. No box spring required. Built-in headboard with subtle grain detail.',
  'Walnut',
  'Dark Walnut',
  'Contemporary',
  'beds',
  '["https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=1200&q=80","https://images.unsplash.com/photo-1556020685-ae41abfc9365?w=1200&q=80"]',
  5.00,
  1,
  6,
  'King Size: 200cm L × 180cm W × 45cm H'
),
(
  5,
  'Oak Sideboard',
  'oak-sideboard',
  1099,
  'Spacious storage with soft-close drawers and adjustable shelving. Wire management built in for modern living.',
  'White Oak',
  'Light Oak',
  'Scandinavian',
  'storage',
  '["https://images.unsplash.com/photo-1603380353725-f8a4d39cc41e?w=1200&q=80","https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=1200&q=80"]',
  4.60,
  0,
  0,
  '160cm W × 45cm D × 80cm H'
),
(
  6,
  'Arc Floor Lamp',
  'arc-floor-lamp',
  349,
  'Elegant brass arc lamp with a warm fabric shade. Adjustable height and reach for flexible lighting.',
  'Brass & Fabric',
  'Brass',
  'Modern',
  'lighting',
  '["https://images.unsplash.com/photo-1513506003901-1e6a229e2d15?w=1200&q=80","https://images.unsplash.com/photo-1524484485831-a92ffc0de03f?w=1200&q=80"]',
  4.80,
  1,
  15,
  'Base: 30cm diameter, Reach: 180cm'
),
(
  7,
  'Merino Wool Throw',
  'merino-wool-throw',
  129,
  'Soft merino wool blanket in neutral tones. Hand-woven with a subtle herringbone pattern.',
  'Merino Wool',
  'Oatmeal',
  'Classic',
  'textiles',
  '["https://images.unsplash.com/photo-1615875605825-5eb9bb5d52ac?w=1200&q=80","https://images.unsplash.com/photo-1631889993959-41b4e9c6e3c5?w=1200&q=80"]',
  4.90,
  1,
  20,
  '130cm × 180cm'
),
(
  8,
  'Coffee Table Round',
  'coffee-table-round',
  449,
  'Solid oak round coffee table with a smooth, natural finish. Perfect centerpiece for conversation.',
  'Oak',
  'Natural Oak',
  'Scandinavian',
  'tables',
  '["https://images.unsplash.com/photo-1551298370-9d3d53740c72?w=1200&q=80","https://images.unsplash.com/photo-1618220179428-22790b461013?w=1200&q=80"]',
  4.70,
  1,
  11,
  '90cm diameter × 45cm H'
);

INSERT INTO reviews (id, product_id, user_id, user_name, rating, comment, date) VALUES
(1, 1, NULL, 'Sarah M.', 5, 'Exceptional quality and incredibly comfortable. The natural oak finish is beautiful.', '2026-03-15'),
(2, 1, NULL, 'James K.', 4, 'Love the minimalist design. Took a few weeks to arrive but worth the wait.', '2026-03-10');
