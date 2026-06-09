-- ============================================================
-- Katalog Produk Pertanian SobatTani (20 produk + varian)
-- Aman dijalankan berulang: kategori pakai INSERT IGNORE.
-- Catatan: berat varian = perkiraan (ml + botol). Sesuaikan kalau perlu.
--   100 ml=150 g, 250 ml=320 g, 500 ml=580 g, 1000 ml=1100 g
-- ============================================================

START TRANSACTION;

-- Kategori (dilewati jika slug sudah ada)
INSERT IGNORE INTO categories (name, slug, description, is_active, sort_order, created_at, updated_at) VALUES
('Insektisida', 'insektisida', 'Pengendali serangga & hama', 1, 1, NOW(), NOW()),
('Fungisida',   'fungisida',   'Pengendali penyakit jamur', 1, 2, NOW(), NOW()),
('ZPT & Hormon','zpt-hormon',  'Zat pengatur tumbuh, hormon & biostimulan', 1, 3, NOW(), NOW());

SET @cat_ins = (SELECT id FROM categories WHERE slug = 'insektisida' LIMIT 1);
SET @cat_fun = (SELECT id FROM categories WHERE slug = 'fungisida'   LIMIT 1);
SET @cat_zpt = (SELECT id FROM categories WHERE slug = 'zpt-hormon'  LIMIT 1);

