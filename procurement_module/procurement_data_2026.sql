-- =============================================
-- Migrasi Data Pengadaan Hardware 2026 (Manual)
-- Jalankan SETELAH procurement_migration.sql
-- Catatan: building_id & room_id = NULL karena
--   data rooms/buildings tidak tersedia.
--   Update manual sesuai ID di database live.
-- =============================================

-- Nonaktifkan FK check sementara agar bisa insert bebas
SET FOREIGN_KEY_CHECKS = 0;

-- Kosongkan contoh data sebelumnya (hapus jika tidak perlu)
-- DELETE FROM procurement_items WHERE procurement_id IN (1,2,3);
-- DELETE FROM procurement_orders WHERE id IN (1,2,3);

-- =============================================
-- ORDER 1 — Printer Epson L3251 (No. 2 & 3)
-- Digabung karena merk & harga sama, beda ruangan
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-001', '2026-01-01', '-', NULL, 'blud', 'selesai',
     'Data migrasi manual 2026 - Printer Epson L3251 (No.2 & No.3)', 1);

SET @o1 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    -- No.2: RT dan Logistik (1) + Perencanaan (2) = 3 unit
    (@o1, 'Printer Epson L3251 - RT dan Logistik',  'printer', 'Epson', 'L3251', 1, 3330000.00),
    (@o1, 'Printer Epson L3251 - Perencanaan',       'printer', 'Epson', 'L3251', 2, 3330000.00),
    -- No.3: Bidang Keperawatan (1)
    (@o1, 'Printer Epson L3251 - Bidang Keperawatan','printer', 'Epson', 'L3251', 1, 3330000.00);

-- =============================================
-- ORDER 2 — Notebook Asus Vivobook 14 (No. 5)
-- 7 unit ke berbagai unit
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-002', '2026-01-01', '-', NULL, 'blud', 'selesai',
     'Data migrasi manual 2026 - Notebook Asus Vivobook 14 (No.5)', 1);

SET @o2 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    (@o2, 'Notebook Asus Vivobook 14 - Keuangan (Raviqa & Kabag Keuangan)',         'laptop', 'Asus', 'Vivobook 14', 2, 9800000.00),
    (@o2, 'Notebook Asus Vivobook 14 - Perencanaan (Euis Kulsum & Kabag Perencanaan)','laptop','Asus', 'Vivobook 14', 2, 9800000.00),
    (@o2, 'Notebook Asus Vivobook 14 - Bidang Keperawatan (Kabid Keperawatan)',      'laptop', 'Asus', 'Vivobook 14', 1, 9800000.00),
    (@o2, 'Notebook Asus Vivobook 14 - Penunjang Medik (Kabid Penunjang Medik)',     'laptop', 'Asus', 'Vivobook 14', 1, 9800000.00),
    (@o2, 'Notebook Asus Vivobook 14 - Pelayanan Medik (Kabid Pelayanan Medik)',     'laptop', 'Asus', 'Vivobook 14', 1, 9800000.00);

-- =============================================
-- ORDER 3 — Printer Epson Thermal (No. 7)
-- 5 unit ke Kasir (Pentor)
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-003', '2026-01-01', '-', NULL, 'blud', 'selesai',
     'Data migrasi manual 2026 - Printer Epson Thermal (No.7)', 1);

SET @o3 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    (@o3, 'Printer Epson Thermal - Kasir (Pentor)', 'printer', 'Epson', 'Thermal', 5, 3000000.00);

-- =============================================
-- ORDER 4 — Notebook Asus Vivobook 14 (3 unit tanpa nomor)
-- Ka.Instalasi Ranap, Sekretaris Komite PPRA, Rekam Medik
-- Harga berbeda tiap unit → pisah item
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-004', '2026-01-01', '-', NULL, 'blud', 'selesai',
     'Data migrasi manual 2026 - Notebook Asus Vivobook 14 (Ka.Ranap, PPRA, Rekam Medik)', 1);

