# Sistema de Asistencia IoT con ESP32

Control de asistencia escolar con cajas ESP32 (lector RFID + teclado matricial + LCD),
verificación en dos pasos por Telegram y un panel web en PHP/MySQL.

---

## 1. Estructura del proyecto

| Ruta | Qué es |
|---|---|
| `index.php` | Panel principal (cambia según el rol: alumno, docente, administrativo) |
| `login.php` / `logout.php` | Acceso al sistema |
| `historial.php` | Historial filtrable + exportación a CSV |
| `mis_clases.php` | **Alumno**: asistencia materia por materia, faltas y porcentajes |
| `pase_lista.php` | **Docente**: lista de la sesión, con corrección manual |
| `usuarios.php` | **Admin**: alta, edición, baja y registro de tarjetas RFID |
| `clases.php` | **Admin**: alta y edición de materias y horarios |
| `inscripciones.php` | **Admin**: asignación de clases a alumnos |
| `dispositivos.php` | **Admin**: cajas ESP32 y su ID único |
| `api/attendance.php` | Recibe las lecturas de la caja (RFID / teclado / firma docente) |
| `api/verify_code.php` | Valida el código de 4 dígitos de Telegram |
| `api/rfid_enroll.php` | Alta de tarjetas desde la web y desde la caja |
| `api/attendance_helper.php` | Horarios, estados, dispositivos y respuestas JSON |
| `api/rfid_helper.php` | Lógica de enrolamiento de tarjetas |
| `includes/config.php` | Zona horaria, token de Telegram, tolerancias |
| `includes/conexion.php` | Conexión PDO (alinea la hora de MySQL con la de PHP) |
| `database/esp32_attendance.sql` | Base de datos inicial |
| `database/migracion_v2.sql` | **Migración obligatoria** con las tablas nuevas |
| `esp32_code/esp32_code.ino` | Firmware de la caja |

> La carpeta `public/`, `server.js` y `data/` son el prototipo anterior en Node.js.
> El sistema actual es el de PHP; puedes conservarlos como referencia.

---

## 2. Instalación

### 2.1 Base de datos

1. Abre **phpMyAdmin**.
2. Importa `database/esp32_attendance.sql` (si aún no tienes la base creada).
3. Abre la base `esp32_attendance` → pestaña **SQL** → pega y ejecuta el contenido de
   **`database/migracion_v2.sql`**.

La migración agrega:

- `dispositivos` — el ID único de cada caja ESP32.
- `asistencias.dispositivo_id` — desde qué caja se registró cada asistencia.
- `inscripciones` — qué alumno pertenece a qué clase.
- `enrolamientos_rfid` — solicitudes de alta de tarjeta.

Además rellena las inscripciones automáticamente con las coincidencias actuales de
carrera + grupo + semestre, para no perder la configuración previa.

### 2.2 Configuración del servidor

Edita `includes/config.php`:

```php
define('APP_TIMEZONE', 'America/Mexico_City'); // Zona horaria de la escuela
define('TELEGRAM_BOT_TOKEN', 'tu_token_de_bot');
define('TOLERANCIA_RETARDO', 10);  // minutos → después de esto, retardo
define('TOLERANCIA_FALTA', 20);    // minutos → después de esto, falta
```

Y `includes/conexion.php` con los datos de MySQL (usuario, contraseña, host).

### 2.3 Firmware del ESP32

Abre `esp32_code/esp32_code.ino` en el Arduino IDE y ajusta el bloque de configuración:

```cpp
const char* ssid     = "TU_SSID_WIFI";
const char* password = "TU_PASSWORD_WIFI";

// IP del servidor + carpeta del proyecto + /api
const String serverBaseUrl = "http://192.168.100.77/esp32-attendance-system/api";

// ¡IMPORTANTE! Un ID DISTINTO para cada caja
const String DEVICE_ID = "ESP32-A214-01";
```

Sube el programa a cada ESP32 cambiando `DEVICE_ID` en cada uno.
No hace falta darlas de alta a mano: la caja aparece sola en **Cajas ESP32**
la primera vez que envía una lectura, y ahí le pones nombre y aula.

---

## 3. Cómo se usa la caja

| Acción | Qué hacer |
|---|---|
| Pasar lista con tarjeta | Acercar la tarjeta al lector |
| Pasar lista con matrícula | Teclear la matrícula y pulsar `#` |
| Escribir el código de Telegram | Teclear los 4 dígitos y pulsar `#` |
| Cancelar lo que sea | Pulsar `*` |
| **Dar de alta una tarjeta** | Pulsar `*` en reposo → pasar la tarjeta del docente → teclear la matrícula del alumno + `#` → acercar la tarjeta nueva |

### Los tres flujos de asistencia

1. **Tarjeta + Telegram**: se lee la tarjeta, llega un código de 4 dígitos al Telegram
   del alumno y lo escribe en el teclado.
