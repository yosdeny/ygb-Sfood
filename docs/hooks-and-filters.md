# Hooks y Filtros Disponibles - YGB SFood

Este documento describe todos los hooks (acciones) y filtros disponibles en el plugin **YGB SFood** versión 5.15.0+.

## 📌 Filtros (Filters)

Los filtros permiten modificar datos antes de ser procesados o mostrados.

### `ygb_sfood_search_cache_time`
Modifica el tiempo de caché para las búsquedas de productos.

- **Desde:** 5.15.0
- **Parámetros:**
  - `$cache_time` (int): Tiempo en segundos. Default: `180`
- **Retorna:** (int) Nuevo tiempo de caché en segundos

**Ejemplo de uso:**
```php
add_filter('ygb_sfood_search_cache_time', function($cache_time) {
    // Extender caché a 10 minutos
    return 600;
});
```

---

### `ygb_sfood_suggestions_cache_time`
Modifica el tiempo de caché para las sugerencias de autocompletado.

- **Desde:** 5.15.0
- **Parámetros:**
  - `$cache_time` (int): Tiempo en segundos. Default: `300`
- **Retorna:** (int) Nuevo tiempo de caché en segundos

**Ejemplo de uso:**
```php
add_filter('ygb_sfood_suggestions_cache_time', function($cache_time) {
    // Extender caché a 1 hora
    return 3600;
});
```

---

## 🔔 Acciones (Actions)

Las acciones permiten ejecutar código personalizado en momentos específicos del flujo del plugin.

### `ygb_sfood_search_cache_hit`
Se ejecuta cuando una búsqueda utiliza resultados en caché (no consulta la BD).

- **Desde:** 5.15.0
- **Parámetros:**
  - `$input` (string): Término buscado
  - `$cached` (array): Datos retornados desde caché

**Ejemplo de uso:**
```php
add_action('ygb_sfood_search_cache_hit', function($input, $cached) {
    error_log("Búsqueda en caché: '{$input}' con " . count($cached['productos']) . " productos");
}, 10, 2);
```

---

### `ygb_sfood_search_completed`
Se ejecuta cuando se completa una búsqueda (sin usar caché).

- **Desde:** 5.15.0
- **Parámetros:**
  - `$input` (string): Término buscado
  - `$response` (array): Resultados de la búsqueda con productos encontrados

**Ejemplo de uso:**
```php
add_action('ygb_sfood_search_completed', function($input, $response) {
    // Registrar búsquedas populares en un log externo
    if (count($response['productos']) > 0) {
        do_some_analytics($input, count($response['productos']));
    }
}, 10, 2);
```

---

### `ygb_sfood_suggestions_cache_hit`
Se ejecuta cuando las sugerencias usan datos en caché.

- **Desde:** 5.15.0
- **Parámetros:**
  - `$term` (string): Término para autocompletado
  - `$cached` (array): Lista de sugerencias en caché

**Ejemplo de uso:**
```php
add_action('ygb_sfood_suggestions_cache_hit', function($term, $cached) {
    // Monitoreo de uso de caché
    update_option('ygb_suggestions_cache_hits', get_option('ygb_suggestions_cache_hits', 0) + 1);
}, 10, 2);
```

---

### `ygb_sfood_suggestions_generated`
Se ejecuta cuando se generan nuevas sugerencias (sin caché).

- **Desde:** 5.15.0
- **Parámetros:**
  - `$term` (string): Término para autocompletado
  - `$sug` (array): Lista de sugerencias generadas

**Ejemplo de uso:**
```php
add_action('ygb_sfood_suggestions_generated', function($term, $sug) {
    // Analizar términos frecuentes sin caché
    if (empty($sug)) {
        // Término sin resultados - posible oportunidad de nuevo producto
        log_missed_search_term($term);
    }
}, 10, 2);
```

---

## 📝 Notas de Uso

1. **Prioridad:** Todos los hooks usan prioridad `10` por defecto. Puedes cambiarla según necesites.
2. **Parámetros:** Presta atención al número de parámetros aceptados por cada hook (especificado en el cuarto argumento de `add_action`/`add_filter`).
3. **Rendimiento:** Los filtros de caché permiten optimizar el plugin según tu tráfico. Valores más altos mejoran rendimiento pero pueden mostrar datos menos actualizados.
4. **Depuración:** Usa las acciones `*_cache_hit` y `*_completed` para medir efectividad del caché.

---

## 🧪 Ejemplo Completo: Monitor de Búsquedas

```php
/**
 * Sistema simple de analytics para búsquedas del plugin YGB SFood
 */

// Contador de búsquedas totales
add_action('ygb_sfood_search_completed', function($input, $response) {
    $total = get_option('ygb_search_total', 0);
    update_option('ygb_search_total', $total + 1);
    
    // Registrar término más buscado
    $terms = get_option('ygb_search_terms', []);
    $terms[$input] = ($terms[$input] ?? 0) + 1;
    update_option('ygb_search_terms', $terms);
}, 10, 2);

// Ajustar caché según tipo de término
add_filter('ygb_sfood_search_cache_time', function($cache_time, $input) {
    // Términos genéricos: caché más largo
    if (strlen($input) < 5) {
        return 600; // 10 minutos
    }
    // Términos específicos: caché más corto
    return 120; // 2 minutos
}, 10, 1);

// Alerta cuando no hay resultados
add_action('ygb_sfood_search_completed', function($input, $response) {
    if (empty($response['productos'])) {
        // Enviar notificación al admin
        wp_mail(
            get_option('admin_email'),
            'Búsqueda sin resultados: ' . $input,
            'Un usuario buscó "' . $input . '" pero no se encontraron productos.'
        );
    }
}, 20, 2);
```

---

## 📊 Métricas Recomendadas

Puedes usar estos hooks para implementar:

- ✅ Analytics de búsquedas más frecuentes
- ✅ Detección de productos faltantes (búsquedas sin resultados)
- ✅ Optimización de tiempos de caché según patrones de uso
- ✅ Logs de auditoría para debugging
- ✅ Integración con herramientas externas (Google Analytics, Mixpanel, etc.)

---

**Autor:** YGB  
**Plugin:** ygb-sfood (Lista de Compras)  
**Versión documentada:** 5.15.0  
**Última actualización:** 2024
