-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: db_larashop
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (4,'Insektisida','insektisida','Pengendali serangga & hama',1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(5,'Fungisida','fungisida','Pengendali penyakit jamur',1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(6,'ZPT & Hormon','zpt-hormon','Zat pengatur tumbuh, hormon & biostimulan',1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_addresses`
--

DROP TABLE IF EXISTS `customer_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `recipient_name` varchar(255) NOT NULL,
  `recipient_phone` varchar(255) NOT NULL,
  `destination_id` bigint(20) unsigned DEFAULT NULL,
  `province` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `district` varchar(255) NOT NULL,
  `subdistrict` varchar(255) NOT NULL,
  `postal_code` varchar(10) NOT NULL,
  `address_line` text NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_addresses_user_id_foreign` (`user_id`),
  CONSTRAINT `customer_addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_addresses`
--

LOCK TABLES `customer_addresses` WRITE;
/*!40000 ALTER TABLE `customer_addresses` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_05_14_232918_create_personal_access_tokens_table',1),(5,'2026_05_15_060000_create_catalog_tables',1),(6,'2026_05_15_060100_create_customer_addresses_table',1),(7,'2026_05_15_060200_create_shipping_and_orders_tables',1),(8,'2026_05_15_090000_add_workflow_fields_to_orders_table',2),(9,'2026_05_15_091000_add_admin_role_to_users_table',3),(10,'2026_05_16_000100_add_destination_id_to_customer_addresses_table',4),(11,'2026_05_16_010000_create_shipment_origins_table',5),(12,'2026_05_16_020000_rename_destination_id_to_origin_id_on_shipment_origins_table',6),(13,'2026_05_16_030000_add_selected_courier_to_shipment_origins_table',7),(14,'2026_05_16_040000_add_is_selected_to_order_items_table',8),(15,'2026_05_16_050000_make_order_items_unselected_by_default',9),(16,'2026_05_16_060000_create_user_unique_codes_table',10),(17,'2026_05_16_070000_add_used_unique_code_to_orders_table',11),(18,'2026_05_16_080000_add_product_variants_table',12),(19,'2026_05_17_090000_add_variant_snapshot_to_order_items_table',13),(20,'2026_06_07_000100_create_wa_messages_table',14),(21,'2026_06_09_000100_create_payment_accounts_table',15),(22,'2026_06_09_000200_create_settings_table',16),(23,'2026_06_09_000300_add_komerce_shipment_fields_to_orders_table',17),(24,'2026_06_09_000400_add_shipping_cashback_to_orders_table',18),(25,'2026_06_09_000500_create_order_trackings_table',19),(26,'2026_06_09_000600_add_pin_point_to_shipment_origins_table',20),(27,'2026_06_10_000100_drop_legacy_shipping_services',21);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `product_variant_id` bigint(20) unsigned DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_sku` varchar(255) DEFAULT NULL,
  `variant_label` varchar(255) DEFAULT NULL,
  `weight_grams` int(10) unsigned DEFAULT NULL,
  `price` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `subtotal` bigint(20) unsigned NOT NULL,
  `is_selected` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_order_id_foreign` (`order_id`),
  KEY `order_items_product_id_foreign` (`product_id`),
  KEY `order_items_product_variant_id_foreign` (`product_variant_id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_product_variant_id_foreign` FOREIGN KEY (`product_variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_trackings`
--

DROP TABLE IF EXISTS `order_trackings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_trackings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `source` varchar(255) NOT NULL DEFAULT 'app',
  `raw_status` varchar(255) DEFAULT NULL,
  `awb` varchar(255) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_trackings_order_id_id_index` (`order_id`,`id`),
  CONSTRAINT `order_trackings_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_trackings`
--

LOCK TABLES `order_trackings` WRITE;
/*!40000 ALTER TABLE `order_trackings` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_trackings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `customer_address_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('draft','pending_payment','paid','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'draft',
  `payment_method` varchar(255) DEFAULT NULL,
  `payment_status` varchar(255) DEFAULT NULL,
  `items_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  `shipping_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  `shipping_cashback` bigint(20) unsigned NOT NULL DEFAULT 0,
  `unique_code` bigint(20) unsigned NOT NULL DEFAULT 0,
  `used_unique_code` bigint(20) unsigned NOT NULL DEFAULT 0,
  `grand_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  `shipping_service_name` varchar(255) DEFAULT NULL,
  `shipping_courier_code` varchar(30) DEFAULT NULL,
  `shipping_service_code` varchar(30) DEFAULT NULL,
  `shipping_estimate_days` varchar(255) DEFAULT NULL,
  `awb` varchar(255) DEFAULT NULL,
  `komerce_order_no` varchar(255) DEFAULT NULL,
  `komerce_order_id` bigint(20) unsigned DEFAULT NULL,
  `shipment_note` text DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `shipped_at` timestamp NULL DEFAULT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `recipient_phone` varchar(255) DEFAULT NULL,
  `address_label` varchar(255) DEFAULT NULL,
  `address_snapshot` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_code_unique` (`code`),
  KEY `orders_user_id_foreign` (`user_id`),
  KEY `orders_customer_address_id_foreign` (`customer_address_id`),
  CONSTRAINT `orders_customer_address_id_foreign` FOREIGN KEY (`customer_address_id`) REFERENCES `customer_addresses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_accounts`
--

DROP TABLE IF EXISTS `payment_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(255) NOT NULL,
  `account_number` varchar(50) NOT NULL,
  `account_holder` varchar(255) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_accounts`
--

LOCK TABLES `payment_accounts` WRITE;
/*!40000 ALTER TABLE `payment_accounts` DISABLE KEYS */;
INSERT INTO `payment_accounts` VALUES (1,'BCA','99991029','Fredi Nasution',NULL,1,0,'2026-06-08 23:04:45','2026-06-08 23:16:22'),(2,'Mandiri','88808970','Fredi Nasution',NULL,0,0,'2026-06-08 23:05:24','2026-06-08 23:05:24');
/*!40000 ALTER TABLE `payment_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES (4,'App\\Models\\User',2,'larashop-fe','dc0e0778392ed25f0a8817a2e407e53c12028b09a205907a6e0b9d809316da2a','[\"role:customer\"]','2026-05-15 09:17:15',NULL,'2026-05-15 06:26:26','2026-05-15 09:17:15'),(7,'App\\Models\\User',1,'larashop-fe-admin-panel','43216c9cd9fcc33295a90a42d615f509dfcc4a81717cc438e7776e093bf1aaaf','[\"role:admin\"]','2026-05-16 10:47:23',NULL,'2026-05-15 23:29:26','2026-05-16 10:47:23'),(8,'App\\Models\\User',2,'larashop-fe','1f6b5290e860406135e7294f8a2dd658a4d1eeb627ebaf4f5a844c90068344db','[\"role:customer\"]','2026-05-16 10:47:20',NULL,'2026-05-16 00:34:13','2026-05-16 10:47:20'),(9,'App\\Models\\User',1,'larashop-fe-admin-panel','03560908badf2ecc55058c4b8378482350e8f0648205002aa7f6327a96fe7469','[\"role:admin\"]',NULL,NULL,'2026-05-16 15:09:49','2026-05-16 15:09:49'),(14,'App\\Models\\User',1,'larashop-fe-admin-panel','dcea9320fa0fb8c0978bfb02eb51c8ff3c55b737c6738cc50bb1948b3904abbe','[\"role:admin\"]','2026-05-16 17:58:06',NULL,'2026-05-16 17:55:32','2026-05-16 17:58:06'),(15,'App\\Models\\User',1,'larashop-fe-admin-panel','e5f8c346d395da9530bfa236a8ea7568bead5049748f7c2c568a3f1e79b57413','[\"role:admin\"]','2026-05-16 19:15:08',NULL,'2026-05-16 18:07:53','2026-05-16 19:15:08'),(16,'App\\Models\\User',1,'larashop-fe-admin-panel','d09e453b740c4b7c66a246cf5ea053a4c5a9c2a33b34ddc8f0ba407299ce1bd8','[\"role:admin\"]','2026-05-16 23:58:31',NULL,'2026-05-16 23:58:18','2026-05-16 23:58:31'),(17,'App\\Models\\User',2,'larashop-fe','9060f419e92c3293d3e1269a8a5ec8f50fb726219437d57e27d58970e59dbde6','[\"role:customer\"]','2026-06-01 19:24:38',NULL,'2026-06-01 18:25:33','2026-06-01 19:24:38'),(18,'App\\Models\\User',7,'larashop-fe','2533c4f911de3162bab982c95d1d13fdd90046d7edaf7e50b0fdb165a27ef7b4','[\"role:customer\"]','2026-06-09 10:09:29',NULL,'2026-06-08 21:38:43','2026-06-09 10:09:29'),(21,'App\\Models\\User',1,'larashop-fe-admin-panel','768ffd80a876f4556b3fcf33b5de46d691bb65d9dfc2ed2adf284fdc151bb435','[\"role:admin\"]','2026-06-09 10:24:54',NULL,'2026-06-08 23:02:39','2026-06-09 10:24:54');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_images`
--

DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `path` varchar(255) NOT NULL,
  `alt` varchar(255) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_images_product_id_foreign` (`product_id`),
  CONSTRAINT `product_images_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_images`
--

LOCK TABLES `product_images` WRITE;
/*!40000 ALTER TABLE `product_images` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variants`
--

DROP TABLE IF EXISTS `product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `price` bigint(20) unsigned NOT NULL,
  `compare_at_price` bigint(20) unsigned DEFAULT NULL,
  `stock` int(10) unsigned NOT NULL DEFAULT 0,
  `weight_grams` int(10) unsigned DEFAULT NULL,
  `length_cm` decimal(8,2) DEFAULT NULL,
  `width_cm` decimal(8,2) DEFAULT NULL,
  `height_cm` decimal(8,2) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_variants_sku_unique` (`sku`),
  KEY `product_variants_product_id_foreign` (`product_id`),
  CONSTRAINT `product_variants_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` VALUES (8,7,'TANI-001-250','250 ml',100000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(9,7,'TANI-001-500','500 ml',190000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(10,7,'TANI-001-1000','1000 ml',360000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(11,8,'TANI-002-250','250 ml',80000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(12,8,'TANI-002-500','500 ml',130000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(13,8,'TANI-002-1000','1000 ml',245000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(14,9,'TANI-003-100','100 ml',100000,NULL,100,150,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(15,9,'TANI-003-250','250 ml',250000,NULL,100,320,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(16,9,'TANI-003-500','500 ml',495000,NULL,100,580,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(17,10,'TANI-004-250','250 ml',65000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(18,10,'TANI-004-500','500 ml',125000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(19,10,'TANI-004-1000','1000 ml',230000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(20,11,'TANI-005-250','250 ml',50000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(21,11,'TANI-005-500','500 ml',90000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(22,11,'TANI-005-1000','1000 ml',160000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(23,12,'TANI-006-250','250 ml',70000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(24,12,'TANI-006-500','500 ml',125000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(25,12,'TANI-006-1000','1000 ml',235000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(26,13,'TANI-007-250','250 ml',80000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(27,13,'TANI-007-500','500 ml',130000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(28,13,'TANI-007-1000','1000 ml',245000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(29,14,'TANI-008-1000','1000 ml',68000,NULL,100,1100,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(30,15,'TANI-009-100','100 ml',30000,NULL,100,150,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(31,16,'TANI-010-100','100 ml',24000,NULL,100,150,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(32,17,'TANI-011-100','100 ml',27000,NULL,100,150,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(33,18,'TANI-012-250','250 ml',55000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(34,18,'TANI-012-500','500 ml',97000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(35,18,'TANI-012-1000','1000 ml',185000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(36,19,'TANI-013-100','100 ml',30000,NULL,100,150,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(37,20,'TANI-014-500','500 ml',70000,NULL,100,580,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(38,20,'TANI-014-1000','1000 ml',130000,NULL,100,1100,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(39,21,'TANI-015-500','500 ml',80000,NULL,100,580,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(40,21,'TANI-015-1000','1000 ml',130000,NULL,100,1100,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(41,22,'TANI-016-100','100 ml',35000,NULL,100,150,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(42,22,'TANI-016-250','250 ml',75000,NULL,100,320,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(43,22,'TANI-016-500','500 ml',140000,NULL,100,580,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(44,22,'TANI-016-1000','1000 ml',265000,NULL,100,1100,NULL,NULL,NULL,0,1,4,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(45,23,'TANI-017-500','500 ml',72000,NULL,100,580,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(46,23,'TANI-017-1000','1000 ml',135000,NULL,100,1100,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(47,24,'TANI-018-250','250 ml',82000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(48,24,'TANI-018-500','500 ml',160000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(49,24,'TANI-018-1000','1000 ml',300000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(50,25,'TANI-019-250','250 ml',98000,NULL,100,320,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(51,25,'TANI-019-500','500 ml',180000,NULL,100,580,NULL,NULL,NULL,0,1,2,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(52,25,'TANI-019-1000','1000 ml',350000,NULL,100,1100,NULL,NULL,NULL,0,1,3,'2026-06-09 01:46:37','2026-06-09 01:46:37'),(53,26,'TANI-020-100','100 ml',90000,NULL,100,150,NULL,NULL,NULL,1,1,1,'2026-06-09 01:46:37','2026-06-09 01:46:37');
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_description` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `price` bigint(20) unsigned NOT NULL,
  `compare_at_price` bigint(20) unsigned DEFAULT NULL,
  `weight_label` varchar(255) DEFAULT NULL,
  `weight_grams` int(10) unsigned DEFAULT NULL,
  `length_cm` decimal(8,2) DEFAULT NULL,
  `width_cm` decimal(8,2) DEFAULT NULL,
  `height_cm` decimal(8,2) DEFAULT NULL,
  `stock` int(10) unsigned NOT NULL DEFAULT 0,
  `public_status` enum('draft','active','inactive','preorder') NOT NULL DEFAULT 'draft',
  `catalog_status` enum('available','limited','preorder','sold_out') NOT NULL DEFAULT 'available',
  `badge_label` varchar(255) DEFAULT NULL,
  `sold_count` int(10) unsigned NOT NULL DEFAULT 0,
  `highlights` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`highlights`)),
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_sku_unique` (`sku`),
  UNIQUE KEY `products_slug_unique` (`slug`),
  KEY `products_category_id_foreign` (`category_id`),
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (7,4,'TANI-001','emamectin-benzoat-lufenuron','Emamectin benzoat + lufenuron',NULL,'Mengendalikan ulat grayak, ulat daun, dan penggerek buah. Bekerja cepat sekaligus menghambat perkembangan larva sehingga pengendalian lebih maksimal.',100000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(8,5,'TANI-002','difenoconazole-azoxystrobin','Difeconazole+azoxistrobin',NULL,'Fungisida sistemik untuk mengendalikan antraknosa, bercak daun, embun tepung, karat daun, dan berbagai penyakit jamur lainnya.',80000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(9,4,'TANI-003','broflanilida','Broflanilida',NULL,'Insektisida generasi baru yang efektif mengendalikan ulat, thrips, penggerek, dan hama pengunyah daun lainnya.',100000,NULL,'100 ml',150,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(10,4,'TANI-004','abamectin-bening','Abamectin bening',NULL,'Mengendalikan tungau, thrips, kutu daun, dan ulat. Bekerja cepat menghentikan aktivitas makan hama.',65000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(11,4,'TANI-005','fipronil','Fipronil',NULL,'Mengendalikan wereng, semut, rayap, penggerek batang, dan berbagai hama serangga lainnya.',50000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(12,5,'TANI-006','difenoconazole','Difeconazole',NULL,'Fungisida sistemik untuk mengendalikan antraknosa, bercak daun, embun tepung, dan penyakit jamur lainnya.',70000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(13,5,'TANI-007','metalaxyl','Metalaxyl',NULL,'Fungisida sistemik untuk mengendalikan busuk akar, rebah semai, busuk batang, dan penyakit akibat jamur tanah.',80000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(14,6,'TANI-008','asam-amino-biostimulant','Asam amino Biostimulant + unsur-unsur',NULL,'Membantu mempercepat pertumbuhan tanaman, memperkuat akar, meningkatkan kehijauan daun, dan mengurangi stres tanaman.',68000,NULL,'1000 ml',1100,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(15,6,'TANI-009','hormon-ga3-10000-ppm','Hormon GA3 10.000ppm',NULL,'Merangsang pertumbuhan tanaman, pembungaan, pembesaran buah, dan memecah masa dormansi.',30000,NULL,'100 ml',150,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(16,6,'TANI-010','hormon-auxin-10000-ppm','Hormon Auxin 10.000ppm',NULL,'Merangsang pembentukan akar, pertumbuhan tunas, serta mengurangi kerontokan bunga dan buah.',24000,NULL,'100 ml',150,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(17,6,'TANI-011','hormon-sitokinin-10000-ppm','Hormon Sitokinin 10.000ppm',NULL,'Merangsang pembelahan sel, pertumbuhan tunas, dan menjaga daun tetap hijau lebih lama.',27000,NULL,'100 ml',150,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(18,6,'TANI-012','paclobutrazol-50000-ppm','ZPT Paclobutrazol 50.000ppm',NULL,'Membantu merangsang pembungaan dan mengurangi pertumbuhan vegetatif yang berlebihan.',55000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(19,6,'TANI-013','cppu-1000-ppm','ZPT CPPU 1000ppm (pembesar buah)',NULL,'Membantu meningkatkan ukuran, bobot, dan kualitas buah.',30000,NULL,'100 ml',150,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(20,6,'TANI-014','sodium-nitrophenolate-atonik-20-sl','ZPT sodium nitrophenolat (atonik)20 SL',NULL,'Perangsang pertumbuhan yang membantu pembentukan akar, tunas, bunga, dan buah.',70000,NULL,'500 ml',580,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(21,6,'TANI-015','da-6','ZPT DA-6 (Dimethil amino hexaonate)',NULL,'Membantu meningkatkan fotosintesis, pertumbuhan akar, pembungaan, dan produktivitas tanaman.',80000,NULL,'500 ml',580,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(22,6,'TANI-016','triacontanol-1000-ppm','Triacontanol 1000ppm',NULL,'Meningkatkan fotosintesis, pertumbuhan tanaman, pembentukan bunga, dan hasil panen.',35000,NULL,'100 ml',150,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(23,4,'TANI-017','cypermethrin-50-ec','Cypermetrin 50EC',NULL,'Insektisida kontak dan lambung dengan efek cepat untuk mengendalikan ulat, kutu, wereng, dan penggerek.',72000,NULL,'500 ml',580,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(24,5,'TANI-018','pyraclostrobin-100-ec','Pyraclostrobin 100EC',NULL,'Fungisida sistemik yang membantu mengendalikan berbagai penyakit jamur sekaligus menjaga kesehatan tanaman.',82000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(25,5,'TANI-019','penconazole-100-ec','Penconazole 100EC',NULL,'Fungisida sistemik yang efektif mengendalikan embun tepung, karat daun, dan berbagai penyakit jamur lainnya.',98000,NULL,'250 ml',320,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37'),(26,6,'TANI-020','brassinolide','Brasinolide',NULL,'ZPT premium yang membantu meningkatkan pertumbuhan, pembungaan, pembuahan, serta ketahanan tanaman terhadap stres lingkungan.',90000,NULL,'100 ml',150,NULL,NULL,NULL,100,'active','available',NULL,0,NULL,0,'2026-06-09 01:46:37','2026-06-09 01:46:37','2026-06-09 01:46:37');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (2,'store_whatsapp','6285733920144','2026-06-09 00:53:48','2026-06-09 00:53:48'),(4,'store_email','admin@akartanikimia.com','2026-06-09 01:17:25','2026-06-09 01:17:25'),(5,'store_brand','Akar Tani Kimia','2026-06-09 01:17:25','2026-06-09 01:17:25'),(7,'unique_code_enabled','0','2026-06-09 07:49:21','2026-06-09 07:49:21');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shipment_origins`
--

DROP TABLE IF EXISTS `shipment_origins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shipment_origins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `contact_name` varchar(255) NOT NULL,
  `contact_phone` varchar(255) NOT NULL,
  `origin_id` bigint(20) unsigned DEFAULT NULL,
  `selected_courier` varchar(255) NOT NULL DEFAULT 'jnt',
  `province` varchar(255) NOT NULL,
  `city` varchar(255) NOT NULL,
  `district` varchar(255) NOT NULL,
  `subdistrict` varchar(255) NOT NULL,
  `postal_code` varchar(10) NOT NULL,
  `address_line` text NOT NULL,
  `pin_point` varchar(255) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shipment_origins`
--

LOCK TABLES `shipment_origins` WRITE;
/*!40000 ALTER TABLE `shipment_origins` DISABLE KEYS */;
INSERT INTO `shipment_origins` VALUES (1,'Gudang Utama Malang','Tim Gudang Larashop','0341123456',47246,'jnt','JAWA TIMUR','MALANG','PAKIS','SEKARPURO','65154','Jl. Raya Sekarpuro No.86, Sekaran, Kec. Pakis, Kabupaten Malang, Jawa Timur 65154','-7.968106, 112.676096','Dipakai sebagai titik asal default untuk estimasi ongkir customer.',1,1,'2026-05-16 00:01:22','2026-06-09 04:13:20');
/*!40000 ALTER TABLE `shipment_origins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_unique_codes`
--

DROP TABLE IF EXISTS `user_unique_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_unique_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `value` bigint(20) unsigned NOT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `type` enum('paid','used') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_unique_codes_user_id_type_index` (`user_id`,`type`),
  KEY `user_unique_codes_ref_id_type_index` (`ref_id`,`type`),
  CONSTRAINT `user_unique_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_unique_codes`
--

LOCK TABLES `user_unique_codes` WRITE;
/*!40000 ALTER TABLE `user_unique_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_unique_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) NOT NULL,
  `role` enum('admin','customer') NOT NULL DEFAULT 'customer',
  `admin_role` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','pending_verification') NOT NULL DEFAULT 'active',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_code_unique` (`code`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'ADM-001','Superadmin','superadmin','superadmin@akartanikimia.com','085733920144','admin','super_admin','active',NULL,'2026-06-08 23:02:39','$2y$12$9m0.RKlFiGs6knHjnmicvOSybkQrLT8CT5XThVFbZufjWc6r5eUly',NULL,'2026-05-14 16:43:16','2026-06-08 23:02:39');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wa_messages`
--

DROP TABLE IF EXISTS `wa_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wa_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(30) NOT NULL,
  `direction` varchar(3) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'text',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wa_messages_phone_id_index` (`phone`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wa_messages`
--

LOCK TABLES `wa_messages` WRITE;
/*!40000 ALTER TABLE `wa_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `wa_messages` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-10  0:40:54