2. **Matrícula + Telegram**: igual, pero tecleando la matrícula en vez de la tarjeta.
3. **Firma del docente**: si el alumno no tiene Telegram configurado, la caja pide la
   tarjeta de un docente o administrativo para autorizar el registro.

---

## 4. Registro de tarjetas RFID

Se puede hacer de dos maneras:

**Desde la página** (Usuarios → botón de la tarjeta 🪪 en el alumno):
se abre una ventana, se elige la caja, se pulsa "Iniciar lectura" y se acerca la tarjeta.
La caja consulta al servidor cada 4 segundos, así que reacciona sola.
La ventana avisa cuando la tarjeta queda guardada.

**Desde la caja**: pulsar `*` en reposo y seguir los pasos de la tabla de arriba.
Siempre pide la tarjeta de un docente o administrativo antes de continuar, para que
nadie pueda asignarse una tarjeta por su cuenta.

En ambos casos, si la tarjeta ya pertenece a otra persona el sistema lo avisa y no
la reasigna.

---

## 5. Clases, inscripciones y pase de lista

1. **Clases** (`clases.php`): das de alta la materia, el docente, el día y el horario.
   El sistema avisa si el docente ya tiene otra clase encimada a esa hora.
2. **Inscripciones** (`inscripciones.php`): eliges la clase y agregas alumnos, ya sea
   uno por uno (con buscador) o en bloque con el botón
   *"Inscribir a todo el grupo configurado"*, que usa la carrera, grupo y semestre de la clase.
3. **Pase de lista** (`pase_lista.php`): el docente elige la clase y la fecha, y ve a
   todos sus inscritos con su estado. Puede marcar manualmente
   presente / retardo / falta / justificado, o borrar un registro equivocado.

Cuando alguien pasa lista en la caja, el sistema busca su clase activa **primero en las
inscripciones** y, si no encuentra nada, cae al método anterior por carrera/grupo/semestre.

### Qué se puede editar y desde dónde

| Qué | Dónde | Quién |
|---|---|---|
| Usuarios (datos, rol, estado, contraseña, tarjeta) | `usuarios.php` | Administrativo |
| Clases y horarios | `clases.php` | Administrativo |
| Inscripciones (alta y baja de alumnos) | `inscripciones.php` | Administrativo |
| Cajas ESP32 (nombre, aula, activa/inactiva) | `dispositivos.php` | Administrativo |
| Asistencias de sus propias clases | `pase_lista.php` | Docente |
| **Cualquier asistencia** (estado y materia) o borrarla | `historial.php` | Administrativo |

En el historial, el administrativo tiene una columna **Acciones** con el lápiz para
corregir el registro (estado y materia) y el bote para eliminarlo. Los filtros que
tengas puestos se conservan al editar.

---

## 6. Cálculo de asistencia

- **Presente**: llegó dentro de los primeros `TOLERANCIA_RETARDO` minutos.
- **Retardo**: llegó entre `TOLERANCIA_RETARDO` y `TOLERANCIA_FALTA` minutos.
- **Falta**: llegó después de `TOLERANCIA_FALTA` minutos.
- **Justificado**: solo lo marca el docente desde el pase de lista.

En las vistas del alumno, el porcentaje se calcula sobre las **sesiones realmente
impartidas** (los días en que se pasó lista en esa materia), así que los días en que el
alumno no apareció cuentan como falta aunque no exista un registro suyo.

Un alumno no puede registrar dos veces la asistencia de la misma clase el mismo día:
la caja responde "Ya registrado".

---

## 7. Zona horaria

Antes, PHP usaba la hora de México pero MySQL guardaba con `CURDATE()`/`CURTIME()`,
que toman la hora del servidor (UTC en AWS). Las asistencias salían con horas de
diferencia y el cálculo de retardos fallaba.

Ahora:

- La zona se define en un solo lugar: `APP_TIMEZONE` en `includes/config.php`.
- `includes/conexion.php` le manda a MySQL el mismo desplazamiento al conectarse.
- Las asistencias se insertan con la fecha y hora calculadas en PHP, no en MySQL.

Si el proyecto se mueve a otro estado o país, basta con cambiar `APP_TIMEZONE`.

---

## 8. Notas del despliegue en AWS

```bash
ssh -i "ruta/a/KeyPair.pem" ubuntu@44.211.83.193
```

> ⚠️ El archivo `KeyPair.pem` es una llave privada: no lo subas a ningún repositorio
> ni lo compartas. El `.gitignore` del proyecto ya lo excluye.

Si el navegador bloquea la página por no usar HTTPS, estas banderas de Chrome ayudan
durante las pruebas locales:

- `chrome://flags/#unsafely-treat-insecure-origin-as-secure`
- `chrome://flags/#enable-webrtc-hide-local-ips-with-mdns`
