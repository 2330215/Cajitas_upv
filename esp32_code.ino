#include <SPI.h>
#include <MFRC522.h>
#include <Keypad.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <WiFi.h>
#include <HTTPClient.h>

// =========================================================================
// --- CONFIGURACIÓN DE RED Y SERVIDOR ---
// =========================================================================
const char* ssid = "TU_SSID_WIFI";          // Nombre de tu red WiFi
const char* password = "TU_PASSWORD_WIFI";  // Contraseña de tu red WiFi

// URL base del servidor (IP del servidor + carpeta del proyecto + /api)
//
// Servidor en AWS (el que está en línea, versión nueva):
//     "http://44.211.83.193/si_v3/api"
// Versión anterior, por si hay que volver a ella:
//     "http://44.211.83.193/si_v2/api"
// En tu computadora con XAMPP (misma red WiFi que la caja):
//     "http://192.168.1.15/esp32-attendance-system/api"
const String serverBaseUrl = "http://44.211.83.193/si_v3/api";

// =========================================================================
// --- IDENTIFICADOR ÚNICO DE ESTA CAJA ---
// =========================================================================
// IMPORTANTE: cada ESP32 debe tener un DEVICE_ID DISTINTO.
// El servidor lo da de alta solo en la tabla `dispositivos` la primera vez
// que se conecta, y a partir de ahí cada asistencia queda ligada a la caja
// que la registró. Nómbralo con el aula para identificarlo fácil.
const String DEVICE_ID = "ESP32-A214-01";

// =========================================================================
// --- CONFIGURACIÓN DEL RFID ---
// =========================================================================
#define SS_PIN  5
#define RST_PIN 4
MFRC522 rfid(SS_PIN, RST_PIN);

// =========================================================================
// --- CONFIGURACIÓN DE LA LCD I2C ---
// =========================================================================
LiquidCrystal_I2C lcd(0x27, 16, 2);

// =========================================================================
// --- CONFIGURACIÓN DEL TECLADO MATRICIAL ---
// =========================================================================
const byte FILAS = 4;
const byte COLUMNAS = 3;

char matriz[FILAS][COLUMNAS] = {
  {'1','2','3'},
  {'4','5','6'},
  {'7','8','9'},
  {'*','0','#'}
};

byte pinesFilas[FILAS] = {32, 33, 26, 27};
byte pinesColumnas[COLUMNAS] = {14, 12, 13};

Keypad teclado = Keypad(makeKeymap(matriz), pinesFilas, pinesColumnas, FILAS, COLUMNAS);

// =========================================================================
// --- ESTADOS DE LA MÁQUINA DE ESTADO ---
// =========================================================================
enum EstadoSistema {
  ESTADO_IDLE,                 // Esperando tarjeta o matrícula
  ESTADO_WAITING_CODE,         // Esperando código de Telegram (2 pasos)
  ESTADO_WAITING_BYPASS,       // Esperando tarjeta del docente (Flujo C)
  ESTADO_ENROL_WAIT_DOCENTE,   // Alta de tarjeta: esperando tarjeta del docente
  ESTADO_ENROL_WAIT_MATRICULA, // Alta de tarjeta: tecleando la matrícula
  ESTADO_ENROL_WAIT_TARJETA    // Alta de tarjeta: esperando la tarjeta nueva
};

// --- VARIABLES GLOBALES ---
EstadoSistema estadoActual = ESTADO_IDLE;
String entradaTeclado = "";
String matriculaPendiente = "";
String nombrePendiente = "";
String tarjetaDocenteEnrol = "";
int enrolIdPendiente = 0;

unsigned long tiempoInicioEspera = 0;
const unsigned long TIMEOUT_ESPERA = 45000;   // 45 s para completar un flujo