SET @o4 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    (@o4, 'Notebook Asus Vivobook 14 - Ka.Instalasi Ranap (Pak Alfian)',       'laptop', 'Asus', 'Vivobook 14', 1, 12000000.00),
    (@o4, 'Notebook Asus Vivobook 14 - Sekretaris Komite PPRA (Dewi Darwati)', 'laptop', 'Asus', 'Vivobook 14', 1, 12000000.00),
    (@o4, 'Notebook Asus Vivobook 14 - Rekam Medik (Pak Dadang)',              'laptop', 'Asus', 'Vivobook 14', 1, 12000001.00);

-- =============================================
-- ORDER 5 — Printer Epson L3251 (2 unit tanpa nomor)
-- R. Pedes & Sekretaris Komite PPRA
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-005', '2026-01-01', '-', NULL, 'blud', 'selesai',
     'Data migrasi manual 2026 - Printer Epson L3251 (R.Pedes & PPRA)', 1);

SET @o5 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    (@o5, 'Printer Epson L3251 - R. Pedes',                            'printer', 'Epson', 'L3251', 1, 3500000.00),
    (@o5, 'Printer Epson L3251 - Sekretaris Komite PPRA (Dewi Darwati)','printer', 'Epson', 'L3251', 1, 3500000.00);

-- =============================================
-- ORDER 6 — PC Unit Asus (6 unit tanpa nomor)
-- R. Pedes (5) & Ka.Instalasi IGD (1)
-- Harga beda → pisah item
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-006', '2026-01-01', '-', NULL, 'blud', 'selesai',
     'Data migrasi manual 2026 - PC Unit Asus (R.Pedes & Ka.IGD)', 1);

SET @o6 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    (@o6, 'PC Unit Asus - R. Pedes',        'komputer', 'Asus', NULL, 5, 12445000.00),
    (@o6, 'PC Unit Asus - Ka.Instalasi IGD', 'komputer', 'Asus', NULL, 1, 12441998.00);

-- =============================================
-- ORDER 7 — Sisa item tanpa nomor urut & harga
-- Digabung dalam 1 order karena datanya tidak lengkap
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-007', '2026-01-01', '-', NULL, 'blud', 'draft',
     'Data migrasi manual 2026 - Item tanpa harga (perlu verifikasi)', 1);

SET @o7 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    (@o7, 'Notebook Asus Vivobook 14 - Ka.Instalasi Rawat Jalan', 'laptop',    'Asus',   'Vivobook 14', 1, 12000000.00),
    (@o7, 'Notebook Asus Vivobook 14 - UPDRS',                    'laptop',    'Asus',   'Vivobook 14', 1, 0.00),
    (@o7, 'PC Unit Asus - Diklat',                                 'komputer',  'Asus',   NULL,          1, 0.00),
    (@o7, 'Laptop - Perencanaan',                                  'laptop',    NULL,     NULL,          1, 0.00),
    (@o7, 'Printer Epson L3251 - Sekre. Wadir',                    'printer',   'Epson',  'L3251',       1, 0.00),
    (@o7, 'Printer Epson L3251 - Diklat',                          'printer',   'Epson',  'L3251',       1, 0.00);

-- =============================================
-- ORDER 8 — Fingerprint U Are U 4500
-- 4 unit ke Rekam Medik
-- =============================================
INSERT INTO procurement_orders
    (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
VALUES
    ('PBL-2026-008', '2026-01-01', '-', NULL, 'blud', 'selesai',
     'Data migrasi manual 2026 - Fingerprint U Are U 4500 (Rekam Medik)', 1);

SET @o8 = LAST_INSERT_ID();

INSERT INTO procurement_items
    (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
VALUES
    (@o8, 'Fingerprint U Are U 4500 - Rekam Medik', 'lainnya', 'Solution', 'U Are U 4500', 4, 1709400.00);

-- Aktifkan kembali FK check
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- Verifikasi hasil
-- =============================================
SELECT
    po.nomor_pengadaan,
    po.vendor,
    po.status,
    COUNT(pi.id) AS jumlah_item,
    SUM(pi.jumlah) AS total_unit,
    po.total_harga
FROM procurement_orders po
LEFT JOIN procurement_items pi ON pi.procurement_id = po.id
WHERE po.nomor_pengadaan LIKE 'PBL-2026-%'
GROUP BY po.id
ORDER BY po.nomor_pengadaan;
