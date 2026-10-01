=== ygb-sfood - Buscador de Alimentos por Lista ===
Contributors: yosdeny
Tags: buscador, alimentos, woocommerce, lista de compra, bulk order, carrito, cantidades, autocompletado, recetas
Requires at least: 7.0
Tested up to: 7.1.2
Stable tag: 6.0.1
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
* **Shortcode simple**: Inserta `[ygb_sfood]` en cualquier página o entrada y el buscador estará listo.
* **Personalización de colores**: 22 opciones independientes para ajustar el aspecto del buscador al tema de tu tienda.

= Requisitos =

* WordPress 7.0 o superior.
* WooCommerce instalado y activo.
* PHP 8.0 o superior.

== Installation ==

1. Sube la carpeta `ygb-sfood` al directorio `/wp-content/plugins/` de tu instalación de WordPress.
2. Activa el plugin desde el menú "Plugins" del escritorio de WordPress.
3. Asegúrate de que WooCommerce esté activo.
4. Crea una página nueva en **Páginas → Añadir nueva**.
5. Dentro del contenido, pega el shortcode `[ygb_sfood]`.
6. Publica la página.

== Usage ==

1. Ve a la página donde insertaste el shortcode `[ygb_sfood]`.
2. Escribe los nombres de los alimentos separados por comas (puedes usar cantidades, por ejemplo `2 leche, 1 pan`).
3. Si lo deseas, ajusta las cantidades individuales de cada producto.
4. Marca los productos que quieras añadir y pulsa **"Añadir seleccionados al carrito"**, o usa el botón individual de cada producto.
5. Los usuarios registrados pueden guardar la búsqueda actual con el botón **"Guardar lista"** y recuperarla más tarde.

= Shortcodes disponibles =

* `[ygb_sfood]` — Muestra el buscador completo.
* `[ygb_sfood_link texto="Buscar alimentos" class="mi-clase"]` — Genera un enlace a la primera página publicada que contenga `[ygb_sfood]`.

= Administración =

* **YGB-SFood → YGB-SFood**: Pantalla principal con el shortcode a copiar.
* **YGB-SFood → Estadísticas**: Últimas 100 búsquedas realizadas con el número de productos encontrados y agregados.
* **YGB-SFood → Personalizar**: 22 opciones de color para adaptar el buscador al tema de la tienda.

== Frequently Asked Questions ==

= ¿Es necesario WooCommerce? =
Sí, el plugin depende completamente de WooCommerce. Sin él no funcionará.

= ¿Puedo usar este plugin para productos que no sean alimentos? =
Sí, aunque está pensado para alimentos, puedes usarlo con cualquier tipo de producto. Simplemente buscará por nombre en tu tienda.

= ¿Cómo muestro el buscador en una página? =
Crea una página, pega el shortcode `[ygb_sfood]` dentro del contenido y publica. No requiere ninguna plantilla ni configuración adicional.

= ¿Qué pasa si escribo un producto que no existe? =
El sistema te avisará de que no se encontraron coincidencias. La búsqueda se registra en las estadísticas para que puedas ampliar tu catálogo si lo deseas.

= ¿Se pueden añadir cantidades diferentes para cada producto? =
Sí, cada producto tiene un campo de cantidad independiente. Además, si escribes "3 tomates" y "1 tomate" en la misma búsqueda, el plugin asignará la cantidad más alta detectada para ese producto.

= ¿Cómo se guardan las listas? =
Solo los usuarios que hayan iniciado sesión pueden guardar listas. Estas se almacenan en el perfil del usuario (user meta) y se cargan automáticamente al visitar la página.

= ¿Dónde veo las estadísticas? =
En el menú de administración de WordPress encontrarás una entrada llamada "YGB-SFood". Dentro, el submenú "Estadísticas" muestra las últimas 100 búsquedas con sus resultados.

