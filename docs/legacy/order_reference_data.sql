-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Хост: localhost:3306
-- Время создания: Окт 22 2026 г., 07:57
-- Версия сервера: 8.0.46-0ubuntu0.22.04.4
-- Версия PHP: 8.2.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `user_accasnew`
--

-- --------------------------------------------------------

--
-- Структура таблицы `order_arrivals`
--

CREATE TABLE `order_arrivals` (
  `id` bigint NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `order_arrivals`
--

INSERT INTO `order_arrivals` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'Консультация нал.', NULL, NULL),
(2, 'Консультация кредит', NULL, NULL),
(3, 'Заявка отказ', NULL, NULL),
(4, 'Заявка ФССП', NULL, NULL),
(5, 'Заявка решение', NULL, NULL),
(6, 'Продажа нал.', NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `order_drops`
--

CREATE TABLE `order_drops` (
  `id` bigint NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `showroom_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `order_drops`
--

INSERT INTO `order_drops` (`id`, `name`, `showroom_id`, `comment`, `created_at`, `updated_at`) VALUES
(1, 'Отзывы', NULL, NULL, NULL, NULL),
(2, 'Изменился способ оплаты ', NULL, NULL, NULL, NULL),
(3, 'Не отпустили с работы', NULL, NULL, NULL, NULL),
(4, 'Нашел дешевле', NULL, NULL, NULL, NULL),
(5, 'Не верит (Цена / Наличие)', NULL, NULL, NULL, NULL),
(6, 'Поедет к дилеру ', NULL, NULL, NULL, NULL),
(7, 'Уже купил', NULL, NULL, NULL, NULL),
(8, 'Купил автотеку ', NULL, NULL, NULL, NULL),
(9, 'Передумал покупать ', NULL, NULL, NULL, NULL),
(10, 'Семейные обстоятельства ', NULL, NULL, NULL, NULL),
(11, 'Нет денег', NULL, NULL, NULL, NULL),
(12, 'Не дозвон', NULL, NULL, NULL, NULL),
(13, 'Был у нас в салоне  либо есть в нашем салоне', NULL, NULL, NULL, NULL),
(14, 'Другое', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `order_histories`
--

CREATE TABLE `order_histories` (
  `id` int NOT NULL,
  `phone` int DEFAULT NULL,
  `datetime` datetime DEFAULT NULL,
  `operator_id` int DEFAULT NULL,
  `work_place_id` int DEFAULT NULL,
  `duration` int DEFAULT NULL,
  `link` varchar(150) DEFAULT NULL,
  `status_id` int DEFAULT NULL,
  `diversion` varchar(80) DEFAULT NULL,
  `telnum` varchar(80) DEFAULT NULL,
  `ext` int DEFAULT NULL,
  `type` enum('in','out') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `order_sources`
--

CREATE TABLE `order_sources` (
  `id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `order_sources`
--

INSERT INTO `order_sources` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'Mango', NULL, NULL),
(2, 'Lead', NULL, NULL),
(3, 'Вручную', NULL, NULL),
(4, 'Другое', NULL, NULL),
(5, 'Автоимпорт', NULL, NULL),
(6, 'Quiz', NULL, NULL),
(7, 'Victory Crm', NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `order_statuses`
--

CREATE TABLE `order_statuses` (
  `id` bigint NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` int DEFAULT NULL,
  `order` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `order_statuses`
--

INSERT INTO `order_statuses` (`id`, `name`, `comment`, `active`, `order`, `created_at`, `updated_at`) VALUES
(1, 'Новая', NULL, 1, 1, NULL, NULL),
(2, 'В работе', NULL, 1, 2, NULL, NULL),
(3, 'Не отвечает', NULL, 1, 3, NULL, NULL),
(4, 'Одобрить', NULL, 1, 4, NULL, NULL),
(5, 'Приедет', NULL, 1, 6, NULL, NULL),
(6, 'Приехал', NULL, 1, 7, NULL, NULL),
(7, 'Корзина', NULL, 1, 8, NULL, NULL),
(8, 'Повтор', NULL, 1, 9, NULL, NULL),
(12, 'Одобренный', NULL, 1, 5, NULL, NULL),
(15, 'Не ликвид', NULL, 1, 11, NULL, NULL),
(16, 'Повтор ДИ', NULL, 1, 12, NULL, NULL),
(17, 'ТО', NULL, 1, 10, NULL, NULL),
(18, 'Перезвон', NULL, 1, 10, NULL, NULL),
(1000, 'Передано', NULL, NULL, 13, NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `order_trashes`
--

CREATE TABLE `order_trashes` (
  `id` bigint NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `showroom_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `order_trashes`
--

INSERT INTO `order_trashes` (`id`, `name`, `showroom_id`, `comment`, `created_at`, `updated_at`) VALUES
(15, 'Не подавал', NULL, NULL, NULL, NULL),
(16, 'Наличка', NULL, NULL, NULL, NULL),
(17, 'Отказался', NULL, NULL, NULL, NULL),
(18, 'Долги ФССП', NULL, NULL, NULL, NULL),
(19, 'Не поедет в Москву', '1,2', NULL, NULL, NULL),
(20, 'Отзывы\r\n', NULL, NULL, NULL, NULL),
(21, 'Нет гражданства', NULL, NULL, NULL, NULL),
(22, 'Реклама', NULL, NULL, NULL, NULL),
(23, 'Не поедет в Волгоград', '8', NULL, NULL, NULL),
(24, 'Повтор', NULL, NULL, NULL, NULL),
(25, 'Не дозвон', NULL, NULL, NULL, NULL),
(26, 'Другое', NULL, NULL, NULL, NULL),
(27, 'Некорректный регион', NULL, NULL, NULL, NULL),
(28, 'Не поедет в Нижний Новгород', '11', NULL, NULL, NULL),
(29, 'Некорректные данные', NULL, NULL, NULL, NULL),
(30, 'Далеко', NULL, NULL, NULL, NULL),
(31, 'Отложил покупку', NULL, NULL, NULL, NULL),
(32, 'Не поедет в Ростов', '2,4,10,14', NULL, NULL, NULL),
(33, 'Уже купил', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `order_types`
--

CREATE TABLE `order_types` (
  `id` bigint UNSIGNED NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `order_types`
--

INSERT INTO `order_types` (`id`, `name`, `comment`, `created_at`, `updated_at`) VALUES
(1, 'Звонок', NULL, NULL, NULL),
(2, 'Кредит', NULL, NULL, NULL),
(3, 'Экпресс кредит', NULL, NULL, NULL),
(4, 'СП кредит', NULL, NULL, NULL),
(5, 'Обратный звонок', NULL, NULL, NULL),
(6, 'Trade-In', NULL, NULL, NULL),
(7, 'Auto.ru', NULL, NULL, NULL),
(8, 'Подарок', NULL, NULL, NULL),
(9, 'Кредит такси', NULL, NULL, NULL),
(10, 'Обратная связь', NULL, NULL, NULL),
(11, 'Зарезервирование', NULL, NULL, NULL),
(12, 'WhatsApp', NULL, NULL, NULL),
(13, 'SEO', NULL, NULL, NULL),
(14, 'Реклама', NULL, NULL, NULL),
(15, 'SEO/Реклама', NULL, NULL, NULL),
(16, 'Victory', NULL, NULL, NULL),
(17, 'Авито', NULL, NULL, NULL),
(18, 'Другой салон', NULL, NULL, NULL),
(19, 'VDL 2', NULL, NULL, NULL),
(20, 'VDL 3', NULL, NULL, NULL),
(21, 'PV', NULL, NULL, NULL),
(22, 'PV AO', NULL, NULL, NULL),
(23, 'Заявка VK', NULL, NULL, NULL),
(24, 'Заявка Direct', NULL, NULL, NULL);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `order_arrivals`
--
ALTER TABLE `order_arrivals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id` (`id`);

--
-- Индексы таблицы `order_drops`
--
ALTER TABLE `order_drops`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id` (`id`);

--
-- Индексы таблицы `order_histories`
--
ALTER TABLE `order_histories`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `order_sources`
--
ALTER TABLE `order_sources`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `order_statuses`
--
ALTER TABLE `order_statuses`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `order_trashes`
--
ALTER TABLE `order_trashes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id` (`id`);

--
-- Индексы таблицы `order_types`
--
ALTER TABLE `order_types`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `order_arrivals`
--
ALTER TABLE `order_arrivals`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT для таблицы `order_drops`
--
ALTER TABLE `order_drops`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT для таблицы `order_histories`
--
ALTER TABLE `order_histories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `order_sources`
--
ALTER TABLE `order_sources`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `order_statuses`
--
ALTER TABLE `order_statuses`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1002;

--
-- AUTO_INCREMENT для таблицы `order_trashes`
--
ALTER TABLE `order_trashes`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT для таблицы `order_types`
--
ALTER TABLE `order_types`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
