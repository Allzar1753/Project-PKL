-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 03, 2026 at 03:26 AM
-- Server version: 8.0.30
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `it_hexindo`
--

-- --------------------------------------------------------

--
-- Table structure for table `barang`
--

CREATE TABLE `barang` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `id_barang` int NOT NULL,
  `id_merk` int NOT NULL,
  `no_asset` varchar(50) NOT NULL,
  `serial_number` varchar(100) NOT NULL,
  `id_tipe` int NOT NULL,
  `id_jenis` int NOT NULL,
  `tanggal_terima` date NOT NULL,
  `bermasalah` enum('Iya','Tidak') NOT NULL,
  `keterangan_masalah` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `id_status` int NOT NULL,
  `id_branch` int NOT NULL,
  `foto` varchar(255) NOT NULL,
  `user` varchar(100) NOT NULL,
  `status` enum('Tersedia','Dalam Perjalanan','Diterima') DEFAULT 'Tersedia'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `barang`
--

INSERT INTO `barang` (`id`, `user_id`, `id_barang`, `id_merk`, `no_asset`, `serial_number`, `id_tipe`, `id_jenis`, `tanggal_terima`, `bermasalah`, `keterangan_masalah`, `id_status`, `id_branch`, `foto`, `user`, `status`) VALUES
(71, 7, 1, 1, '10160110160808', 'DUHCU83', 1, 1, '2026-05-31', 'Tidak', NULL, 4, 28, 'aset_6a1c491985c8f5.50232672.jpeg', 'BIYAW', 'Tersedia'),
(72, 38, 3, 1, '101601103748', 'D43REH', 13, 1, '2026-05-31', 'Tidak', NULL, 4, 28, 'aset_6a1c497a579496.05142573.png', 'YAW', 'Tersedia'),
(73, 38, 3, 1, '90391-09-03E30', 'D CJC', 11, 1, '2026-05-31', 'Tidak', NULL, 4, 28, 'aset_6a1c4b7a805dc1.82641639.png', 'JSJ', 'Tersedia'),
(74, 7, 3, 1, '1', 'A1', 11, 1, '2026-06-02', 'Tidak', NULL, 4, 2, 'foto_6a1e339ef16af7.28703015.webp', 'PAK HADI', 'Tersedia'),
(75, 7, 2, 1, '1', 'A12', 18, 1, '2026-06-02', 'Tidak', NULL, 4, 2, 'foto_6a1e34bea91075.85964727.jpg', 'PAK HADI', 'Tersedia'),
(76, 33, 3, 1, '11', 'A1Q', 12, 1, '2026-06-02', 'Iya', 'SSD', 5, 40, 'aset_6a1e926906f5f8.52746173.jpg', 'PAK DENI', 'Diterima'),
(84, 7, 3, 1, '121', 'A1213', 12, 1, '2026-06-03', 'Tidak', NULL, 4, 40, 'foto_6a1f9c89942f23.49497585.jpg', 'Bima', 'Tersedia');

-- --------------------------------------------------------

--
-- Table structure for table `barang_pengiriman`
--