unsigned long ultimoSondeo = 0;
unsigned long intervaloSondeo = 4000;              // Cada 4 s se pregunta al servidor
const unsigned long SONDEO_NORMAL = 4000;          // si hay un alta de tarjeta pedida
const unsigned long SONDEO_TRAS_FALLO = 30000;     // desde la página web. Si el servidor
                                                   // no responde, se espera más para no
                                                   // dejar el teclado trabado.

// =========================================================================
// --- PROTOTIPOS ---
// Se declaran a mano porque el generador automático del Arduino IDE a veces
// no maneja bien los parámetros por referencia (String &respuesta).
// =========================================================================
void procesarTecla(char tecla);
void procesarTarjeta(String uid);
void iniciarAsistencia(String id, String tipo);
void verificarCodigo(String matricula, String codigo);
void autorizarBypass(String tarjetaDocente, String matriculaAlumno);
void iniciarAltaTarjeta();
void solicitarAltaDesdeCaja(String tarjetaDocente, String matricula);
void consultarEnrolamientoPendiente();
void asignarTarjeta(String uid);
void mostrarMensaje(String linea1, String linea2);
void mostrarPantallaEspera();
void mostrarPantallaCodigo();
void mostrarPantallaBypass();
void mostrarPantallaEnrolMatricula();
void mostrarPantallaEnrolTarjeta();
void cancelarFlujo();
bool hayWiFi();
bool enviarPost(String ruta, String payload, String &respuesta);
bool enviarGet(String url, String &respuesta, int timeoutMs);
void conectarWiFi();
void verificarConexionWiFi();
String extraerValorJSON(String json, String llave);
int extraerEnteroJSON(String json, String llave);
bool extraerBooleanoJSON(String json, String llave);

void setup() {
  Serial.begin(115200);

  SPI.begin();
  rfid.PCD_Init();

  lcd.init();
  lcd.backlight();

  mostrarMensaje("Caja Asistencia", DEVICE_ID);
  delay(1500);

  conectarWiFi();
  mostrarPantallaEspera();
}

void loop() {
  verificarConexionWiFi();

  // Timeout de los flujos que no son reposo
  if (estadoActual != ESTADO_IDLE) {
    if (millis() - tiempoInicioEspera > TIMEOUT_ESPERA) {
      Serial.println("Tiempo de espera agotado.");
      mostrarMensaje("TIEMPO AGOTADO", "Intenta de nuevo");
      delay(2000);
      cancelarFlujo();
      return;
    }
  } else {
    // En reposo: preguntar si el panel web pidió registrar una tarjeta
    if (millis() - ultimoSondeo > intervaloSondeo) {
      ultimoSondeo = millis();
      consultarEnrolamientoPendiente();
    }
  }

  // 1. TECLADO
  char tecla = teclado.getKey();
  if (tecla) {
    procesarTecla(tecla);
  }

  // 2. TARJETA RFID
  if (rfid.PICC_IsNewCardPresent() && rfid.PICC_ReadCardSerial()) {
    String uidTarjeta = "";
    for (byte i = 0; i < rfid.uid.size; i++) {
      uidTarjeta += String(rfid.uid.uidByte[i] < 0x10 ? "0" : "");
      uidTarjeta += String(rfid.uid.uidByte[i], HEX);
    }
    uidTarjeta.toUpperCase();

    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();

    procesarTarjeta(uidTarjeta);
  }
}

// =========================================================================
// --- PROCESAMIENTO DE ENTRADAS ---
// =========================================================================

