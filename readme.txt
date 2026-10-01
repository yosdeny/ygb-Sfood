=== ygb-sfood - Buscador de Alimentos por Lista ===
Contributors: yosdeny
Tags: buscador, alimentos, woocommerce, lista de compra, bulk order, carrito, cantidades, autocompletado, recetas
Requires at least: 7.0
Tested up to: 7.1.2
Stable tag: 5.16.0
Requires PHP: 8.0
Tested PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transforma listas de alimentos escritas en texto en productos de WooCommerce con cantidades y añádelos al carrito de forma masiva o individual o simplemente guarda la busqueda como recetas de cocina o compra.

== Description ==

**ygb-sfood** es un plugin especializado para tiendas de alimentación que permite a los usuarios escribir una lista de la compra separada por comas (ej. "2 manzanas, leche, pan integral") y obtener los productos coincidentes de tu catálogo WooCommerce. Los productos se muestran con imagen, precio y un campo de cantidad, listos para ser añadidos al carrito.

= Características principales =

* **Búsqueda por texto natural**: Escribe alimentos separados por comas y el sistema los busca en tu tienda.
* **Interpretación de cantidades**: Detecta automáticamente números al inicio de cada término (ej. "3 huevos" → cantidad 3).
* **Selector de cantidad global e individual**: Define una cantidad por defecto para todos los productos o ajusta cada uno por separado.
* **Añadir al carrito en lote**: Selecciona varios productos con checkboxes y agrégalos todos a la vez respetando las cantidades.
* **Añadir uno a uno**: Cada producto tiene su propio botón "Añadir" para una compra más granular.
* **Detección de productos en carrito**: Si un producto ya está en el carrito, se marca y deshabilita para evitar duplicados no deseados.
* **Autocompletado inteligente**: Mientras escribes, sugiere productos existentes para completar el último término.
* **Listas guardadas**: Los usuarios registrados pueden guardar sus búsquedas frecuentes y cargarlas con un clic.
* **Estadísticas de búsqueda**: En el panel de administración, consulta las últimas 100 búsquedas realizadas, productos encontrados y agregados.
* **Shortcode simple**: Inserta `[ygb_sfood]` en cualquier página y el buscador estará listo.
* **Plantilla de página "Lista de Compras"**: Crea una página y asígnale esta plantilla desde el editor para mostrar el buscador sin cabeceras ni elementos del tema.

= Requisitos =

* WordPress 7.0 o superior.
* WooCommerce instalado y activo.
* PHP 8.0 o superior.

== Installation ==

1. Sube la carpeta `ygb-sfood` al directorio `/wp-content/plugins/` de tu instalación de WordPress.
2. Activa el plugin desde el menú "Plugins" del escritorio de WordPress.
3. Asegúrate de que WooCommerce esté activo.
4. Inserta el shortcode `[ygb_sfood]` en la página o entrada donde quieras mostrar el buscador.
5. Opcional: crea una página nueva y asígnale la plantilla "Lista de Compras" para una vista sin distracciones del tema.

== Usage ==

1. Ve a la página donde insertaste el shortcode.
2. Escribe los nombres de los alimentos separados por comas (puedes usar cantidades, por ejemplo `2 leche, 1 pan`).
3. Si lo deseas, ajusta la cantidad global o las cantidades individuales en cada producto.
4. Marca los productos que quieras añadir y pulsa **"Añadir seleccionados al carrito"**, o usa el botón individual de cada producto.
5. Los usuarios registrados pueden guardar la búsqueda actual con el botón **"Guardar lista"** y recuperarla más tarde.

= Uso de la plantilla "Lista de Compras" =

1. Ve a **Páginas → Añadir nueva**.
2. En el panel lateral, selecciona **Lista de Compras** en el selector de plantilla.
3. Publica la página. El buscador se mostrará sin cabeceras, sidebars ni comentarios del tema.

== Frequently Asked Questions ==

= ¿Es necesario WooCommerce? =
Sí, el plugin depende completamente de WooCommerce. Sin él no funcionará.

= ¿Puedo usar este plugin para productos que no sean alimentos? =
Sí, aunque está pensado para alimentos, puedes usarlo con cualquier tipo de producto. Simplemente buscará por nombre en tu tienda.

