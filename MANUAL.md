# iCalSync — Manual de Usuario

Plugin de sincronización bidireccional entre FacturaScripts (PlanetaEscenario) e iCloud Calendar vía CalDAV.

---

## Índice

1. [Requisitos previos](#1-requisitos-previos)
2. [Instalación](#2-instalación)
3. [Configuración general (admin)](#3-configuración-general-admin)
4. [Configuración por usuario](#4-configuración-por-usuario)
5. [Uso del calendario integrado](#5-uso-del-calendario-integrado)
6. [Sincronización](#6-sincronización)
7. [Resolución de conflictos](#7-resolución-de-conflictos)
8. [Panel de auditoría](#8-panel-de-auditoría)
9. [Logs de sincronización](#9-logs-de-sincronización)
10. [Dashboard widget](#10-dashboard-widget)
11. [Automatización (Cron)](#11-automatización-cron)
12. [Notificaciones por email](#12-notificaciones-por-email)
13. [Reglas de negocio](#13-reglas-de-negocio)
14. [Solución de problemas](#14-solución-de-problemas)

---

## 1. Requisitos previos

| Requisito | Detalle |
|-----------|---------|
| FacturaScripts | Versión 2025.81 o superior |
| PlanetaEscenario | Plugin instalado y activo |
| PHP | 8.0+ con extensiones `curl`, `xml`, `mbstring`, `sodium` |
| Composer | Para instalar dependencias `sabre/dav` y `sabre/vobject` |
| iCloud | Una cuenta de Apple con **app-specific password** generada |

### Generar una app-specific password en iCloud

1. Iniciá sesión en [appleid.apple.com](https://appleid.apple.com)
2. Ve a **Inicio de sesión y seguridad** → **Contraseñas de aplicaciones**
3. Clic en **Generar contraseña de aplicación**
4. Poné un nombre descriptivo (ej: `FacturaScripts-iCalSync`)
5. Copiá la contraseña generada (formato: `xxxx-xxxx-xxxx-xxxx`)
6. Usala en la configuración del plugin como `app_specific_password`

> ⚠️ La contraseña solo se muestra una vez. Guardala en un lugar seguro.

---

## 2. Instalación

### 2.1 Instalar dependencias PHP

Desde el directorio de FacturaScripts, ejecutá:

```bash
composer require sabre/dav sabre/vobject
```

Esto instala las librerías CalDAV necesarias para comunicarse con iCloud.

### 2.2 Instalar el plugin

Copiá la carpeta `iCalSync` dentro de `Plugins/` en tu instalación de FacturaScripts:

```
facturascripts/
└── Plugins/
    └── iCalSync/       ← esta carpeta
        ├── Init.php
        ├── facturascripts.ini
        ├── Model/
        ├── Controller/
        ├── ...
```

### 2.3 Activar el plugin

1. Entrá al panel de administración de FacturaScripts
2. Ve a **Admin** → **Plugins**
3. Buscá **iCalSync** en la lista
4. Clic en **Instalar**

El plugin detectará automáticamente si PlanetaEscenario está instalado. Si no lo está, mostrará una advertencia y no cargará sus extensiones.

### 2.4 Verificar la instalación

Después de instalar, deberías ver estos nuevos items en el menú:

- **Admin** → **General** → **iCalSync Configuración**
- **Admin** → **General** → **iCalSync Logs**
- **Admin** → **General** → **iCalSync Auditoría**
- **Perfil** → **Servicios** → **Mi sincronización iCloud**

---

## 3. Configuración general (admin)

> Ruta: **Admin** → **General** → **iCalSync Configuración**

Esta sección configura la conexión al **calendario compartido corporativo** de iCloud. Solo los administradores pueden acceder.

### 3.1 Pantalla principal

La pantalla de configuración muestra tres secciones:

#### a) Cuentas iCloud

Lista de cuentas compartidas configuradas. Desde acá podés:

- **Añadir cuenta**: botón para crear una nueva cuenta compartida
- **Probar conexión**: verifica que las credenciales funcionan y descubre calendarios disponibles
- **Eliminar cuenta**: borra la cuenta y sus calendarios asociados

#### b) Conflictos pendientes

Muestra los items de sincronización que están en estado de conflicto (versión local vs remota divergentes).

- **Resolver con versión local**: mantiene los datos de FacturaScripts y sobreescribe iCloud
- **Resolver con versión remota**: mantiene los datos de iCloud y sobreescribe FacturaScripts
- **Resolver todos**: resuelve todos los conflictos en lote usando la versión local

#### c) Errores recientes

Últimos 10 errores de sincronización para diagnóstico rápido.

### 3.2 Configuración global (Settings)

Los siguientes parámetros se configuran vía `XMLView/SettingsICalSync.xml`:

| Campo | Descripción | Valor por defecto |
|-------|-------------|-------------------|
| `enabled` | Activar/desactivar sincronización global | `false` |
| `sync_frequency_minutes` | Frecuencia de sincronización automática | `15` |
| `log_level` | Nivel de detalle de logs (`debug`, `info`, `warning`, `error`) | `warning` |
| `conflict_strategy` | Estrategia ante conflictos (`last_write_wins`, `manual`, `source_wins`, `destination_wins`) | `last_write_wins` |
| `batch_size` | Tamaño de lote por ejecución | `25` |
| `max_execution_time` | Tiempo máximo de ejecución por batch (segundos) | `30` |
| `email_notifications_enabled` | Activar notificaciones por email | `false` |
| `email_notification_level` | Nivel mínimo para notificar (`error`, `warning`) | `error` |
| `admin_email` | Email del administrador para notificaciones | _(vacío)_ |

### 3.3 Probar conexión

1. Seleccioná una cuenta de la lista
2. Clic en **Probar conexión**
3. El sistema:
   - Intenta descubrir la principal URL de CalDAV
   - Autentica con el Apple ID y la app-specific password
   - Descubre los calendarios disponibles en la cuenta
   - Muestra el resultado (éxito o error)

Si la prueba es exitosa, los calendarios descubiertos se guardan automáticamente y podés activarlos individualmente.

### 3.4 Forzar sincronización

Clic en **Forzar sincronización** ejecuta una sincronización completa inmediata de todas las cuentas activas. Útil para:

- Primera sincronización después de configurar
- Después de resolver conflictos masivos
- Para verificar que todo funciona sin esperar al cron

---

## 4. Configuración por usuario

> Ruta: **Perfil** → **Servicios** → **Mi sincronización iCloud**

Cada usuario puede configurar su propia conexión a su **calendario privado** de iCloud.

### 4.1 Campos disponibles

| Campo | Descripción |
|-------|-------------|
| `enabled` | Activar la cuenta del usuario |
| `apple_id` | Tu Apple ID (email de iCloud) |
| `app_specific_password` | Contraseña de aplicación (se guarda encriptada) |
| `calendar_url` | URL del calendario privado (se autocompleta al probar conexión) |
| `principal_url` | URL principal CalDAV (se autocompleta al probar conexión) |
| `sync_enabled` | Activar sincronización automática para este usuario |
| `show_in_calendar` | Mostrar citas privadas en el calendario de PlanetaEscenario |
| `show_in_dashboard` | Mostrar citas privadas en el widget del dashboard |
| `last_sync_at` | Última sincronización exitosa (solo lectura) |

### 4.2 Probar conexión (usuario)

1. Ingresá tu Apple ID y app-specific password
2. Clic en **Probar conexión**
3. El sistema verifica las credenciales y descubre tu principal URL y calendarios
4. El primer calendario descubierto se asigna automáticamente

### 4.3 Guardar configuración

Clic en **Guardar**. La contraseña se encripta con libsodium antes de almacenarse en la base de datos.

> 🔒 La contraseña NUNCA se muestra en texto plano después de guardar. El campo aparece vacío al editar (comportamiento normal por seguridad).

---

## 5. Uso del calendario integrado

El calendario de PlanetaEscenario ahora incluye funcionalidades de iCalSync:

### 5.1 Filtro de origen

En la vista del calendario, aparece un filtro desplegable con estas opciones:

| Valor | Muestra |
|-------|---------|
| `todos` | Todos los eventos y citas (por defecto) |
| `icloud` | Solo entradas sincronizadas desde/hacia el calendario compartido |
| `icloud-privado` | Solo citas privadas sincronizadas del usuario actual |
| `interno` | Solo entradas creadas en FacturaScripts (sin sincronizar) |

### 5.2 Badges de sincronización

Las entradas del calendario muestran indicadores visuales:

- **🟢 Sincronizado**: la entrada está mapeada a un evento de iCloud y sincronizada
- **🟡 Pendiente**: la entrada fue modificada pero aún no sincronizada
- **🔴 Error**: hubo un error al sincronizar esta entrada
- **Sin badge**: entrada interna sin relación con iCloud

### 5.3 Visibilidad por usuario

- **Citas del calendario compartido**: visibles para todos los usuarios autorizados
- **Citas privadas**: solo visibles para el usuario propietario
- **Eventos**: siempre visibles según los permisos de PlanetaEscenario

---

## 6. Sincronización

### 6.1 Tipos de sincronización

| Tipo | Gatillo | Descripción |
|------|---------|-------------|
| **Automática (cron)** | Cada N minutos (configurable) | Sincroniza todas las cuentas activas en segundo plano |
| **Manual (admin)** | Botón "Forzar sincronización" | Sincronización completa inmediata |
| **Por evento** | Al guardar/borrar un Evento o Cita | Marca la entidad como pendiente (el cron la procesa) |

### 6.2 Qué se sincroniza

| Origen | Destino | Dirección |
|--------|---------|-----------|
| iCloud (compartido) → FacturaScripts | Calendario compartido → **Citas** | Import |
| FacturaScripts → iCloud (compartido) | **Eventos** → Calendario compartido | Export |
| iCloud (privado) → FacturaScripts | Calendario privado → **Citas** del usuario | Import |
| FacturaScripts → iCloud (privado) | **Citas** del usuario → Calendario privado | Export |

### 6.3 Flujo de sincronización

```
1. Cron se ejecuta cada sync_frequency_minutes
2. Carga cuentas compartidas activas
3. Para cada cuenta:
   a. Descubre/recupera calendarios del cache
   b. Lista eventos modificados desde última sincronización (delta)
   c. Exporta Eventos nuevos/modificados → iCloud
   d. Importa eventos de iCloud → Citas
   e. Resuelve conflictos según estrategia configurada
   f. Registra todas las operaciones en ICalSyncLog
4. Repite para cuentas privadas de usuarios (máx 10 por ejecución)
5. Guarda progreso para continuar en la próxima ejecución
```

### 6.4 Límites de rendimiento

- **Batch size**: 25 entidades por lote
- **Tiempo máximo**: 30 segundos por ejecución
- **Usuarios por ejecución**: máximo 10
- **Safety cap**: máximo 100 usuarios en total por ejecución

---

## 7. Resolución de conflictos

Un conflicto ocurre cuando una entidad fue modificada tanto en FacturaScripts como en iCloud desde la última sincronización.

### 7.1 Estrategias disponibles

| Estrategia | Comportamiento |
|------------|----------------|
| `last_write_wins` | Compara `ultima_modificacion` vs `last-modified` remoto. Gana el más reciente. |
| `manual` | No sobreescribe nada. Crea un registro de conflicto para resolver manualmente. |
| `source_wins` | La versión remota (iCloud) siempre gana. |
| `destination_wins` | La versión local (FacturaScripts) siempre gana. |

### 7.2 Resolver conflictos manualmente

1. Ve a **Admin** → **iCalSync Configuración**
2. En la sección **Conflictos pendientes**, revisá los items en conflicto
3. Para cada uno, elegí:
   - **Resolver con versión local**: mantiene los datos de FS
   - **Resolver con versión remota**: trae los datos de iCloud
4. O usá **Resolver todos** para aplicar "versión local" a todos en lote

### 7.3 Estrategia por defecto

Configurable en **Settings** → `conflict_strategy`. Para la mayoría de los casos se recomienda `last_write_wins`.

---

## 8. Panel de auditoría

> Ruta: **Admin** → **General** → **iCalSync Auditoría**

Proporciona una vista analítica de toda la actividad de sincronización.

### 8.1 Tarjetas de resumen

| Indicador | Descripción |
|-----------|-------------|
| **Total operaciones** | Cantidad total de sincronizaciones registradas |
| **Exitosas** | Operaciones completadas correctamente |
| **Errores** | Operaciones que fallaron |
| **Conflictos** | Conflictos detectados (si usás estrategia `manual`) |

### 8.2 Desgloses

- **Por operación**: gráfico de barras con import/export/delete
- **Por entidad**: desglose entre Eventos y Citas

### 8.3 Filtros

| Filtro | Opciones |
|--------|----------|
| **Rango de fechas** | Desde / Hasta |
| **Tipo de operación** | import, export, delete |
| **Estado** | success, error, conflict, skipped |

### 8.4 Exportar CSV

Clic en **Exportar CSV** descarga un archivo con todos los registros filtrados. Columnas:

- Fecha, Operación, Entidad, ID Entidad, UID CalDAV, Estado, Mensaje

---

## 9. Logs de sincronización

> Ruta: **Admin** → **General** → **iCalSync Logs**

Vista de lista con todas las operaciones de sincronización registradas.

### 9.1 Columnas

| Columna | Descripción |
|---------|-------------|
| Fecha | Cuándo se ejecutó la operación |
| Operación | `import`, `export`, `delete` |
| Entidad | `Evento` o `Cita` |
| ID Entidad | ID en FacturaScripts |
| UID CalDAV | Identificador en iCloud |
| Estado | `success`, `error`, `conflict`, `skipped` |
| Mensaje | Detalle de la operación o mensaje de error |

### 9.2 Limpiar logs

El botón **Limpiar logs** borra todos los registros. Útil después de una migración inicial o para empezar de cero.

---

## 10. Dashboard widget

El widget del dashboard de PlanetaEscenario ahora incluye información de sincronización:

### 10.1 Indicadores mostrados

- **Última sincronización**: fecha y hora de la última sync exitosa
- **Pendientes**: cantidad de Eventos y Citas sin sincronizar
- **Errores**: cantidad de errores en la última ejecución
- **Estado**: indicador visual (verde = OK, amarillo = pendiente, rojo = error)

### 10.2 Visibilidad

El widget respeta la configuración `show_in_dashboard` de cada usuario. Si un usuario desactiva esta opción en su configuración privada, sus citas privadas no aparecen en el contador del dashboard.

---

## 11. Automatización (Cron)

### 11.1 Configuración del cron

El cron job `ICalSync` se registra automáticamente al instalar el plugin. FacturaScripts lo ejecuta según su propio scheduler de cron jobs.

Si tu instalación usa cron del sistema, asegurate de que el cron de FacturaScripts esté activo:

```bash
* * * * * php /ruta/a/facturascripts/Cron.php
```

### 11.2 Comportamiento

1. El cron verifica si `enabled = true` en la configuración global
2. Si está desactivado, no hace nada
3. Procesa primero las cuentas compartidas
4. Luego procesa usuarios privados (máximo 10 por ejecución)
5. Respeta el límite de 30 segundos por ejecución
6. Si no termina, guarda el progreso y continúa en la siguiente ejecución

### 11.3 Frecuencia recomendada

| Uso | Frecuencia |
|-----|------------|
| Alta actividad | cada 5 minutos |
| Uso normal | cada 15 minutos (por defecto) |
| Baja actividad | cada 30-60 minutos |

---

## 12. Notificaciones por email

### 12.1 Activar notificaciones

1. Ve a **Admin** → **iCalSync Configuración** → **Settings**
2. Activá `email_notifications_enabled`
3. Configurá `admin_email` con el email del administrador
4. Elegí `email_notification_level`:
   - `error`: solo notifica errores críticos
   - `warning`: notifica errores y advertencias

### 12.2 Qué dispara una notificación

- Error de conexión con iCloud
- Error de autenticación (app-specific password inválida)
- Fallos en lote (múltiples errores en una ejecución)
- Excepciones no controladas durante la sincronización

Las notificaciones incluyen el nombre de la cuenta/usuario afectado y un resumen del error.

---

## 13. Reglas de negocio

El plugin aplica estas reglas de forma automática y no configurable:

### 13.1 Importación desde iCloud

> Todo lo que viene de iCalendar **siempre** se convierte en **Cita** de PlanetaEscenario. Nunca en Evento.

- Calendario compartido → Cita visible para todos los usuarios autorizados
- Calendario privado → Cita visible solo para el usuario propietario

### 13.2 Exportación hacia iCloud

> Los **Eventos** de PlanetaEscenario **siempre** van al calendario compartido. Nunca a un calendario privado.

> Las **Citas** privadas **solo** van al calendario privado de su usuario propietario. Nunca al compartido.

### 13.3 Aislamiento

- Un usuario **nunca** ve las citas privadas de otro usuario
- Un Evento **nunca** termina accidentalmente en un calendario privado
- Una Cita importada del calendario compartido **nunca** se exporta a un calendario privado

---

## 14. Solución de problemas

### 14.1 Error de conexión: "test-connection-failure"

**Causas posibles:**
- Apple ID incorrecto
- App-specific password incorrecta o expirada
- Verificación en dos pasos no configurada correctamente
- Bloqueo de iCloud por intentos fallidos

**Solución:**
1. Verificá que el Apple ID sea exactamente tu email de iCloud
2. Generá una nueva app-specific password en [appleid.apple.com](https://appleid.apple.com)
3. Asegurate de que la verificación en dos pasos esté activa en tu cuenta
4. Esperá 15 minutos si hubo muchos intentos fallidos (iCloud bloquea temporalmente)

### 14.2 Error: "credential-decrypt-error"

**Causa:** La extensión `sodium` de PHP no está instalada o hubo un cambio en el entorno.

**Solución:**
```bash
# Verificar que sodium está instalado
php -m | grep sodium

# Si no aparece, instalar:
# Ubuntu/Debian:
sudo apt install php-sodium
# Con PHP compilado:
# Recompilar con --with-sodium
```

Si cambiaste de servidor, las credenciales encriptadas no son transferibles (la clave de encriptación se deriva del nombre de la base de datos).

### 14.3 No aparecen los items del menú

**Causa:** PlanetaEscenario no está instalado o la tabla `eventos` no existe.

**Solución:**
1. Verificá que PlanetaEscenario esté instalado y activo
2. Revisá **Admin** → **Plugins** → **PlanetaEscenario**
3. Si está instalado pero el menú no aparece, desactivá y reactivá iCalSync

### 14.4 La sincronización no se ejecuta

**Verificá en orden:**
1. ¿Está `enabled = true` en la configuración global?
2. ¿Hay al menos una cuenta compartida con `enabled = true`?
3. ¿Hay al menos un calendario habilitado para esa cuenta?
4. ¿El cron de FacturaScripts está funcionando?
5. Revisá **iCalSync Logs** para ver si hay errores registrados

### 14.5 Eventos recurrentes

Los eventos recurrentes de iCloud (con regla RRULE) **no se sincronizan completamente** en esta versión. El sistema:

- Detecta el evento recurrente
- Lo importa como Cita con una nota "Recurring event — individual instances not synced"
- Registra un warning en los logs
- No expande las ocurrencias individuales

El soporte completo para eventos recurrentes está planeado para una fase futura.

### 14.6 Rate limiting de iCloud

iCloud aplica rate limiting a las operaciones CalDAV. El plugin maneja esto con:

- **Batch processing**: máximo 25 entidades por lote
- **Exponential backoff**: reintentos con espera creciente ante HTTP 429
- **Time budget**: máximo 30 segundos por ejecución de cron

Si ves errores `429 Too Many Requests` frecuentes, aumentá `sync_frequency_minutes` a 30 o 60.

---

## Apéndice: Estructura de archivos

```
iCalSync/
├── Init.php                          # Bootstrap y menús
├── facturascripts.ini                # Metadatos y dependencias
├── composer.json                     # Dependencias sabre/dav
├── Cron.php                          # Registro de cron jobs
├── MANUAL.md                         # Este manual
├── Model/
│   ├── ICalSyncAccount.php           # Cuenta iCloud compartida
│   ├── ICalSyncCalendar.php          # Calendario descubierto
│   ├── ICalSyncItem.php              # Mapeo de sincronización
│   ├── ICalSyncLog.php               # Registro de operaciones
│   └── ICalSyncUserAccount.php       # Cuenta iCloud por usuario
├── Table/                            # Esquemas XML de tablas
│   ├── icalsync_accounts.xml
│   ├── icalsync_calendars.xml
│   ├── icalsync_items.xml
│   ├── icalsync_logs.xml
│   └── icalsync_user_accounts.xml
├── Controller/
│   ├── AdminICalSync.php             # Panel de administración
│   ├── AuditICalSync.php             # Informe de auditoría
│   ├── EditICalSyncUserAccount.php   # Configuración por usuario
│   └── ListICalSyncLog.php           # Visor de logs
├── XMLView/                          # Vistas XML de formularios
│   ├── SettingsICalSync.xml
│   ├── EditICalSyncUserAccount.xml
│   └── ListICalSyncLog.xml
├── View/                             # Plantillas Twig
│   ├── AdminICalSync.html.twig
│   └── AuditICalSync.html.twig
├── Extension/
│   ├── Table/                        # Columnas extra en tablas PE
│   │   ├── eventos.xml
│   │   └── citas.xml
│   ├── Model/                        # Hooks de modelos PE
│   │   ├── Evento.php
│   │   └── Cita.php
│   ├── Controller/                   # Extensiones de controladores PE
│   │   └── CalendarioEventos.php
│   └── View/                         # Extensiones Twig para PE
│       └── CalendarioEventos.html.twig
├── CronJob/
│   └── ICalSync.php                  # Sincronización automática
├── Lib/
│   ├── Service/
│   │   ├── CalDavClient.php          # Cliente CalDAV HTTP
│   │   ├── ICloudCalendarService.php # Lógica específica de iCloud
│   │   └── SyncEngine.php            # Motor de sincronización
│   └── Util/
│       ├── CredentialEncryption.php      # Encriptación de credenciales
│       ├── ConflictResolutionStrategy.php # Estrategias de conflicto
│       └── SyncNotifier.php              # Notificaciones por email
└── Translation/
    └── es_ES.json                    # Traducciones al español
```