void procesarTecla(char tecla) {
  Serial.print("Tecla: ");
  Serial.println(tecla);

  if (tecla == '*') {
    // En reposo y sin nada escrito, '*' abre el alta de tarjetas.
    // En cualquier otro caso, cancela lo que se esté haciendo.
    if (estadoActual == ESTADO_IDLE && entradaTeclado.length() == 0) {
      iniciarAltaTarjeta();
    } else {
      mostrarMensaje("CANCELADO", "");
      delay(1000);
      cancelarFlujo();
    }
    return;
  }

  switch (estadoActual) {

    case ESTADO_IDLE:
      // Escribir la matrícula para pasar lista
      if (tecla == '#') {
        if (entradaTeclado.length() > 0) {
          iniciarAsistencia(entradaTeclado, "TECLADO");
          entradaTeclado = "";
        } else {
          mostrarMensaje("Escribe tu", "matricula antes");
          delay(1500);
          mostrarPantallaEspera();
        }
      } else if (entradaTeclado.length() < 10) {
        entradaTeclado += tecla;
        lcd.setCursor(0, 1);
        lcd.print("Mat: " + entradaTeclado + "           ");
      }
      break;

    case ESTADO_WAITING_CODE:
      // Escribir el código de 4 dígitos que llegó por Telegram
      if (tecla == '#') {
        if (entradaTeclado.length() == 4) {
          verificarCodigo(matriculaPendiente, entradaTeclado);
          entradaTeclado = "";
        } else {
          mostrarMensaje("Son 4 digitos", "Vuelve a escribir");
          delay(1500);
          mostrarPantallaCodigo();
        }
      } else if (entradaTeclado.length() < 4) {
        entradaTeclado += tecla;
        lcd.setCursor(0, 1);
        lcd.print("Cod: " + entradaTeclado + "        ");
      }
      break;

    case ESTADO_ENROL_WAIT_MATRICULA:
      // Escribir la matrícula del dueño de la tarjeta nueva
      if (tecla == '#') {
        if (entradaTeclado.length() > 0) {
          solicitarAltaDesdeCaja(tarjetaDocenteEnrol, entradaTeclado);
          entradaTeclado = "";
        } else {
          mostrarMensaje("Escribe la", "matricula");
          delay(1500);
          mostrarPantallaEnrolMatricula();
        }
      } else if (entradaTeclado.length() < 10) {
        entradaTeclado += tecla;
        lcd.setCursor(0, 1);
        lcd.print("Mat: " + entradaTeclado + "           ");
      }
      break;

    default:
      // En los estados que esperan una tarjeta, el teclado no hace nada
      break;
  }
}

void procesarTarjeta(String uid) {
  switch (estadoActual) {

    case ESTADO_IDLE:
      iniciarAsistencia(uid, "RFID");
      break;

    case ESTADO_WAITING_BYPASS:
      autorizarBypass(uid, matriculaPendiente);
      break;

    case ESTADO_ENROL_WAIT_DOCENTE:
      // La tarjeta del docente autoriza el alta; ahora pedimos la matrícula
      tarjetaDocenteEnrol = uid;
      estadoActual = ESTADO_ENROL_WAIT_MATRICULA;
      entradaTeclado = "";
      tiempoInicioEspera = millis();
      mostrarPantallaEnrolMatricula();
      break;

    case ESTADO_ENROL_WAIT_TARJETA:
      asignarTarjeta(uid);
      break;

    default:
      // En ESTADO_WAITING_CODE la tarjeta se ignora
      break;
  }
}

// =========================================================================
// --- FLUJO DE ASISTENCIA ---
// =========================================================================

// Paso 1: iniciar el registro (RFID o teclado)
void iniciarAsistencia(String id, String tipo) {
  mostrarMensaje("Verificando...", "");

  if (!hayWiFi()) return;

  String payload = "{\"id\":\"" + id + "\",\"type\":\"" + tipo +
                   "\",\"device_id\":\"" + DEVICE_ID + "\"}";
  String response = "";

  if (!enviarPost("/attendance.php", payload, response)) {
    mostrarMensaje("SIN SERVIDOR", "Revisa la red");
    delay(2500);
    cancelarFlujo();
    return;
  }

  bool success   = extraerBooleanoJSON(response, "success");
  String status  = extraerValorJSON(response, "status");
  String matricula = extraerValorJSON(response, "matricula");
  String lcdMsg  = extraerValorJSON(response, "lcd");

  if (!success) {
    mostrarMensaje("NO SE PUDO", lcdMsg);
    delay(2500);
    cancelarFlujo();
    return;
  }

  matriculaPendiente = matricula;
  nombrePendiente = extraerValorJSON(response, "userName");
  tiempoInicioEspera = millis();
  entradaTeclado = "";

  if (status == "code_sent") {
    // Flujo A / B: el código viajó por Telegram
    estadoActual = ESTADO_WAITING_CODE;
    mostrarPantallaCodigo();
  } else if (status == "require_bypass") {
    // Flujo C: sin Telegram, hace falta la tarjeta del docente
    estadoActual = ESTADO_WAITING_BYPASS;
    mostrarPantallaBypass();
  } else if (status == "duplicado") {
    mostrarMensaje("YA REGISTRADO", nombrePendiente);
    delay(2500);
    cancelarFlujo();
  } else {
    mostrarMensaje("LISTO", lcdMsg);
    delay(2000);
    cancelarFlujo();
  }
}

