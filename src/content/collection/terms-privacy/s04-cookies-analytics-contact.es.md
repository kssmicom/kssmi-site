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

Kssmi separa una función mínima de eventos de contacto de la función de análisis del recorrido del visitante basada en el consentimiento. Sirven para diferentes propósitos y no deben describirse como un único sistema con una sola base legal.

### 1. Eventos de contacto

Cuando un visitante selecciona deliberadamente un enlace de WhatsApp o un correo electrónico, el sitio web puede registrar un evento mínimo que muestre que se abrió el punto de entrada de contacto. Sin el consentimiento de análisis, este evento está diseñado para contener solo:

- el canal seleccionado;
- un tipo de evento `open_intent`;
- la hora del servidor;
- la ruta de la página relevante en el sitio;
- la ubicación del enlace;
- el SKU del producto cuando corresponda;
- el idioma del sitio; y
- un estado de intención (`intent`).

Sin el consentimiento de análisis, este registro no debe crear ni leer un identificador de visitante/sesión de VJT y no debe contener un recorrido de navegación reconstruido, URL de referencia completa, parámetros de campaña, dirección IP, agente de usuario o geolocalización. Se puede realizar un procesamiento de seguridad independiente y de corta duración para limitar la velocidad.

Un registro `open_intent` solo significa que se activó el enlace de contacto del sitio web. No prueba que un dispositivo haya abierto correctamente WhatsApp o un cliente de correo electrónico, que el visitante haya enviado un mensaje o que Kssmi haya recibido uno.

Para un formulario de consulta, un evento `submission_success` significa que el proceso de envío configurado en el sitio web informó de un éxito. No prueba que un destinatario haya leído o respondido al correo electrónico.

### 2. Seguimiento del recorrido del visitante (VJT)

Con el consentimiento de análisis, VJT puede utilizar un identificador de visitante de origen y un identificador de sesión de corta duración para asociar las visitas a la página y los eventos de contacto con un recorrido consentido. Dependiendo de la configuración activa, los datos del recorrido pueden incluir:

- URL y títulos de las páginas;
- tiempos de visita e interacción;
- parámetros de referencia y campaña;
- información sobre el navegador, dispositivo, pantalla, idioma y zona horaria;
- país o ciudad derivados de IP;
- medidas de desplazamiento y participación; y
- atribución de consultas o eventos de contacto.

El recorrido de análisis debe permanecer deshabilitado hasta que el visitante otorgue el consentimiento para el análisis. Si se retira el consentimiento, la posterior recopilación de análisis debe detenerse y los identificadores de VJT almacenados en el navegador deben eliminarse de acuerdo con el proceso de retiro implementado.

### 3. Publicidad y análisis de terceros

Google Analytics, Google Ads, Google Tag Manager o tecnología de medición comparable deben funcionar de acuerdo con las categorías de consentimiento seleccionadas por el visitante y la configuración real del sitio. El aviso final debe describir únicamente los productos y funciones que estén genuinamente habilitados.

### 4. Cookies y almacenamiento del navegador

Los siguientes períodos y criterios se aplican a los sistemas del sitio descritos en este aviso:

| Nombre | Proveedor | Finalidad | Categoría | Duración | Tipo de almacenamiento |
| --- | --- | --- | --- | --- | --- |
| `cookie-consent` | Kssmi | Recordar las opciones de análisis y publicidad del visitante | Necesario | Hasta que se cambie la elección o se borre el almacenamiento del navegador | Local storage |
| `vjt_visitor_id` | Kssmi | Asociar las visitas consentidas con el recorrido de un visitante | Análisis | Cookie: up to about 365 days; local copy: Hasta que se retire el consentimiento de análisis o se borre el almacenamiento del navegador | Cookie y almacenamiento local |
| `vjt_session_id` | Kssmi | Asociar eventos de página consentidos dentro de una sesión | Análisis | About 30 minutes | Cookie |
| Otros identificadores de Google o terceros | Google / relevant third party | Análisis o publicidad | Análisis/publicidad | Varía según el proveedor y la configuración | Cookie o tecnología similar |

El inventario de cookies, el banner de consentimiento y la implementación en vivo deben coincidir. Cambiar el nombre de un rastreador o mover un identificador de una cookie al almacenamiento local no exime por sí solo a la tecnología del consentimiento.

### 5. Modificación de las opciones de consentimiento

Los visitantes deben poder volver a abrir la Configuración de cookies y cambiar o retirar su consentimiento para publicidad y análisis con la misma facilidad con la que lo dieron. La retirada no afecta al procesamiento que era legal antes de la misma.

### 6. Recuento anónimo de visitas a páginas
Independientemente del análisis basado en consentimiento descrito anteriormente, el sitio web cuenta las visitas a páginas de forma agregada: para cada día natural (hora de Pekín) y ruta de página, solo almacena el número total de visitas. Esta tabla de recuento no utiliza cookies, ni almacenamiento del navegador, ni identificadores de visitante o sesión, y no incluye direcciones IP, agentes de usuario, referentes ni marcas de tiempo de visitas individuales. La limitación de velocidad, el filtrado de bots y los registros del servidor son operaciones de seguridad independientes descritas en sus propias secciones; no forman parte de esta tabla agregada. El servidor puede leer por separado una marca firmada de exclusión de administradores para evitar contar el tráfico de administración; esa marca no se almacena en la tabla agregada anónima.