CREATE TABLE `barang_pengiriman` (
  `id_pengiriman` int NOT NULL,
  `id_barang` int NOT NULL,
  `branch_asal` int NOT NULL,
  `branch_tujuan` int NOT NULL,
  `tanggal_keluar` date DEFAULT NULL,
  `jasa_pengiriman` varchar(50) DEFAULT NULL,
  `nomor_resi_keluar` varchar(100) DEFAULT NULL,
  `foto_resi_keluar` varchar(255) DEFAULT NULL,
  `status_pengiriman` enum('Sedang perjalanan','Sudah diterima') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'Sedang perjalanan',
  `tanggal_diterima` date DEFAULT NULL,
  `nama_penerima` varchar(100) DEFAULT NULL,
  `nomor_resi_masuk` varchar(100) DEFAULT NULL,
  `foto_barang_diterima` varchar(255) DEFAULT NULL,
  `dibuat_oleh` int DEFAULT NULL,
  `dibuat_pada` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `diupdate_pada` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `barang_pengiriman`
--

INSERT INTO `barang_pengiriman` (`id_pengiriman`, `id_barang`, `branch_asal`, `branch_tujuan`, `tanggal_keluar`, `jasa_pengiriman`, `nomor_resi_keluar`, `foto_resi_keluar`, `status_pengiriman`, `tanggal_diterima`, `nama_penerima`, `nomor_resi_masuk`, `foto_barang_diterima`, `dibuat_oleh`, `dibuat_pada`, `diupdate_pada`) VALUES
(48, 71, 40, 28, '2026-05-31', 'PCP Express', '9I32I03', 'foto_resi_6a1c4cb5798b56.36869680.jpg', 'Sudah diterima', '2026-05-31', 'Nabila', '9I32I03', 'terima_6a1c4cd156bde5.39766865.jpeg', NULL, '2026-05-31 14:59:01', '2026-05-31 14:59:29'),
(49, 75, 40, 2, '2026-06-02', 'SAP Express', 'SW2345d', 'foto_resi_6a1e3971118069.99584204.webp', 'Sudah diterima', '2026-06-02', 'Winda', 'SW2345d', 'terima_6a1e39f7109f37.42769329.jpg', NULL, '2026-06-02 02:01:21', '2026-06-02 02:03:35'),
(50, 74, 40, 2, '2026-06-02', 'SAP Express', 'SW2345d', 'foto_resi_6a1e3e78e74ae8.07960432.webp', 'Sudah diterima', '2026-06-02', 'Winda', 'SW2345d', 'terima_6a1e40b786f432.69743857.jpeg', NULL, '2026-06-02 02:22:48', '2026-06-02 02:32:23'),
(51, 75, 40, 2, '2026-06-02', 'PCP EXPRESS', 'SW2345d', 'foto_resi_6a1e5d65950e30.69452430.webp', 'Sudah diterima', '2026-06-02', 'Winda', 'SW2345d', 'terima_6a1e5e2e985e83.60409760.png', NULL, '2026-06-02 04:34:45', '2026-06-02 04:38:06');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `reset_token` varchar(128) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_requests`
--

CREATE TABLE `password_reset_requests` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `alasan` text,
  `status` enum('pending','selesai') DEFAULT 'pending',
  `requested_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `processed_by` int DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pengiriman_cabang_ho`
--

CREATE TABLE `pengiriman_cabang_ho` (
  `id_pengiriman_ho` int NOT NULL,
  `id_barang` int NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `pemilik_barang` varchar(100) DEFAULT NULL,
  `branch_asal` int NOT NULL,
  `branch_tujuan` int NOT NULL,
  `tanggal_pengajuan` date NOT NULL,
  `jasa_pengiriman` varchar(50) NOT NULL,
  `nomor_resi_keluar` varchar(100) NOT NULL,
  `foto_resi_keluar` varchar(255) DEFAULT NULL,
  `status_pengiriman` varchar(50) NOT NULL DEFAULT 'Menunggu persetujuan admin',
  `catatan_user` varchar(255) DEFAULT NULL,
  `catatan_admin` varchar(255) DEFAULT NULL,
  `dibuat_oleh` int DEFAULT NULL,
  `disetujui_oleh` int DEFAULT NULL,
  `disetujui_pada` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `pengiriman_cabang_ho`
--

INSERT INTO `pengiriman_cabang_ho` (`id_pengiriman_ho`, `id_barang`, `serial_number`, `pemilik_barang`, `branch_asal`, `branch_tujuan`, `tanggal_pengajuan`, `jasa_pengiriman`, `nomor_resi_keluar`, `foto_resi_keluar`, `status_pengiriman`, `catatan_user`, `catatan_admin`, `dibuat_oleh`, `disetujui_oleh`, `disetujui_pada`, `created_at`, `updated_at`) VALUES
(86, 1, 'DUHCU83', 'BIYAW', 28, 40, '2026-05-31', 'PCP Express', '920920923', 'resi_6a1c4adfd67e89.24021116.jpeg', 'Selesai', 'RUSAK', 'Admin HO Jakarta', 38, 7, '2026-05-31 21:52:18', '2026-05-31 14:51:11', '2026-05-31 14:59:29'),
(87, 3, 'A1', 'PAK HADI', 2, 40, '2026-06-02', 'SAP Express', '123', 'resi_6a1e3610db7972.54301318.jpg', 'Selesai', 'SSD', 'Admin HO Jakarta', 33, 7, '2026-06-02 08:47:53', '2026-06-02 01:46:56', '2026-06-02 02:32:23'),
(88, 2, 'A12', 'PAK HADI', 2, 40, '2026-06-02', 'PCP Express', '1212', 'resi_6a1e510c6f1ad9.41262159.png', 'Selesai', 'LCD', 'Admin HO Jakarta', 33, 7, '2026-06-02 10:42:44', '2026-06-02 03:42:04', '2026-06-02 04:38:06'),
(89, 3, 'A1Q', 'PAK DENI', 2, 40, '2026-06-02', 'PCP EXPRESS', '456', 'resi_6a1ea2d695e977.06541344.jpeg', 'Sudah diterima HO', 'SSD', 'Admin HO Jakarta', 33, 7, '2026-06-03 08:39:10', '2026-06-02 09:31:02', '2026-06-03 01:39:10');

-- --------------------------------------------------------

--
-- Table structure for table `rbac_permissions`
--

CREATE TABLE `rbac_permissions` (
  `id` int NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `permission_name` varchar(150) NOT NULL,
  `menu_group` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `rbac_permissions`
--

INSERT INTO `rbac_permissions` (`id`, `permission_key`, `permission_name`, `menu_group`, `created_at`) VALUES
(1, 'dashboard.view', 'Lihat dashboard', 'Dashboard', '2026-03-24 12:06:21'),
(2, 'barang.view', 'Lihat data barang', 'Barang', '2026-03-24 12:06:21'),
(3, 'barang.create', 'Tambah barang', 'Barang', '2026-03-24 12:06:21'),
(4, 'barang.update', 'Edit data barang masuk', 'Barang', '2026-03-24 12:06:21'),
(5, 'barang.delete', 'Hapus data barang', 'Barang', '2026-03-24 12:06:21'),
(6, 'barang.kirim', 'Update logistik / pengiriman', 'Barang', '2026-03-24 12:06:21'),
(7, 'riwayat.view', 'Lihat riwayat', 'Riwayat', '2026-03-24 12:06:21'),
(8, 'laporan.view', 'Lihat laporan', 'Laporan', '2026-03-24 12:06:21'),
(9, 'users.view', 'Lihat manajemen user', 'Pengguna', '2026-03-24 12:06:21'),
(10, 'users.create', 'Tambah user', 'Pengguna', '2026-03-24 12:06:21'),
(11, 'users.update', 'Edit user', 'Pengguna', '2026-03-24 12:06:21'),
(12, 'users.delete', 'Hapus user', 'Pengguna', '2026-03-24 12:06:21'),
(13, 'role_permissions.manage', 'Atur hak akses role', 'Hak Akses', '2026-03-24 12:06:21'),
(14, 'laporan.pdf', 'Cetak PDF', 'Laporan', '2026-05-06 02:37:23'),
(15, 'laporan.excel', 'Cetak Excel', 'Laporan', '2026-05-06 02:37:23');

-- --------------------------------------------------------

--
-- Table structure for table `rbac_role_permissions`
--

CREATE TABLE `rbac_role_permissions` (
  `role` varchar(30) NOT NULL,
  `permission_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `rbac_role_permissions`
--

INSERT INTO `rbac_role_permissions` (`role`, `permission_id`) VALUES
('admin', 1),
('user', 1),
('admin', 2),
('user', 2),
('admin', 3),
('user', 3),
('admin', 4),
('user', 4),
('admin', 5),
('user', 5),
('admin', 6),
('user', 6),
('admin', 7),
('user', 7),
('admin', 8),
('user', 8),
('admin', 9),
('admin', 10),
('admin', 11),
('admin', 12),
('admin', 13),
('user', 14),
('user', 15);

-- --------------------------------------------------------

--
-- Table structure for table `system_notifications`
--

CREATE TABLE `system_notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `target_role` varchar(32) NOT NULL,
  `target_user_id` int DEFAULT NULL,
  `target_branch_id` int DEFAULT NULL,
  `title` varchar(120) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `system_notifications`
--

INSERT INTO `system_notifications` (`id`, `target_role`, `target_user_id`, `target_branch_id`, `title`, `message`, `link`, `is_read`, `created_at`) VALUES
(136, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 123456 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-05-25 11:08:47'),
(137, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi 12345 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-25 11:16:04'),
(138, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak (Notebook) ke HO Jakarta. Resi: 2323', '../Barang/pengiriman_approval.php', 1, '2026-05-25 13:57:45'),
(140, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 2323 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-05-25 14:00:50'),
(141, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi 12211 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-25 14:17:09'),
(142, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak (Notebook) ke HO Jakarta. Resi: 123123', '../Barang/pengiriman_approval.php', 1, '2026-05-25 14:19:50'),
(143, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 123123 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-05-25 14:20:16'),
(144, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi 12345 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-26 11:01:02'),
(145, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak (Notebook) ke HO Jakarta. Resi: 000', '../Barang/pengiriman_approval.php', 1, '2026-05-26 13:20:20'),
(146, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 000 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-05-26 13:20:44'),
(147, 'user', NULL, 28, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 123456932 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-05-26 13:20:52'),
(148, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak (Notebook) ke HO Jakarta. Resi: 1111', '../Barang/pengiriman_approval.php', 1, '2026-05-26 16:16:07'),
(149, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 1111 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-05-26 16:16:28'),
(150, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi 111 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-26 16:18:03'),
(151, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi 11111 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-26 16:18:41'),
(152, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi 123 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-29 09:03:57'),
(153, 'user', NULL, 49, 'Pengiriman dalam perjalanan', 'Barang dengan resi 1313 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-29 16:30:57'),
(154, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak (CPU) ke HO Jakarta. Resi: 121212', '../Barang/pengiriman_approval.php', 1, '2026-05-29 16:43:10'),
(155, 'user', NULL, 49, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 121212 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 0, '2026-05-29 16:43:29'),
(156, 'user', NULL, 49, 'Pengiriman dalam perjalanan', 'Barang dengan resi 12345 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-29 16:45:28'),
(157, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak ke HO Jakarta. Resi: 988080', '../Barang/pengiriman_approval.php', 1, '2026-05-31 21:20:06'),
(158, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak ke HO Jakarta. Resi: 920920923', '../Barang/pengiriman_approval.php', 1, '2026-05-31 21:51:11'),
(159, 'user', NULL, 28, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 920920923 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-05-31 21:52:18'),
(160, 'user', NULL, 28, 'Pengiriman dalam perjalanan', 'Barang dengan resi 9I32I03 sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-05-31 21:59:01'),
(161, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak ke HO Jakarta. Resi: 123', '../Barang/pengiriman_approval.php', 1, '2026-06-02 08:46:56'),
(162, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 123 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-06-02 08:47:53'),
(163, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi SW2345d sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-06-02 09:01:21'),
(164, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi SW2345d sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-06-02 09:22:48'),
(165, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak ke HO Jakarta. Resi: 1212', '../Barang/pengiriman_approval.php', 1, '2026-06-02 10:42:04'),
(166, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 1212 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-06-02 10:42:44'),
(167, 'user', NULL, 2, 'Pengiriman dalam perjalanan', 'Barang dengan resi SW2345d sedang dalam perjalanan ke cabang Anda.', '../Barang/index.php?filter=masuk', 1, '2026-06-02 11:34:45'),
(168, 'admin', NULL, NULL, 'Pengiriman cabang → HO (menunggu persetujuan)', 'Cabang mengajukan pengiriman barang rusak ke HO Jakarta. Resi: 456', '../Barang/pengiriman_approval.php', 1, '2026-06-02 16:31:02'),
(169, 'user', NULL, 2, 'Barang cabang sudah diterima HO Jakarta', 'Pengiriman dengan resi 456 sudah diterima oleh Admin HO Jakarta.', '../Barang/index.php?filter=keluar', 1, '2026-06-03 08:39:10');

-- --------------------------------------------------------

--
-- Table structure for table `tb_barang`
--

CREATE TABLE `tb_barang` (
  `id_barang` int NOT NULL,
  `id_branch` int DEFAULT NULL,
  `nama_barang` varchar(100) NOT NULL,
  `status` enum('Tersedia','Dalam Perjalanan','Diterima') DEFAULT 'Tersedia'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tb_barang`
--

INSERT INTO `tb_barang` (`id_barang`, `id_branch`, `nama_barang`, `status`) VALUES
(1, NULL, 'Notebook', 'Tersedia'),
(2, NULL, 'Monitor', 'Tersedia'),
(3, NULL, 'CPU', 'Tersedia'),
(4, 51, 'Hardisk', 'Diterima'),
(5, 9, 'Ram', 'Diterima'),
(6, 2, 'Keyboard', 'Diterima'),
(7, 1, 'Mouse', 'Diterima'),
(8, 2, 'Battery', 'Diterima'),
(9, 2, 'Router', 'Diterima'),
(10, 2, 'Kabel LAN', 'Diterima'),
(11, 11, 'Kabel Power', 'Diterima'),
(12, NULL, 'Tang Crimping', 'Tersedia');

-- --------------------------------------------------------

--
-- Table structure for table `tb_branch`
--

CREATE TABLE `tb_branch` (
  `id_branch` int NOT NULL,
  `nama_branch` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tb_branch`
--

INSERT INTO `tb_branch` (`id_branch`, `nama_branch`) VALUES
(1, 'Accounting'),
(2, 'Aceh'),
(3, 'Adaro'),
(4, 'AMNT Project'),
(5, 'AMT System'),
(6, 'AR Collection & Admin'),
(7, 'Banda Aceh'),
(8, 'Bandar Lampung'),
(9, 'Bandung'),
(10, 'Banjarmasin'),
(11, 'Balikpapan'),
(12, 'Balikpapan Project'),
(13, 'Batu Licin'),
(14, 'Bengalon'),
(15, 'Berau'),
(16, 'BPN Training Center'),
(17, 'Branch Sales Support'),
(18, 'Cilegon'),
(19, 'Commercial Contract'),
(20, 'Corp Sec'),
(21, 'Cor Plan & SMO'),
(22, 'Credit Analysis'),
(23, 'Direktur Expat'),
(24, 'Direktur Local'),
(25, 'Export Import'),
(26, 'Finance & Treasury'),
(27, 'Finance Adm'),
(28, 'Finance Balikpapan'),
(29, 'Finance Sangatta'),
(30, 'GA & Assets Management'),
(31, 'GA East & Project'),
(32, 'GAM Kaubun'),
(33, 'General Sales Support'),
(34, 'Gorontalo'),
(35, 'HC East & Project'),
(36, 'HC PA'),
(37, 'HC Recruitment & EPM'),
(38, 'Inf Tech'),
(39, 'Inad'),
(40, 'Jakarta'),
(41, 'Jambi'),
(42, 'Jayapura'),
(43, 'JKT Training Center'),
(44, 'Kendari'),
(45, 'Ketapang'),
(46, 'Kupang'),
(47, 'Legal'),
(48, 'Makassar'),
(49, 'Manado'),
(50, 'Marketing Development'),
(51, 'Medan'),
(52, 'Merak'),
(53, 'Merauke'),
(54, 'Mining Sales & Wenco'),
(55, 'Morowali'),
(56, 'Muara Bungo'),
(57, 'Muara Enim'),
(58, 'Muara Teweh'),
(59, 'NUES'),
(60, 'NUES Balikpapan'),
(61, 'Padang'),
(62, 'Palembang'),
(63, 'Palu'),
(64, 'Pangkal Pinang'),
(65, 'Pani Project'),
(66, 'Parts Inventory & System'),
(67, 'Parts Warehouse'),
(68, 'Parts Warehouse BPN'),
(69, 'Parts Warehouse SGT'),
(70, 'Pekanbaru'),
(71, 'Pontianak'),
(72, 'Procurement & Investment'),
(73, 'Project Sales Support'),
(74, 'PS Support'),
(75, 'QSHE'),
(76, 'QSHE Balikpapan'),
(77, 'Sales Adm'),
(78, 'Sales Planning'),
(79, 'Samarinda'),
(80, 'Samarinda Project'),
(81, 'Sampit'),
(82, 'Sangatta'),
(83, 'Semarang'),
(84, 'Service Admin'),
(85, 'Sims Kideco'),
(86, 'SIS Adaro'),
(87, 'Sorong'),
(88, 'Sungai Baung'),
(89, 'Surabaya'),
(90, 'Tarakan'),
(91, 'Tax'),
(92, 'Tech Support'),
(93, 'Tech Support (Mining)'),
(94, 'Tanjung Pandan'),
(95, 'Training Center'),
(96, 'Vale Soroako'),
(97, 'Value Chain Promotion'),
(98, 'Weda'),
(99, 'Welding SGT'),
(100, 'Welding SMO'),
(101, 'Wetar');

-- --------------------------------------------------------

--
-- Table structure for table `tb_ekspedisi`
--

CREATE TABLE `tb_ekspedisi` (
  `id_ekspedisi` int NOT NULL,
  `nama_ekspedisi` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tb_ekspedisi`
--

INSERT INTO `tb_ekspedisi` (`id_ekspedisi`, `nama_ekspedisi`, `created_at`, `updated_at`) VALUES
(1, 'SAP Express', '2026-05-22 15:51:08', '2026-05-22 15:51:08'),
(2, 'PCP Express', '2026-05-22 15:51:08', '2026-05-22 15:51:08');

-- --------------------------------------------------------

--
-- Table structure for table `tb_jenis`
--

CREATE TABLE `tb_jenis` (
  `id_jenis` int NOT NULL,
  `nama_jenis` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tb_jenis`
--

INSERT INTO `tb_jenis` (`id_jenis`, `nama_jenis`) VALUES
(1, 'Baru'),
(2, 'Bekas'),
(3, 'Service'),
(4, 'Rusak');

-- --------------------------------------------------------

--
-- Table structure for table `tb_merk`
--

CREATE TABLE `tb_merk` (
  `id_merk` int NOT NULL,
  `nama_merk` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tb_merk`
--

INSERT INTO `tb_merk` (`id_merk`, `nama_merk`) VALUES
(1, 'DELL'),
(2, 'Hp'),
(3, 'LG'),
(4, 'Samsung'),
(5, 'Kingston'),
(6, 'WD Blue'),
(7, 'Transcend'),
(8, 'V-Gen'),
(9, 'Hynix');

-- --------------------------------------------------------

--
-- Table structure for table `tb_status`
--

CREATE TABLE `tb_status` (
  `id_status` int NOT NULL,
  `nama_status` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tb_status`
--

INSERT INTO `tb_status` (`id_status`, `nama_status`) VALUES
(1, 'Dipinjamkan'),
(2, 'Sudah Di Terima'),
(3, 'Sudah Di Kirim'),
(4, 'Tersedia'),
(5, 'Rusak');

-- --------------------------------------------------------

--
-- Table structure for table `tb_tipe`
--

CREATE TABLE `tb_tipe` (
  `id_tipe` int NOT NULL,
  `id_barang` int DEFAULT NULL,
  `id_merk` int DEFAULT NULL,
  `nama_tipe` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `tb_tipe`
--

INSERT INTO `tb_tipe` (`id_tipe`, `id_barang`, `id_merk`, `nama_tipe`) VALUES
(1, 1, 1, 'Dell Pro 14PC14250'),
(2, 1, 1, 'Inspiron 3581'),
(3, 1, 1, 'Latitude 3400'),
(4, 1, 1, 'Latitude 3410'),
(5, 1, 1, 'Latitude 3420'),
(6, 1, 1, 'Latitude 3440'),
(7, 1, 1, 'Latitude 3450'),
(8, 1, 1, 'Latitude 3490'),
(9, 1, 1, 'Latitude 3300'),
(10, 1, 1, 'Latitude 3350'),
(11, 3, 1, 'OptiPlex Tower 3050'),
(12, 3, 1, 'OptiPlex Tower 5050'),
(13, 3, 1, 'OptiPlex Tower 5060'),
(14, 3, 1, 'OptiPlex Tower 5070'),
(15, 3, 1, 'OptiPlex Tower 5090'),
(16, 3, 1, 'OptiPlex Tower 7020'),
(17, 3, 1, 'OptiPlex Tower 7010'),
(18, 2, 1, 'Dell SE2225H'),
(19, 5, 5, 'DDR 4 3200 - 16GB SO-DIMM'),
(20, 5, 5, 'DDR 4 2666 - 8GB SO-DIMM'),
(21, 5, 5, 'DDR 4 2666 - 16GB SO-DIMM'),
(22, 5, 5, 'DDR 4 12800 - 8GB SO-DIMM'),
(23, 5, 5, 'DDR 3 12800 - 8GB SO-DIMM'),
(24, 5, 5, 'DDR 4 3200 - 8GB SO-DIMM'),
(25, 5, 5, 'DDR 4 2666 CL19 260-16GB LONG-DIMM'),
(26, NULL, NULL, 'DDR 4 2400 - 8GB LONG-DIMM'),
(27, NULL, NULL, 'DDR 4 3200 - 16GB LONG-DIMM'),
(28, NULL, NULL, 'DDR 4 3200 - 8GB LONG-DIMM'),
(29, NULL, NULL, 'DDR 4 2666 - 16GB LONG-DIMM'),
(30, NULL, NULL, 'DDR 4 2666 - 8GB LONG-DIMM'),
(31, NULL, NULL, 'DDR 4 21300 - 16GB LONG-DIMM'),
(32, NULL, NULL, 'DDR 4 3200 CL22 288-16GB LONG-DIMM'),
(33, NULL, NULL, 'SSD NVME M.2 1TB'),
(34, NULL, NULL, 'SATA 2.5 1TB'),
(35, NULL, NULL, 'SATA 2.5 500GB'),
(36, NULL, NULL, 'SSD PCle 4.0 NVme M.2 1TB'),
(37, NULL, NULL, 'SSD PCle 4.0 NVme M.2 500Gb'),
(38, NULL, NULL, 'SSD SN5000 NVme M.2 1TB'),
(39, NULL, NULL, 'SATA SSD 2.5 1TB'),
(40, NULL, NULL, '1W217 A02');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `password_changed_at` datetime DEFAULT NULL,
  `password_reset_at` datetime DEFAULT NULL,
  `password_reset_by` int DEFAULT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `id_branch` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `created_at`, `must_change_password`, `password_changed_at`, `password_reset_at`, `password_reset_by`, `role`, `id_branch`) VALUES
(7, 'admin', 'admin@gmail.com', '$2y$10$BADbfT/I.V0dYlAk2do8GuThz9pjJy1onyyYyHlXOagOEaSPnMu3u', '2026-04-22 16:35:04', 0, NULL, NULL, NULL, 'admin', 40),
(32, 'abuzar', 'abuzar@gmail.com', '$2y$10$vOIT.tG/xdFxEPaX3qwNu.JDZ4P1WfBQNUFsCCgW9NTjjxrITxX2q', '2026-05-02 21:13:52', 0, '2026-05-16 21:26:53', '2026-05-16 21:26:15', 7, 'user', 51),
(33, 'Winda', 'winda@gmail.com', '$2y$10$MRmJ7QbtiQEYqUNMOKJnsubUpcA4/381pR/mFExj8WcS/b9lfOlk6', '2026-05-04 09:39:23', 0, '2026-05-04 09:40:23', NULL, NULL, 'user', 2),
(34, 'Supam', 'supam@gmail.com', '$2y$10$6bAuNIZREFmLum/NKC/iqu0vtWHP.S071PygYSg1pRA7VVcrnQAM.', '2026-05-04 13:40:09', 0, '2026-05-04 13:40:43', NULL, NULL, 'user', 1),
(35, 'Desri', 'Desri@gmail.com', '$2y$10$uLV1niZ3muSKqRzwqgb/zOnzYZPJgiLQsNeaoCw4EV0oHtaD18r7S', '2026-05-07 09:25:14', 0, '2026-05-26 13:23:48', NULL, NULL, 'user', 49),
(36, 'Deni', 'deni@gmail.com', '$2y$10$RQsODVN30oCeUoojAZgJSeeCgK7hkIOScixvHCXXR4mAUYrljD.42', '2026-05-07 09:35:30', 0, '2026-05-07 09:40:33', NULL, NULL, 'user', 11),
(37, 'Rifki', 'rifki@gmail.com', '$2y$10$O2qimjlUFn.VtRvxj61iTOmMozgSiHTh1pfCfL28JJg5Atf108XHq', '2026-05-07 09:36:17', 1, NULL, NULL, NULL, 'user', 64),
(38, 'Nabila', 'biya@gmail.com', '$2y$10$ILH/tZcd2f8Tk5gyG98kGuWhCcp8NoIlZwlsuS4C7gyxWOA/wggBy', '2026-05-10 20:52:47', 0, '2026-05-10 20:59:39', NULL, NULL, 'user', 28),
(39, 'dwi', 'dwi@gmail.com', '$2y$10$MrGO7zFguBnLclbsVjkjxuMZUGmWZWyLZgsOausxo.rKSgbnBb4Yy', '2026-05-11 14:26:13', 0, '2026-05-11 14:28:32', NULL, NULL, 'user', 41);

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

CREATE TABLE `user_activity_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int DEFAULT NULL,
  `role` varchar(32) DEFAULT NULL,
  `branch_id` int DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `path` varchar(255) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_password_history`
--

CREATE TABLE `user_password_history` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_password_history`
--

INSERT INTO `user_password_history` (`id`, `user_id`, `password_hash`, `created_at`) VALUES
(11, 35, '$2y$10$uLV1niZ3muSKqRzwqgb/zOnzYZPJgiLQsNeaoCw4EV0oHtaD18r7S', '2026-05-26 13:23:48');

-- --------------------------------------------------------

--
-- Table structure for table `user_presence`
--

CREATE TABLE `user_presence` (
  `user_id` int NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `last_seen_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_presence`
--

INSERT INTO `user_presence` (`user_id`, `session_id`, `ip_address`, `user_agent`, `last_seen_at`, `updated_at`) VALUES
(7, 'cu89i6ierh3af01c6vj0ohpl48', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 10:16:29', '2026-06-03 10:16:29'),
(31, 'tu6mbci3lntvo7veg29am59gkp', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-02 22:40:33', '2026-05-02 22:40:33'),
(32, '96lkjoic4mbe054pd1v2h25jre', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-17 08:04:57', '2026-05-17 08:04:57'),
(33, 'p69605r6ijeri71dmp8bbcemsj', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-03 09:18:45', '2026-06-03 09:18:45'),
(34, '32sot684a11denn9oliattnsm8', '2404:c0:20e0::34a8:b92f', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-26 13:24:59', '2026-05-26 13:24:59'),
(35, '5ui154g2t2untc9kemqe4p2u11', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-05-29 16:52:23', '2026-05-29 16:52:23'),
(36, 'm8ga5dntoq2nvoh38eriv0a3o7', '2001:448a:9010:713f:187a:79c3:c384:e0da', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-07 09:56:23', '2026-05-07 09:56:23'),
(38, '80gt925b3e0e4s3gn8o1ghff54', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-31 22:01:24', '2026-05-31 22:01:24'),
(39, 'mrltrfg4fs5tlacu44t7qni537', '2404:c0:2570::2a8f:5d58', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 Edg/148.0.0.0', '2026-05-11 14:33:18', '2026-05-11 14:33:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barang`
--
ALTER TABLE `barang`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `barang_pengiriman`
--
ALTER TABLE `barang_pengiriman`
  ADD PRIMARY KEY (`id_pengiriman`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_password_resets_reset_token` (`reset_token`),
  ADD KEY `idx_password_resets_user_id` (`user_id`);

--
-- Indexes for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `pengiriman_cabang_ho`
--
ALTER TABLE `pengiriman_cabang_ho`
  ADD PRIMARY KEY (`id_pengiriman_ho`),
  ADD KEY `idx_pengiriman_cabang_ho_status` (`status_pengiriman`),
  ADD KEY `idx_pengiriman_cabang_ho_barang` (`id_barang`),
  ADD KEY `idx_pengiriman_cabang_ho_asal` (`branch_asal`),
  ADD KEY `idx_pengiriman_cabang_ho_tujuan` (`branch_tujuan`);

--
-- Indexes for table `rbac_permissions`
--
ALTER TABLE `rbac_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_key` (`permission_key`);

--
-- Indexes for table `rbac_role_permissions`
--
ALTER TABLE `rbac_role_permissions`
  ADD PRIMARY KEY (`role`,`permission_id`),
  ADD KEY `fk_role_permissions_permission` (`permission_id`);

--
-- Indexes for table `system_notifications`
--
ALTER TABLE `system_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_system_notifications_target_role` (`target_role`),
  ADD KEY `idx_system_notifications_is_read` (`is_read`),
  ADD KEY `idx_system_notifications_created_at` (`created_at`),
  ADD KEY `idx_system_notifications_target_user_id` (`target_user_id`),
  ADD KEY `idx_system_notifications_target_branch_id` (`target_branch_id`);

--
-- Indexes for table `tb_ekspedisi`
--
ALTER TABLE `tb_ekspedisi`
  ADD PRIMARY KEY (`id_ekspedisi`),
  ADD UNIQUE KEY `uq_tb_ekspedisi_nama` (`nama_ekspedisi`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_id_branch` (`id_branch`);

--
-- Indexes for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_activity_logs_user_id` (`user_id`),
  ADD KEY `idx_user_activity_logs_created_at` (`created_at`),
  ADD KEY `idx_user_activity_logs_action` (`action`);

--
-- Indexes for table `user_password_history`
--
ALTER TABLE `user_password_history`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_presence`
--
ALTER TABLE `user_presence`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_user_presence_last_seen` (`last_seen_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barang`
--
ALTER TABLE `barang`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `barang_pengiriman`
--
ALTER TABLE `barang_pengiriman`
  MODIFY `id_pengiriman` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `pengiriman_cabang_ho`
--
ALTER TABLE `pengiriman_cabang_ho`
  MODIFY `id_pengiriman_ho` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `rbac_permissions`
--
ALTER TABLE `rbac_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `system_notifications`
--
ALTER TABLE `system_notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=170;

--
-- AUTO_INCREMENT for table `tb_ekspedisi`
--
ALTER TABLE `tb_ekspedisi`
  MODIFY `id_ekspedisi` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `user_activity_logs`
--
ALTER TABLE `user_activity_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_password_history`
--
ALTER TABLE `user_password_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `password_reset_requests`
--
ALTER TABLE `password_reset_requests`
  ADD CONSTRAINT `password_reset_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `password_reset_requests_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `rbac_role_permissions`
--
ALTER TABLE `rbac_role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `rbac_permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