// Paso 2: validar el código de Telegram
void verificarCodigo(String matricula, String codigo) {
  mostrarMensaje("Validando...", "");

  if (!hayWiFi()) return;

  String payload = "{\"matricula\":\"" + matricula + "\",\"code\":\"" + codigo +
                   "\",\"device_id\":\"" + DEVICE_ID + "\"}";
  String response = "";

  if (!enviarPost("/verify_code.php", payload, response)) {
    mostrarMensaje("SIN SERVIDOR", "Revisa la red");
    delay(2500);
    cancelarFlujo();
    return;
  }

  bool success  = extraerBooleanoJSON(response, "success");
  String lcdMsg = extraerValorJSON(response, "lcd");

  if (success) {
    String userName = extraerValorJSON(response, "userName");
    mostrarMensaje(lcdMsg, userName);
    delay(2500);
    cancelarFlujo();
    return;
  }

  mostrarMensaje("CODIGO MAL", lcdMsg);
  delay(2500);

  // Si el código venció o se agotaron los intentos, se reinicia el flujo
  if (lcdMsg.indexOf("vencido") >= 0 || lcdMsg.indexOf("reinic") >= 0) {
    cancelarFlujo();
  } else {
    entradaTeclado = "";
    tiempoInicioEspera = millis();
    mostrarPantallaCodigo();
  }
}

// Paso 3: firma del docente (bypass)
void autorizarBypass(String tarjetaDocente, String matriculaAlumno) {
  mostrarMensaje("Validando prof", "");

  if (!hayWiFi()) return;

  String payload = "{\"id\":\"" + tarjetaDocente + "\",\"type\":\"RFID_BYPASS\",\"student_matricula\":\"" +
                   matriculaAlumno + "\",\"device_id\":\"" + DEVICE_ID + "\"}";
  String response = "";

  if (!enviarPost("/attendance.php", payload, response)) {
    mostrarMensaje("SIN SERVIDOR", "Revisa la red");
    delay(2500);
    cancelarFlujo();
    return;
  }

  bool success  = extraerBooleanoJSON(response, "success");
  String lcdMsg = extraerValorJSON(response, "lcd");

  if (success) {
    String studentName = extraerValorJSON(response, "userName");
    mostrarMensaje("AUTORIZADO", studentName);
  } else {
    mostrarMensaje("NO AUTORIZADO", lcdMsg);
  }

  delay(2500);
  cancelarFlujo();
}

// =========================================================================
// --- ALTA DE TARJETAS RFID ---
// =========================================================================

// Alta iniciada DESDE LA CAJA: se pulsa '*' en reposo
void iniciarAltaTarjeta() {
  estadoActual = ESTADO_ENROL_WAIT_DOCENTE;
  entradaTeclado = "";
  tarjetaDocenteEnrol = "";
  enrolIdPendiente = 0;
  tiempoInicioEspera = millis();
  mostrarMensaje("ALTA DE TARJETA", "Tarjeta docente");
}

