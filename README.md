# WP User Analyse

Plugin de WordPress que registra la actividad web de visitantes anónimos y usuarios registrados, sin servicios de terceros. Todos los datos se guardan en tablas propias de la base de datos de WordPress.

La sincronización con HubSpot queda fuera de esta versión. Las tablas ya dejan preparado lo necesario para añadirla (`synced_at` en sesiones y los ajustes de identificador de web y entidad).

## Qué registra

| Dato | Anónimos | Registrados | Cómo |
| --- | --- | --- | --- |
| Altas por fecha | — | Sí | Hook `user_register` y tabla `wp_users` (incluye las altas anteriores al plugin) |
| Accesos (logins) | — | Sí | Hook `wp_login` |
| Páginas vistas | Sí | Sí | Script `tracker.js` |
| Sesiones y tiempo activo | Sí | Sí | Latido cada 5 s mientras la pestaña está visible y hubo interacción en los últimos 2 minutos |
| Profundidad de scroll | Sí | Sí | Máximo por página vista |
| Clics en botones y enlaces | Sí | Sí | Selectores configurables y atributo `data-wua-track` |
| Mapa de calor de clics | Sí | Sí | Posición relativa al elemento pulsado, por dispositivo |
| Origen (UTM, referrer, página de entrada) | Sí | Sí | Primera visita del visitante y de cada sesión |

Cuando un visitante anónimo se registra o inicia sesión, su historial previo en ese navegador pasa a su usuario.

## Panel

Menú **Actividad web** en el escritorio, con filtro de fechas en todas las pantallas:

- **Resumen:** cifras clave, sesiones por día (anónimos frente a registrados), altas y accesos por día, tiempo activo por día, páginas y clics más frecuentes.
- **Páginas:** vistas, visitantes, usuarios, tiempo activo medio y scroll medio.
- **Clics:** elementos más pulsados por página.
- **Usuarios:** actividad de cada usuario registrado y su ficha con todas sus sesiones.
- **Altas:** altas del periodo con origen de la primera visita y sesiones previas al alta.
- **Sesiones:** listado y recorrido completo de cada sesión (páginas, clics y accesos en orden).
- **Mapa de calor:** la página real en un iframe con la capa de calor encima, por dispositivo.
- **Ajustes.**

Las pantallas de Páginas, Clics, Usuarios, Altas y Sesiones se pueden exportar a CSV (separador `;`, compatible con Excel).

## Consentimiento (RGPD)

Sin consentimiento de estadística, el script no escribe cookies ni envía nada. Modos:

- **Automático (por defecto):** usa la [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) si está activa. Si no lo está, espera a `wua.grantConsent()`.
- **WP Consent API:** categoría `statistics`.
- **Manual:** el gestor de cookies llama a `wua.grantConsent()` al aceptar y a `wua.revokeConsent()` al retirar.
- **Sin consentimiento:** solo si vuestro DPO lo ha validado.

Las altas y los accesos se registran en el servidor siempre, porque forman parte de la cuenta del usuario. El plugin añade un texto sugerido a la guía de la política de privacidad y se integra con las herramientas de exportación y borrado de datos personales de WordPress.

## Instalación en varias webs

1. Copia la carpeta del plugin en `wp-content/plugins/` y actívalo.
2. Revisa **Actividad web → Ajustes**: identificador de la web, entidad, modo de consentimiento y exclusiones.
3. Para replicar la configuración sin tocar el panel, fija cualquier ajuste en `wp-config.php` con una constante `WUA_<CLAVE>`:

```php
define( 'WUA_SITE_ID', 'labolife-es' );
define( 'WUA_ENTITY', 'LL España' );
define( 'WUA_CONSENT_MODE', 'consent_api' );
define( 'WUA_HEATMAP_SAMPLE', 50 );
```

Para nombrar un botón en los informes:

```html
<a href="/inscripcion" data-wua-track="Inscribirme al curso">Apúntate</a>
```

Eventos personalizados desde JavaScript: `wua.track('Vídeo reproducido')`.

## Actualizaciones desde GitHub

El plugin se actualiza desde las *releases* de este repositorio, igual que un plugin de wordpress.org: aviso en **Plugins**, actualización con un clic y, si se quiere, actualización automática.

**Instalación inicial en cada web:** descarga `wp-user-analyse.zip` de la última release y súbelo en **Plugins → Añadir nuevo → Subir plugin**. No uses el botón "Download ZIP" del repositorio: ese zip trae una carpeta con otro nombre y las actualizaciones no la reconocerían.

**Publicar una versión nueva:**

1. Sube el número de versión en `wp-user-analyse.php`, en la cabecera `Version:` y en la constante `WUA_VERSION`.
2. Fusiona el cambio en `main`.
3. En GitHub, crea una release con la etiqueta `v` + versión (por ejemplo `v0.2.0`) y publícala.
4. La GitHub Action *Empaquetar release* comprueba que la etiqueta coincide con la versión, genera `wp-user-analyse.zip` y lo adjunta a la release.
5. Las webs muestran la actualización en las siguientes horas. Se puede forzar con el enlace "Check for updates" de la lista de plugins.

Solo las releases publicadas llegan a las webs. Los commits en `main` no se distribuyen.

Constantes opcionales en `wp-config.php`:

- `WUA_GITHUB_TOKEN`: token de solo lectura, necesario si el repositorio pasa a ser privado.
- `WUA_UPDATE_REPO`: URL de otro repositorio (por ejemplo, un fork).
- `WUA_DISABLE_UPDATES`: `true` para desactivar la comprobación.

Las actualizaciones usan la librería [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (licencia MIT), incluida en `lib/`.

## Notas técnicas

- Requiere WordPress 6.0+ y PHP 7.4+, con MySQL o MariaDB.
- Endpoint de recogida: `POST /wp-json/wua/v1/collect`. Si un plugin de seguridad bloquea la API REST a usuarios anónimos, hay que permitir esta ruta.
- Todo se guarda en UTC y se muestra en la zona horaria del sitio.
- Detrás de un proxy o CDN, la IP real se configura con el filtro `wua_client_ip`.
- La capacidad para ver los informes se cambia con el filtro `wua_capability` (por defecto `manage_options`).
- Una tarea diaria purga los datos según los plazos de retención. En producción conviene un cron real del servidor en lugar de WP-Cron.
- Al desinstalar se borran tablas y ajustes, salvo que `WUA_KEEP_DATA` esté definido.
- El mapa de calor necesita que la web se pueda cargar en un iframe desde su propio escritorio (`X-Frame-Options: SAMEORIGIN` vale, `DENY` no).
