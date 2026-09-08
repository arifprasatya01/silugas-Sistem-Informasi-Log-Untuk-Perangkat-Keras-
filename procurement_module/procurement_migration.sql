-- =============================================
-- Modul Pengadaan Hardware - Migration
-- Tambahkan ke database u297738695_hardware
-- =============================================

-- Tabel utama pengadaan (header)
CREATE TABLE IF NOT EXISTS procurement_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nomor_pengadaan VARCHAR(50) NOT NULL UNIQUE,         -- contoh: PBL-2026-001
    tanggal_pengadaan DATE NOT NULL,
    vendor VARCHAR(150) NOT NULL,
    no_surat VARCHAR(100) DEFAULT NULL,                  -- nomor surat pembelian / PO
    sumber_dana ENUM('apbd','apbn','bos','blud','lainnya') NOT NULL DEFAULT 'blud',
    total_harga DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('draft','disetujui','diterima','selesai') NOT NULL DEFAULT 'draft',
    catatan TEXT DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Tabel item / rincian per pengadaan
CREATE TABLE IF NOT EXISTS procurement_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    procurement_id INT UNSIGNED NOT NULL,
    nama_barang VARCHAR(200) NOT NULL,
    tipe ENUM('komputer','laptop','printer','scanner','server','network','lainnya') DEFAULT 'lainnya',
    merk VARCHAR(100) DEFAULT NULL,
    spesifikasi TEXT DEFAULT NULL,
    jumlah INT UNSIGNED NOT NULL DEFAULT 1,
    harga_satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_harga DECIMAL(15,2) GENERATED ALWAYS AS (jumlah * harga_satuan) STORED,
    asset_linked TINYINT(1) NOT NULL DEFAULT 0,          -- sudah dihubungkan ke tabel assets?
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (procurement_id) REFERENCES procurement_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Trigger: update total di header setiap kali item berubah
DELIMITER $$

CREATE TRIGGER trg_procurement_total_after_insert
AFTER INSERT ON procurement_items
FOR EACH ROW
BEGIN
    UPDATE procurement_orders
    SET total_harga = (
        SELECT COALESCE(SUM(jumlah * harga_satuan), 0)
        FROM procurement_items
        WHERE procurement_id = NEW.procurement_id
    )
    WHERE id = NEW.procurement_id;
END$$

CREATE TRIGGER trg_procurement_total_after_update
AFTER UPDATE ON procurement_items
FOR EACH ROW
BEGIN
    UPDATE procurement_orders
    SET total_harga = (
        SELECT COALESCE(SUM(jumlah * harga_satuan), 0)
        FROM procurement_items
        WHERE procurement_id = NEW.procurement_id
    )
    WHERE id = NEW.procurement_id;
END$$

CREATE TRIGGER trg_procurement_total_after_delete
AFTER DELETE ON procurement_items
FOR EACH ROW
BEGIN
    UPDATE procurement_orders
    SET total_harga = (
        SELECT COALESCE(SUM(jumlah * harga_satuan), 0)
        FROM procurement_items
        WHERE procurement_id = OLD.procurement_id
    )
    WHERE id = OLD.procurement_id;
END$$

DELIMITER ;

-- =============================================
-- CONTOH DATA MIGRASI MANUAL 2026
-- Sesuaikan baris di bawah ini dengan data Excel kamu
-- Ganti created_by = 1 (admin) atau ID user yang sesuai
-- =============================================

INSERT INTO procurement_orders (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by) VALUES
('PBL-2026-001', '2026-01-15', 'CV Mitra Komputindo', 'SPK/IT/2026/001', 'blud', 'selesai', 'Pengadaan laptop untuk poli rawat jalan', 1),
('PBL-2026-002', '2026-02-10', 'PT Tekno Solusi Indonesia', 'SPK/IT/2026/002', 'blud', 'selesai', 'Pengadaan printer dan scanner IGD', 1),
('PBL-2026-003', '2026-03-20', 'CV Mitra Komputindo', 'SPK/IT/2026/003', 'apbd', 'disetujui', 'Pengadaan PC untuk administrasi', 1);

-- Item untuk PBL-2026-001 (laptop)
INSERT INTO procurement_items (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan) VALUES
(1, 'Laptop Core i5 Gen 12', 'laptop', 'Lenovo', 'Intel Core i5-1235U, RAM 8GB, SSD 512GB, 14 inch', 3, 9500000.00),
(1, 'Mouse Wireless', 'lainnya', 'Logitech', 'M185 Wireless', 3, 150000.00);

-- Item untuk PBL-2026-002 (printer & scanner)
INSERT INTO procurement_items (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan) VALUES
(2, 'Printer Inkjet Multifungsi', 'printer', 'Epson', 'L3250 WiFi All-in-One', 2, 2800000.00),
(2, 'Scanner Dokumen', 'scanner', 'Canon', 'DR-C225 II USB', 1, 4200000.00);

-- Item untuk PBL-2026-003 (PC)
INSERT INTO procurement_items (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan) VALUES
(3, 'PC Desktop AIO', 'komputer', 'HP', 'EliteOne 800 G9 AIO, i5, 8GB, 256SSD', 5, 12000000.00),
(3, 'Headset USB', 'lainnya', 'Jabra', 'Evolve2 30 USB-A', 5, 850000.00);