// La caja avisa al servidor a quién se le va a asignar la tarjeta
void solicitarAltaDesdeCaja(String tarjetaDocente, String matricula) {
  mostrarMensaje("Validando...", "");

  if (!hayWiFi()) return;

  String payload = "{\"accion\":\"caja_start\",\"device_id\":\"" + DEVICE_ID +
                   "\",\"tarjeta_docente\":\"" + tarjetaDocente +
                   "\",\"matricula\":\"" + matricula + "\"}";
  String response = "";

  if (!enviarPost("/rfid_enroll.php", payload, response)) {
    mostrarMensaje("SIN SERVIDOR", "Revisa la red");
    delay(2500);
    cancelarFlujo();
    return;
  }

  bool success  = extraerBooleanoJSON(response, "success");
  String lcdMsg = extraerValorJSON(response, "lcd");

  if (!success) {
    mostrarMensaje("NO SE PUDO", lcdMsg);
    delay(2500);
    cancelarFlujo();
    return;
  }

  enrolIdPendiente = extraerEnteroJSON(response, "enrol_id");
  nombrePendiente = extraerValorJSON(response, "userName");
  matriculaPendiente = matricula;
  estadoActual = ESTADO_ENROL_WAIT_TARJETA;
  tiempoInicioEspera = millis();
  mostrarPantallaEnrolTarjeta();
}

// Alta pedida DESDE LA PÁGINA WEB: la caja pregunta cada pocos segundos
void consultarEnrolamientoPendiente() {
  if (WiFi.status() != WL_CONNECTED) return;

  String url = serverBaseUrl + "/rfid_enroll.php?accion=pending&device_id=" + DEVICE_ID;
  String response = "";

  // Timeout corto: este sondeo ocurre en reposo y no debe trabar el teclado
  if (!enviarGet(url, response, 2500)) {
    intervaloSondeo = SONDEO_TRAS_FALLO;  // el servidor no responde: espaciar
    return;
  }

  intervaloSondeo = SONDEO_NORMAL;

  if (!extraerBooleanoJSON(response, "pendiente")) return;

  enrolIdPendiente = extraerEnteroJSON(response, "enrol_id");
  matriculaPendiente = extraerValorJSON(response, "matricula");
  nombrePendiente = extraerValorJSON(response, "userName");

  estadoActual = ESTADO_ENROL_WAIT_TARJETA;
  tiempoInicioEspera = millis();
  mostrarPantallaEnrolTarjeta();
}

// Manda al servidor el UID de la tarjeta nueva
void asignarTarjeta(String uid) {
  mostrarMensaje("Guardando...", "");

  if (!hayWiFi()) return;

  String payload = "{\"accion\":\"assign\",\"device_id\":\"" + DEVICE_ID +
                   "\",\"uid\":\"" + uid +
                   "\",\"enrol_id\":" + String(enrolIdPendiente) + "}";
  String response = "";

  if (!enviarPost("/rfid_enroll.php", payload, response)) {
    mostrarMensaje("SIN SERVIDOR", "Revisa la red");
    delay(2500);
    cancelarFlujo();
    return;
  }

  bool success  = extraerBooleanoJSON(response, "success");
  String lcdMsg = extraerValorJSON(response, "lcd");

  if (success) {
    mostrarMensaje("TARJETA LISTA", nombrePendiente);
  } else {
    mostrarMensaje("NO SE GUARDO", lcdMsg);
  }

  delay(2500);
  cancelarFlujo();
}

// =========================================================================
// --- PANTALLAS ---
// =========================================================================

void mostrarMensaje(String linea1, String linea2) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(linea1.substring(0, 16));
  lcd.setCursor(0, 1);
  lcd.print(linea2.substring(0, 16));
}

void mostrarPantallaEspera() {
  mostrarMensaje(" PASE DE LISTA", "Tarjeta o # mat");
}

void mostrarPantallaCodigo() {
  mostrarMensaje("Cod. de Telegram", "Cod: _ _ _ _");
}

void mostrarPantallaBypass() {
  mostrarMensaje("Falta Telegram", "Tarjeta docente");
}

