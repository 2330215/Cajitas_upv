-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 09-07-2026 a las 20:14:13
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `esp32_attendance`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `asistencias`
--

CREATE TABLE `asistencias` (
  `id` int(11) NOT NULL,
  `usuario_id` varchar(50) NOT NULL,
  `clase_id` int(11) DEFAULT NULL,
  `fecha` date NOT NULL,
  `hora` time NOT NULL,
  `tipo` enum('RFID_VERIFICADO','MATRICULA_VERIFICADA','AUTORIZADO_DOCENTE') NOT NULL,
  `estado` enum('presente','retardo','falta','justificado') DEFAULT 'presente',
  `docente_autorizo_id` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `asistencias`
--

INSERT INTO `asistencias` (`id`, `usuario_id`, `clase_id`, `fecha`, `hora`, `tipo`, `estado`, `docente_autorizo_id`) VALUES
(1, '2330018', NULL, '2026-07-09', '10:14:25', 'RFID_VERIFICADO', 'presente', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clases`
--

CREATE TABLE `clases` (
  `id` int(11) NOT NULL,
  `nombre_materia` varchar(100) NOT NULL,
  `docente_id` varchar(50) NOT NULL,
  `dia_semana` enum('lunes','martes','miercoles','jueves','viernes','sabado') NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `aula` varchar(50) DEFAULT NULL,
  `carrera` varchar(100) DEFAULT NULL,
  `grupo` varchar(20) DEFAULT NULL,
  `semestre` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `clases`
--

INSERT INTO `clases` (`id`, `nombre_materia`, `docente_id`, `dia_semana`, `hora_inicio`, `hora_fin`, `aula`, `carrera`, `grupo`, `semestre`) VALUES
(1, 'Sistemas Inteligentes', '2330319', 'lunes', '07:00:00', '07:54:00', 'A214', 'Ingeniería en Tecnologías de la Información', '1', 9),
(2, 'Sistemas Inteligentes', '2330319', 'martes', '07:00:00', '07:54:00', 'A214', 'Ingeniería en Tecnologías de la Información', '1', 9),
(3, 'Sistemas Inteligentes', '2330319', 'jueves', '07:00:00', '07:54:00', 'A214', 'Ingeniería en Tecnologías de la Información', '1', 9),
(4, 'Sistemas Inteligentes', '2330319', 'miercoles', '07:00:00', '07:54:00', 'A214', 'Ingeniería en Tecnologías de la Información', '1', 9),
(5, 'Sistemas Inteligentes', '2330319', 'viernes', '07:00:00', '08:49:00', 'A214', 'Ingeniería en Tecnologías de la Información', '1', 9);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `codigos_verificacion`
--

CREATE TABLE `codigos_verificacion` (
  `id` int(11) NOT NULL,
  `usuario_id` varchar(50) NOT NULL,
  `codigo` varchar(10) NOT NULL,
  `tipo_flujo` enum('RFID','MATRICULA') NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `expira_en` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `intentos` int(11) DEFAULT 0,
  `estado` enum('pendiente','verificado','expirado') DEFAULT 'pendiente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `codigos_verificacion`
--

INSERT INTO `codigos_verificacion` (`id`, `usuario_id`, `codigo`, `tipo_flujo`, `creado_en`, `expira_en`, `intentos`, `estado`) VALUES
(1, '2330018', '7508', 'MATRICULA', '2026-07-09 13:54:02', '2026-07-09 13:59:02', 0, 'pendiente'),
(2, '2330018', '7731', 'MATRICULA', '2026-07-09 13:57:43', '2026-07-09 14:02:43', 1, 'pendiente'),
(3, '2330018', '1484', 'MATRICULA', '2026-07-09 15:31:00', '2026-07-09 15:36:00', 0, 'pendiente'),
(4, '2330018', '2340', 'MATRICULA', '2026-07-09 15:52:20', '2026-07-09 15:57:20', 0, 'pendiente'),
(5, '2330018', '4071', 'RFID', '2026-07-09 15:53:11', '2026-07-09 15:58:11', 0, 'pendiente'),
(6, '2330018', '7594', 'RFID', '2026-07-09 15:56:57', '2026-07-09 16:01:57', 0, 'pendiente'),
(7, '2330018', '5408', 'RFID', '2026-07-09 16:01:52', '2026-07-09 16:06:52', 0, 'pendiente'),
(8, '2330018', '0543', 'RFID', '2026-07-09 16:02:45', '2026-07-09 16:07:45', 0, 'pendiente'),
(9, '2330018', '5220', 'RFID', '2026-07-09 16:02:55', '2026-07-09 16:07:55', 0, 'pendiente'),
(10, '2330018', '0798', 'RFID', '2026-07-09 16:04:01', '2026-07-09 16:09:01', 0, 'pendiente'),
(11, '2330018', '6487', 'RFID', '2026-07-09 16:05:21', '2026-07-09 16:10:21', 0, 'pendiente'),
(12, '2330018', '8402', 'RFID', '2026-07-09 16:06:41', '2026-07-09 16:11:41', 0, 'pendiente'),
(13, '2330018', '0945', 'RFID', '2026-07-09 16:13:48', '2026-07-09 16:18:48', 0, 'verificado'),
(14, '2330018', '2193', 'RFID', '2026-07-09 16:16:55', '2026-07-09 16:21:55', 0, 'pendiente');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `matricula` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `rol` enum('alumno','docente','administrativo') NOT NULL DEFAULT 'alumno',
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `id_telegram` varchar(50) DEFAULT NULL,
  `tarjeta_rfid` varchar(50) DEFAULT NULL,
  `contrasena` varchar(255) NOT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `carrera` varchar(100) DEFAULT NULL,
  `grupo` varchar(20) DEFAULT NULL,
  `semestre` int(11) DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`matricula`, `nombre`, `rol`, `estado`, `id_telegram`, `tarjeta_rfid`, `contrasena`, `correo`, `carrera`, `grupo`, `semestre`, `fecha_creacion`) VALUES
('2330018', 'Veyra Gutierrez', 'alumno', 'activo', '5658075717', 'B144B71D', '$2y$10$Dhs33ghW9XZqByn7WFpxlegVKDspjaCthFAiCfYJrROkFtA3aKwj6', '2330018@upv.edu.mx', 'Ingeniería en Tecnologías de la Información', '1', 9, '2026-07-09 13:45:13'),
('2330319', 'Ingridh Gracia', 'docente', 'activo', '8122182826', 'B144B71A', '$2y$10$CeQnY4Vd7beYIybmaftUHO5LUkeYFkoMhsq25DBbPZe84SJ6F1l6a', '2330319@upv.edu.mx', NULL, NULL, NULL, '2026-07-09 15:26:20'),
('ADMIN01', 'Administrador General', 'administrativo', 'activo', NULL, NULL, '$2y$10$fLN/3wXESQ8VtiK6sSmRU.ZtcAfQdCHjkpFcqbBcL6kLMpl.mTlOS', 'admin@sistema.com', NULL, NULL, NULL, '2026-07-09 11:06:21');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`),
  ADD KEY `clase_id` (`clase_id`),
  ADD KEY `docente_autorizo_id` (`docente_autorizo_id`);

--
-- Indices de la tabla `clases`
--
ALTER TABLE `clases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `docente_id` (`docente_id`);

--
-- Indices de la tabla `codigos_verificacion`
--
ALTER TABLE `codigos_verificacion`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`matricula`),
  ADD UNIQUE KEY `id_telegram` (`id_telegram`),
  ADD UNIQUE KEY `tarjeta_rfid` (`tarjeta_rfid`),
  ADD UNIQUE KEY `correo` (`correo`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `asistencias`
--
ALTER TABLE `asistencias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `clases`
--
ALTER TABLE `clases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `codigos_verificacion`
--
ALTER TABLE `codigos_verificacion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `asistencias`
--
ALTER TABLE `asistencias`
  ADD CONSTRAINT `asistencias_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`matricula`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `asistencias_ibfk_2` FOREIGN KEY (`clase_id`) REFERENCES `clases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `asistencias_ibfk_3` FOREIGN KEY (`docente_autorizo_id`) REFERENCES `usuarios` (`matricula`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Filtros para la tabla `clases`
--
ALTER TABLE `clases`
  ADD CONSTRAINT `clases_ibfk_1` FOREIGN KEY (`docente_id`) REFERENCES `usuarios` (`matricula`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `codigos_verificacion`
--
ALTER TABLE `codigos_verificacion`
  ADD CONSTRAINT `codigos_verificacion_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`matricula`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