-- 1. EMAMECTIN BENZOAT + LUFENURON (Insektisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_ins, 'TANI-001', 'emamectin-benzoat-lufenuron', 'EMAMECTIN BENZOAT + LUFENURON', NULL, 'Mengendalikan ulat grayak, ulat daun, dan penggerek buah. Bekerja cepat sekaligus menghambat perkembangan larva sehingga pengendalian lebih maksimal.', 100000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-001-250',  '250 ml',  100000, NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-001-500',  '500 ml',  190000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-001-1000', '1000 ml', 360000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 2. DIFENOCONAZOLE + AZOXYSTROBIN (Fungisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_fun, 'TANI-002', 'difenoconazole-azoxystrobin', 'DIFENOCONAZOLE + AZOXYSTROBIN', NULL, 'Fungisida sistemik untuk mengendalikan antraknosa, bercak daun, embun tepung, karat daun, dan berbagai penyakit jamur lainnya.', 80000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-002-250',  '250 ml',  80000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-002-500',  '500 ml',  130000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-002-1000', '1000 ml', 245000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 3. BROFLANILIDA (Insektisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_ins, 'TANI-003', 'broflanilida', 'BROFLANILIDA', NULL, 'Insektisida generasi baru yang efektif mengendalikan ulat, thrips, penggerek, dan hama pengunyah daun lainnya.', 100000, NULL, '100 ml', 150, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-003-100', '100 ml', 100000, NULL, 100, 150, NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-003-250', '250 ml', 250000, NULL, 100, 320, NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-003-500', '500 ml', 495000, NULL, 100, 580, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 4. ABAMECTIN BENING (Insektisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_ins, 'TANI-004', 'abamectin-bening', 'ABAMECTIN BENING', NULL, 'Mengendalikan tungau, thrips, kutu daun, dan ulat. Bekerja cepat menghentikan aktivitas makan hama.', 65000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-004-250',  '250 ml',  65000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-004-500',  '500 ml',  125000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-004-1000', '1000 ml', 230000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 5. FIPRONIL (Insektisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_ins, 'TANI-005', 'fipronil', 'FIPRONIL', NULL, 'Mengendalikan wereng, semut, rayap, penggerek batang, dan berbagai hama serangga lainnya.', 50000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-005-250',  '250 ml',  50000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-005-500',  '500 ml',  90000,  NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-005-1000', '1000 ml', 160000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 6. DIFENOCONAZOLE (Fungisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_fun, 'TANI-006', 'difenoconazole', 'DIFENOCONAZOLE', NULL, 'Fungisida sistemik untuk mengendalikan antraknosa, bercak daun, embun tepung, dan penyakit jamur lainnya.', 70000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-006-250',  '250 ml',  70000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-006-500',  '500 ml',  125000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-006-1000', '1000 ml', 235000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 7. METALAXYL (Fungisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_fun, 'TANI-007', 'metalaxyl', 'METALAXYL', NULL, 'Fungisida sistemik untuk mengendalikan busuk akar, rebah semai, busuk batang, dan penyakit akibat jamur tanah.', 80000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-007-250',  '250 ml',  80000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-007-500',  '500 ml',  130000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-007-1000', '1000 ml', 245000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 8. ASAM AMINO BIOSTIMULANT + UNSUR HARA (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-008', 'asam-amino-biostimulant', 'ASAM AMINO BIOSTIMULANT + UNSUR HARA', NULL, 'Membantu mempercepat pertumbuhan tanaman, memperkuat akar, meningkatkan kehijauan daun, dan mengurangi stres tanaman.', 68000, NULL, '1000 ml', 1100, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-008-1000', '1000 ml', 68000, NULL, 100, 1100, NULL, NULL, NULL, 1, 1, 1, NOW(), NOW());

-- 9. HORMON GA3 10.000 PPM (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-009', 'hormon-ga3-10000-ppm', 'HORMON GA3 10.000 PPM', NULL, 'Merangsang pertumbuhan tanaman, pembungaan, pembesaran buah, dan memecah masa dormansi.', 30000, NULL, '100 ml', 150, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-009-100', '100 ml', 30000, NULL, 100, 150, NULL, NULL, NULL, 1, 1, 1, NOW(), NOW());

-- 10. HORMON AUXIN 10.000 PPM (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-010', 'hormon-auxin-10000-ppm', 'HORMON AUXIN 10.000 PPM', NULL, 'Merangsang pembentukan akar, pertumbuhan tunas, serta mengurangi kerontokan bunga dan buah.', 24000, NULL, '100 ml', 150, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-010-100', '100 ml', 24000, NULL, 100, 150, NULL, NULL, NULL, 1, 1, 1, NOW(), NOW());

-- 11. HORMON SITOKININ 10.000 PPM (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-011', 'hormon-sitokinin-10000-ppm', 'HORMON SITOKININ 10.000 PPM', NULL, 'Merangsang pembelahan sel, pertumbuhan tunas, dan menjaga daun tetap hijau lebih lama.', 27000, NULL, '100 ml', 150, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-011-100', '100 ml', 27000, NULL, 100, 150, NULL, NULL, NULL, 1, 1, 1, NOW(), NOW());

-- 12. PACLOBUTRAZOL 50.000 PPM (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-012', 'paclobutrazol-50000-ppm', 'PACLOBUTRAZOL 50.000 PPM', NULL, 'Membantu merangsang pembungaan dan mengurangi pertumbuhan vegetatif yang berlebihan.', 55000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-012-250',  '250 ml',  55000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-012-500',  '500 ml',  97000,  NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-012-1000', '1000 ml', 185000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 13. CPPU 1000 PPM (PEMBESAR BUAH) (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-013', 'cppu-1000-ppm', 'CPPU 1000 PPM (PEMBESAR BUAH)', NULL, 'Membantu meningkatkan ukuran, bobot, dan kualitas buah.', 30000, NULL, '100 ml', 150, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-013-100', '100 ml', 30000, NULL, 100, 150, NULL, NULL, NULL, 1, 1, 1, NOW(), NOW());

-- 14. SODIUM NITROPHENOLATE (ATONIK) 20 SL (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-014', 'sodium-nitrophenolate-atonik-20-sl', 'SODIUM NITROPHENOLATE (ATONIK) 20 SL', NULL, 'Perangsang pertumbuhan yang membantu pembentukan akar, tunas, bunga, dan buah.', 70000, NULL, '500 ml', 580, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-014-500',  '500 ml',  70000,  NULL, 100, 580,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-014-1000', '1000 ml', 130000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 2, NOW(), NOW());

-- 15. DA-6 (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-015', 'da-6', 'DA-6', NULL, 'Membantu meningkatkan fotosintesis, pertumbuhan akar, pembungaan, dan produktivitas tanaman.', 80000, NULL, '500 ml', 580, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-015-500',  '500 ml',  80000,  NULL, 100, 580,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-015-1000', '1000 ml', 130000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 2, NOW(), NOW());

-- 16. TRIACONTANOL 1000 PPM (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-016', 'triacontanol-1000-ppm', 'TRIACONTANOL 1000 PPM', NULL, 'Meningkatkan fotosintesis, pertumbuhan tanaman, pembentukan bunga, dan hasil panen.', 35000, NULL, '100 ml', 150, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-016-100',  '100 ml',  35000,  NULL, 100, 150,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-016-250',  '250 ml',  75000,  NULL, 100, 320,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-016-500',  '500 ml',  140000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 3, NOW(), NOW()),
(@pid, 'TANI-016-1000', '1000 ml', 265000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 4, NOW(), NOW());

-- 17. CYPERMETHRIN 50 EC (Insektisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_ins, 'TANI-017', 'cypermethrin-50-ec', 'CYPERMETHRIN 50 EC', NULL, 'Insektisida kontak dan lambung dengan efek cepat untuk mengendalikan ulat, kutu, wereng, dan penggerek.', 72000, NULL, '500 ml', 580, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-017-500',  '500 ml',  72000,  NULL, 100, 580,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-017-1000', '1000 ml', 135000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 2, NOW(), NOW());

-- 18. PYRACLOSTROBIN 100 EC (Fungisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_fun, 'TANI-018', 'pyraclostrobin-100-ec', 'PYRACLOSTROBIN 100 EC', NULL, 'Fungisida sistemik yang membantu mengendalikan berbagai penyakit jamur sekaligus menjaga kesehatan tanaman.', 82000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-018-250',  '250 ml',  82000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-018-500',  '500 ml',  160000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-018-1000', '1000 ml', 300000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 19. PENCONAZOLE 100 EC (Fungisida)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_fun, 'TANI-019', 'penconazole-100-ec', 'PENCONAZOLE 100 EC', NULL, 'Fungisida sistemik yang efektif mengendalikan embun tepung, karat daun, dan berbagai penyakit jamur lainnya.', 98000, NULL, '250 ml', 320, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-019-250',  '250 ml',  98000,  NULL, 100, 320,  NULL, NULL, NULL, 1, 1, 1, NOW(), NOW()),
(@pid, 'TANI-019-500',  '500 ml',  180000, NULL, 100, 580,  NULL, NULL, NULL, 0, 1, 2, NOW(), NOW()),
(@pid, 'TANI-019-1000', '1000 ml', 350000, NULL, 100, 1100, NULL, NULL, NULL, 0, 1, 3, NOW(), NOW());

-- 20. BRASSINOLIDE (ZPT)
INSERT INTO products (category_id, sku, slug, name, short_description, description, price, compare_at_price, weight_label, weight_grams, stock, public_status, catalog_status, badge_label, sold_count, highlights, is_featured, published_at, length_cm, width_cm, height_cm, created_at, updated_at)
VALUES (@cat_zpt, 'TANI-020', 'brassinolide', 'BRASSINOLIDE', NULL, 'ZPT premium yang membantu meningkatkan pertumbuhan, pembungaan, pembuahan, serta ketahanan tanaman terhadap stres lingkungan.', 90000, NULL, '100 ml', 150, 100, 'active', 'available', NULL, 0, NULL, 0, NOW(), NULL, NULL, NULL, NOW(), NOW());
SET @pid = LAST_INSERT_ID();
INSERT INTO product_variants (product_id, sku, label, price, compare_at_price, stock, weight_grams, length_cm, width_cm, height_cm, is_default, is_active, sort_order, created_at, updated_at) VALUES
(@pid, 'TANI-020-100', '100 ml', 90000, NULL, 100, 150, NULL, NULL, NULL, 1, 1, 1, NOW(), NOW());

COMMIT;