void mostrarPantallaEnrolMatricula() {
  mostrarMensaje("Matricula dueno:", "Mat: ");
}

void mostrarPantallaEnrolTarjeta() {
  mostrarMensaje("Tarjeta nueva de", nombrePendiente);
}

void cancelarFlujo() {
  estadoActual = ESTADO_IDLE;
  entradaTeclado = "";
  matriculaPendiente = "";
  nombrePendiente = "";
  tarjetaDocenteEnrol = "";
  enrolIdPendiente = 0;
  ultimoSondeo = millis();
  mostrarPantallaEspera();
}

// =========================================================================
// --- RED ---
// =========================================================================

bool hayWiFi() {
  if (WiFi.status() == WL_CONNECTED) return true;

  mostrarMensaje("SIN WIFI", "Reconectando...");
  delay(2000);
  cancelarFlujo();
  return false;
}

bool enviarPost(String ruta, String payload, String &respuesta) {
  HTTPClient http;
  http.begin(serverBaseUrl + ruta);
  http.addHeader("Content-Type", "application/json");
  http.setTimeout(8000);

  int codigo = http.POST(payload);
  bool ok = (codigo > 0);

  if (ok) {
    respuesta = http.getString();
    Serial.println("Resp: " + respuesta);
  } else {
    Serial.println("Error HTTP: " + String(codigo));
  }

  http.end();
  return ok;
}

bool enviarGet(String url, String &respuesta, int timeoutMs) {
  HTTPClient http;
  http.begin(url);
  http.setTimeout(timeoutMs);

  int codigo = http.GET();
  bool ok = (codigo > 0);

  if (ok) {
    respuesta = http.getString();
  }

  http.end();
  return ok;
}

void conectarWiFi() {
  mostrarMensaje("Conectando WiFi", "");

  WiFi.begin(ssid, password);

  int intentos = 0;
  while (WiFi.status() != WL_CONNECTED && intentos < 20) {
    delay(500);
    Serial.print(".");
    lcd.setCursor(intentos % 16, 1);
    lcd.print(".");
    intentos++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nConectado a WiFi!");
    mostrarMensaje("WiFi conectado", WiFi.localIP().toString());
  } else {
    Serial.println("\nSin WiFi");
    mostrarMensaje("SIN WIFI", "Revisa la clave");
  }
  delay(2000);
}

void verificarConexionWiFi() {
  if (WiFi.status() != WL_CONNECTED) {
    static unsigned long ultimoReintento = 0;
    if (millis() - ultimoReintento > 20000) {
      ultimoReintento = millis();
      Serial.println("Reintentando WiFi...");
      WiFi.disconnect();
      WiFi.begin(ssid, password);
    }
  }
}

// =========================================================================
// --- LECTORES DE JSON (sin librerías externas) ---
// =========================================================================

String extraerValorJSON(String json, String llave) {
  String patron = "\"" + llave + "\":\"";
  int index = json.indexOf(patron);
  if (index == -1) return "";

  int inicioVal = index + patron.length();
  int finVal = json.indexOf("\"", inicioVal);
  if (finVal == -1) return "";

  return json.substring(inicioVal, finVal);
}

int extraerEnteroJSON(String json, String llave) {
  String patron = "\"" + llave + "\":";
  int index = json.indexOf(patron);
  if (index == -1) return 0;

  int inicioVal = index + patron.length();
  int finVal = inicioVal;
  while (finVal < (int)json.length() && isDigit(json.charAt(finVal))) {
    finVal++;
  }
  if (finVal == inicioVal) return 0;

  return json.substring(inicioVal, finVal).toInt();
}

bool extraerBooleanoJSON(String json, String llave) {
  String patron = "\"" + llave + "\":";
  int index = json.indexOf(patron);
  if (index == -1) return false;

  int inicioVal = index + patron.length();
  return json.substring(inicioVal, inicioVal + 4) == "true";
}