= ¿Qué pasa si escribo un producto que no existe? =
El sistema te avisará de que no se encontraron coincidencias. La búsqueda se registra en las estadísticas para que puedas ampliar tu catálogo si lo deseas.

= ¿Se pueden añadir cantidades diferentes para cada producto? =
Sí, cada producto tiene un campo de cantidad independiente. Además, si escribes "3 tomates" y "1 tomate" en la misma búsqueda, el plugin asignará la cantidad más alta detectada para ese producto.

= ¿Cómo se guardan las listas? =
Solo los usuarios que hayan iniciado sesión pueden guardar listas. Estas se almacenan en el perfil del usuario (user meta) y se cargan automáticamente al visitar la página.

= ¿Dónde veo las estadísticas? =
En el menú de administración de WordPress encontrarás una nueva entrada llamada "ygb‑sfood". Allí se muestran las últimas 100 búsquedas con sus resultados.

== Screenshots ==

1. Interfaz principal del buscador con campo de texto, cantidad global y botones.
2. Resultados de búsqueda mostrando imagen, nombre, precio, checkbox y cantidad individual.
3. Listas guardadas por el usuario listas para cargar con un clic.
4. Panel de estadísticas en el escritorio de WordPress.
5. Ejemplo de autocompletado sugiriendo productos mientras se escribe.

== Changelog ==

= 5.16.0 =
* AÑADIDO: Plantilla de página "Lista de Compras" seleccionable en el editor.
* Sin cambios en el funcionamiento existente: rewrite /lista-de-compras/, shortcode `[ygb_sfood]`, panel admin, AJAX y caché intactos.

= 5.15.2 =
* CORREGIDO: `parsear()` rompía términos que contienen la letra "y" (p. ej. "mayonesa").
* CORREGIDO: posible XSS en atributos `data-*` generados dinámicamente.
* CORREGIDO: fuga de datos entre usuarios en la caché de búsquedas.
* CORREGIDO: `sanitize_hex_color()` podía devolver `null` y borrar la opción.
* CORREGIDO: validación estricta del campo `ygb_button_radius`.
* MEJORADO: uso de `wp_unslash()` en todas las lecturas de `$_POST`.
* MEJORADO: inicialización de la sesión de WooCommerce vía `WC()->initialize_session()`.
* AÑADIDO: header `Requires Plugins: woocommerce`.

= 5.15.1 =
* MEJORADO: Integración automática con los colores del tema activo de WordPress
* MEJORADO: Sistema avanzado de personalización de colores (22 opciones independientes)
* AÑADIDO: Botón "Añadir seleccionados al carrito" superior
* CORREGIDO: Espaciado y márgenes entre menú y contenido
* CORREGIDO: Efecto hover en el botón inferior

= 5.15.0 =
* AÑADIDO: Sistema de caché para búsquedas y sugerencias (transients API)
* AÑADIDO: Filtros `ygb_sfood_search_cache_time` y `ygb_sfood_suggestions_cache_time`
* AÑADIDO: Acciones `ygb_sfood_search_cache_hit`, `ygb_sfood_search_completed`, `ygb_sfood_suggestions_cache_hit`, `ygb_sfood_suggestions_generated`
* MEJORADO: Documentación completa de hooks y filtros
* CORREGIDO: URL del plugin cambiada a 'lista-de-compras'

= 2.0 =
* Añadida interpretación de cantidades
* Selector de cantidad global e individual
* Listas guardadas para usuarios registrados
* Autocompletado inteligente
* Tabla de estadísticas en administración
* Cambio de nombre a ygb-sfood y shortcode `[ygb_sfood]`

= 1.0 =
* Versión inicial con búsqueda por lista, checkboxes, añadir seleccionados y añadir uno a uno.

== Upgrade Notice ==

= 5.16.0 =
Añade la plantilla "Lista de Compras" seleccionable desde el editor. Crea una página nueva y asígnale esa plantilla para mostrar el buscador sin cabeceras ni elementos del tema.

= 5.15.2 =
Correcciones de seguridad y comportamiento. Actualización recomendada.

= 2.0 =
Actualización mayor con nuevas funcionalidades: cantidades, listas guardadas, autocompletado y estadísticas.

== Credits ==

Desarrollado por YGB.