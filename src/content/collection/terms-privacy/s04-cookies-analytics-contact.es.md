---
lang: es
pageKey: "terms-privacy"
status: "published"
section: "s04"
order: 40
anchor: "cookies-analytics-contact"
navLabel: "Cookies y eventos de contacto"
title: "Cookies, análisis e interacciones de contacto"
---

La medición de contactos y el análisis del recorrido requieren consentimiento de análisis. WhatsApp y el correo siguen disponibles sin seguimiento opcional.

### 1. Eventos de contacto

Solo con consentimiento de análisis, seleccionar un enlace de WhatsApp o correo puede registrar un evento que incluya:

- el canal seleccionado;
- un tipo de evento `open_intent`;
- la hora del servidor;
- la ruta de la página relevante en el sitio;
- la ubicación del enlace;
- el SKU del producto cuando corresponda;
- el idioma del sitio; y
- un estado de intención (`intent`).

Sin consentimiento de análisis no se solicitan credenciales ni se instala la cookie de medición de contactos ni se registran aperturas. Con consentimiento, el evento puede vincularse al recorrido descrito abajo. Un tratamiento de seguridad separado puede proteger el contacto solicitado.

Un registro `open_intent` solo significa que se activó el enlace de contacto del sitio web. No prueba que un dispositivo haya abierto correctamente WhatsApp o un cliente de correo electrónico, que el visitante haya enviado un mensaje o que Kssmi haya recibido uno.

Un evento `submission_success` significa que el sitio aceptó la consulta en su proceso de envío, que puede incluir una cola. No acredita la entrega del correo, su recepción por una persona concreta ni que haya sido leído o respondido.

### 2. Seguimiento del recorrido del visitante (VJT)

Con el consentimiento de análisis, VJT puede utilizar un identificador de visitante de origen y un identificador de sesión de corta duración para asociar las visitas a la página y los eventos de contacto con un recorrido consentido. Dependiendo de la configuración activa, los datos del recorrido pueden incluir:

- URL y títulos de las páginas;
- tiempos de visita e interacción;
- parámetros de referencia y campaña;
- información sobre el navegador, dispositivo, pantalla, idioma y zona horaria;
- país o ciudad derivados de IP;
- medidas de desplazamiento y participación; y
- atribución de consultas o eventos de contacto.

El recorrido de análisis debe permanecer desactivado hasta que el visitante otorgue su consentimiento para análisis. Cada consentimiento inicia un nuevo ciclo de consentimiento y los identificadores VJT no se trasladan de un ciclo al siguiente. Cuando se retira el consentimiento, el sitio detiene en el navegador la nueva recopilación de análisis y la emisión de identificadores, elimina los valores VJT del almacenamiento del navegador y solicita al servidor que haga caducar la cookie de identidad HttpOnly. Esa eliminación se reintenta un número limitado de veces y puede fallar, por ejemplo, si el navegador está sin conexión o la solicitud se bloquea; una cookie de identidad que quede no se usa para continuar el recorrido anterior y se sustituye si se vuelve a otorgar el consentimiento. Los datos ya enviados antes de la retirada aún pueden recibirse. Los identificadores creados antes de la introducción de los ciclos de consentimiento se sustituyen una vez. Los navegadores que no disponen de las funciones necesarias para coordinar el consentimiento entre pestañas no utilizan VJT.

### 3. Publicidad y análisis de terceros

Google Analytics, Google Ads, Google Tag Manager o tecnologías comparables funcionan según las categorías de consentimiento elegidas y la configuración activa del sitio. El almacenamiento para análisis y publicidad permanece denegado hasta obtener el consentimiento correspondiente.

### 4. Cookies y almacenamiento del navegador

A continuación se enumeran los principales elementos de almacenamiento del navegador. Los identificadores de terceros varían según proveedor, navegador, consentimiento y configuración activa.

| Nombre | Proveedor | Finalidad | Categoría | Duración | Tipo de almacenamiento |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Recordar las opciones de análisis y publicidad del visitante | Necesario | Hasta que se cambie la elección o se borre el almacenamiento del navegador | Almacenamiento local |
| `vjt_visitor_id` | Kssmi | Asociar las visitas consentidas con el recorrido de un visitante | Análisis | Cookie: hasta unos 365 días; copia local: Hasta que se retire el consentimiento de análisis o se borre el almacenamiento del navegador | Cookie y almacenamiento local |
| `vjt_session_id` | Kssmi | Asociar eventos de página consentidos dentro de una sesión | Análisis | Aproximadamente 30 minutos | Cookie |
| `KSSMI_VJT_IDENTITY` | Kssmi | Identidad de servidor firmada que vincula las visitas consentidas dentro de un ciclo de consentimiento; los scripts de la página no pueden leerla | Análisis | Hasta aproximadamente 365 días; caduca tras la retirada cuando el navegador lo permite | Cookie (HttpOnly) |
| `KSSMI_VJT_CONTACT` | Kssmi | Sesión firmada que protege las estadísticas de contactos consentidas, vinculada al ciclo actual; emitida solo con consentimiento de análisis | Análisis | Aproximadamente 30 minutos, renovada mientras se navega | Cookie (HttpOnly) |
| Otros identificadores de Google o terceros | Google / tercero correspondiente | Análisis o publicidad | Análisis/publicidad | Varía según el proveedor y la configuración | Cookie o tecnología similar |

El inventario de cookies, el banner de consentimiento y la implementación en vivo deben coincidir. Cambiar el nombre de un rastreador o mover un identificador de una cookie al almacenamiento local no exime por sí solo a la tecnología del consentimiento.

### 5. Modificación de las opciones de consentimiento

Los visitantes deben poder volver a abrir la Configuración de cookies y cambiar o retirar su consentimiento para publicidad y análisis con la misma facilidad con la que lo dieron. La retirada no afecta al procesamiento que era legal antes de la misma. Una vez realizada una elección, en cada página sigue disponible un botón de Configuración de cookies.

### 6. Recuento anónimo de visitas a páginas
Independientemente del análisis basado en consentimiento descrito anteriormente, el sitio web cuenta las visitas a páginas de forma agregada: para cada día natural (hora de Pekín) y ruta de página, solo almacena el número total de visitas. Esta tabla de recuento no utiliza cookies, ni almacenamiento del navegador, ni identificadores de visitante o sesión, y no incluye direcciones IP, agentes de usuario, referentes ni marcas de tiempo de visitas individuales. La limitación de velocidad, el filtrado de bots y los registros del servidor son operaciones de seguridad independientes descritas en sus propias secciones; no forman parte de esta tabla agregada. El servidor puede leer por separado una marca firmada de exclusión de administradores para evitar contar el tráfico de administración; esa marca no se almacena en la tabla agregada anónima.
