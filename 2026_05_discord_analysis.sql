-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 13, 2026 at 03:24 PM
-- Server version: 5.7.24
-- PHP Version: 8.2.2

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `2026_05_discord_analysis`
--

-- --------------------------------------------------------

--
-- Table structure for table `kuisoner`
--

CREATE TABLE `kuisoner` (
  `id` int(10) UNSIGNED NOT NULL COMMENT 'ID',
  `pertanyaan` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Pertanyaan',
  `kategori` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Kategori',
  `urutan` int(11) NOT NULL DEFAULT '0' COMMENT 'Urutan',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Dibuat Pada'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabel daftar pertanyaan survei Discord';

--
-- Dumping data for table `kuisoner`
--

INSERT INTO `kuisoner` (`id`, `pertanyaan`, `kategori`, `urutan`, `created_at`) VALUES
(1, 'Discord mudah diakses melalui berbagai perangkat (PC, HP, Tablet).', 'Aksesibilitas & Kemudahan Penggunaan', 1, '2026-05-01 01:00:00'),
(2, 'Antarmuka Discord mudah dipahami oleh mahasiswa baru.', 'Aksesibilitas & Kemudahan Penggunaan', 2, '2026-05-01 01:00:00'),
(3, 'Proses bergabung ke server dan channel Discord tergolong mudah untuk kegiatan akademik.', 'Aksesibilitas & Kemudahan Penggunaan', 3, '2026-05-01 01:00:00'),
(4, 'Fitur voice channel Discord mendukung diskusi kelompok secara efektif.', 'Fitur & Fungsionalitas', 4, '2026-05-01 01:00:00'),
(5, 'Fitur text channel Discord memudahkan berbagi materi dan pengumuman perkuliahan.', 'Fitur & Fungsionalitas', 5, '2026-05-01 01:00:00'),
(6, 'Fitur screen sharing Discord membantu presentasi dan tutorial selama diskusi belajar.', 'Fitur & Fungsionalitas', 6, '2026-05-01 01:00:00'),
(7, 'Discord meningkatkan intensitas komunikasi antar mahasiswa dalam kegiatan belajar.', 'Efektivitas Komunikasi', 7, '2026-05-01 01:00:00'),
(8, 'Discord mempermudah koordinasi tugas kelompok dan pembagian informasi akademik.', 'Efektivitas Komunikasi', 8, '2026-05-01 01:00:00'),
(9, 'Notifikasi Discord membantu mahasiswa tetap mengikuti perkembangan diskusi perkuliahan.', 'Efektivitas Komunikasi', 9, '2026-05-01 01:00:00'),
(10, 'Discord layak dijadikan platform diskusi resmi untuk kegiatan perkuliahan.', 'Kelayakan sebagai Platform Akademik', 10, '2026-05-01 01:00:00'),
(11, 'Discord efektif digunakan sebagai media interaksi antara mahasiswa dan dosen.', 'Kelayakan sebagai Platform Akademik', 11, '2026-05-01 01:00:00'),
(12, 'Saya merekomendasikan Discord sebagai media diskusi akademik di lingkungan kampus.', 'Kelayakan sebagai Platform Akademik', 12, '2026-05-01 01:00:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `kuisoner`
--
ALTER TABLE `kuisoner`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `kuisoner`
--
ALTER TABLE `kuisoner`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID', AUTO_INCREMENT=13;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
