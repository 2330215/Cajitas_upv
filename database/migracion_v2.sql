-- =========================================================================
-- MIGRACIÓN v2 — Sistema de Asistencia ESP32
-- =========================================================================
-- Ejecutar UNA sola vez sobre la base de datos `esp32_attendance` ya creada
-- (phpMyAdmin -> esp32_attendance -> pestaña SQL -> pegar y continuar).
--
-- Agrega:
--   1. Tabla `dispositivos`      -> ID único para cada caja ESP32
--   2. Columna `dispositivo_id`  -> qué caja registró cada asistencia
--   3. Tabla `inscripciones`     -> asignación de clases a alumnos
--   4. Tabla `enrolamientos_rfid`-> alta de tarjetas desde la caja y desde la web
-- =========================================================================

USE `esp32_attendance`;

-- -------------------------------------------------------------------------
-- 1. DISPOSITIVOS (cada caja/ESP32 tiene un identificador único)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dispositivos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'Identificador único grabado en el firmware',
  `nombre` varchar(100) DEFAULT NULL,
  `aula` varchar(50) DEFAULT NULL,
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  `ultima_conexion` timestamp NULL DEFAULT NULL,
  `ultima_ip` varchar(45) DEFAULT NULL,
  `total_lecturas` int(11) NOT NULL DEFAULT 0,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 2. ASISTENCIAS: guardar desde qué caja se registró
-- -------------------------------------------------------------------------
ALTER TABLE `asistencias`
  ADD COLUMN `dispositivo_id` varchar(50) DEFAULT NULL AFTER `docente_autorizo_id`;

ALTER TABLE `asistencias`
  ADD KEY `dispositivo_id` (`dispositivo_id`);

ALTER TABLE `asistencias`
  ADD CONSTRAINT `asistencias_ibfk_4` FOREIGN KEY (`dispositivo_id`)
  REFERENCES `dispositivos` (`device_id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- -------------------------------------------------------------------------
-- 3. INSCRIPCIONES (asignación explícita de clases a alumnos)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inscripciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `clase_id` int(11) NOT NULL,
  `alumno_id` varchar(50) NOT NULL,
  `fecha_inscripcion` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `clase_alumno` (`clase_id`,`alumno_id`),
  KEY `alumno_id` (`alumno_id`),
  CONSTRAINT `inscripciones_ibfk_1` FOREIGN KEY (`clase_id`) REFERENCES `clases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `inscripciones_ibfk_2` FOREIGN KEY (`alumno_id`) REFERENCES `usuarios` (`matricula`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sembrar las inscripciones a partir de las coincidencias actuales
-- (carrera + grupo + semestre), para no perder la configuración previa.
INSERT IGNORE INTO `inscripciones` (`clase_id`, `alumno_id`)
SELECT c.id, u.matricula
FROM `clases` c
JOIN `usuarios` u
  ON u.carrera  = c.carrera
 AND u.grupo    = c.grupo
 AND u.semestre = c.semestre
WHERE u.rol = 'alumno';

-- -------------------------------------------------------------------------
-- 4. ENROLAMIENTOS RFID (alta de tarjeta desde la web o desde la caja)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enrolamientos_rfid` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `matricula` varchar(50) NOT NULL COMMENT 'Usuario al que se le asignará la tarjeta',
  `solicitado_por` varchar(50) DEFAULT NULL COMMENT 'Admin o docente que autorizó',
  `origen` enum('web','caja') NOT NULL DEFAULT 'web',
  `device_id` varchar(50) DEFAULT NULL COMMENT 'Caja que debe leer la tarjeta (NULL = cualquiera)',
  `uid` varchar(50) DEFAULT NULL COMMENT 'UID leído al completarse',
  `estado` enum('pendiente','completado','cancelado','expirado') NOT NULL DEFAULT 'pendiente',
  `mensaje` varchar(150) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `expira_en` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `matricula` (`matricula`),
  KEY `estado` (`estado`),
  CONSTRAINT `enrolamientos_ibfk_1` FOREIGN KEY (`matricula`) REFERENCES `usuarios` (`matricula`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- 5. Dispositivo de ejemplo (edítalo o bórralo desde el panel)
-- -------------------------------------------------------------------------
INSERT IGNORE INTO `dispositivos` (`device_id`, `nombre`, `aula`) VALUES
('ESP32-A214-01', 'Caja principal', 'A214');