= ¿Cómo cambio los colores del buscador? =
Ve a **YGB-SFood → Personalizar**. Ahí puedes ajustar los 22 colores que controlan botones, inputs, textos, precios, cantidades y demás elementos del buscador.

= ¿Cómo personalizo el CSS? =
El buscador usa selectores con el prefijo `#ygb-app` y `.ygb-*`. Puedes añadir tus reglas CSS desde `Apariencia → Personalizar → CSS adicional`.

== Screenshots ==

1. Interfaz principal del buscador con campo de texto, cantidad y botones.
2. Resultados de búsqueda mostrando imagen, nombre, precio, checkbox y cantidad individual.
3. Listas guardadas por el usuario listas para cargar con un clic.
4. Panel de estadísticas en el escritorio de WordPress.
5. Ejemplo de autocompletado sugiriendo productos mientras se escribe.
6. Panel de personalización de colores.

== Changelog ==

= 6.0.1 =
* AÑADIDO: Carga del textdomain con `load_plugin_textdomain()`.
* AÑADIDO: `flush_rewrite_rules()` en activación y desactivación para limpiar reglas residuales.
* CORREGIDO: Typo "espesificar" → "especificar" en la interfaz del buscador.
* MEJORADO: Comentarios `/* translators: */` en cadenas con placeholders.
* MEJORADO: `.pot` limpio, sin cadenas obsoletas.

= 6.0.0 =
* ELIMINADO: Rewrite rule `/lista-de-compras/` y plantilla `blank.php`.
* ELIMINADO: Filtros `theme_page_templates` y `template_include`.
* SIMPLIFICADO: El buscador se muestra exclusivamente mediante el shortcode `[ygb_sfood]` dentro de cualquier página o entrada.
* MEJORADO: Estructura del plugin reducida a un único fichero PHP más `languages/`.

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
* MEJORADO: Integración automática con los colores del tema activo de WordPress.
* MEJORADO: Sistema avanzado de personalización de colores (22 opciones independientes).
* AÑADIDO: Botón "Añadir seleccionados al carrito" superior.
* CORREGIDO: Espaciado y márgenes entre menú y contenido.
* CORREGIDO: Efecto hover en el botón inferior.

= 5.15.0 =
* AÑADIDO: Sistema de caché para búsquedas y sugerencias (transients API).
* AÑADIDO: Filtros `ygb_sfood_search_cache_time` y `ygb_sfood_suggestions_cache_time`.
* AÑADIDO: Acciones `ygb_sfood_search_cache_hit`, `ygb_sfood_search_completed`, `ygb_sfood_suggestions_cache_hit`, `ygb_sfood_suggestions_generated`.
* MEJORADO: Documentación completa de hooks y filtros.
* CORREGIDO: URL del plugin cambiada a 'lista-de-compras'.

= 2.0 =
* Añadida interpretación de cantidades.
* Selector de cantidad global e individual.
* Listas guardadas para usuarios registrados.
* Autocompletado inteligente.
* Tabla de estadísticas en administración.
* Cambio de nombre a ygb-sfood y shortcode `[ygb_sfood]`.

= 1.0 =
* Versión inicial con búsqueda por lista, checkboxes, añadir seleccionados y añadir uno a uno.

== Upgrade Notice ==

= 6.0.1 =
Correcciones de mantenimiento: carga del textdomain, limpieza de rewrite rules residuales y typo en la interfaz. Sin cambios funcionales.

= 6.0.0 =
Eliminado el rewrite `/lista-de-compras/` y la plantilla `blank.php`. Ahora el buscador se muestra exclusivamente con el shortcode `[ygb_sfood]` en cualquier página o entrada. Si tenías una página con la plantilla antigua, cambia su contenido por el shortcode.

= 5.15.2 =
Correcciones de seguridad y comportamiento. Actualización recomendada.

= 2.0 =
Actualización mayor con nuevas funcionalidades: cantidades, listas guardadas, autocompletado y estadísticas.

== Credits ==

Desarrollado por YGB.