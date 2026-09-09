<?php
/**
 * Plugin Name: ygb-sfood
 * Plugin URI: https://url/lista-de-compras/
 * Description: Buscador de alimentos con controles +- en cantidad, admin-ajax, colores personalizables, responsive.
 * Version: 5.15.0
 * Author: YGB
 * Author URI: https://github.com/yosdeny
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Tested PHP: 8.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ygb-sfood
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class YGB_SFood {

    private $table_name;
    private $db;
    private $blank_slug = 'lista-de-compras';
    private const MAX_ITEMS = 50;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ygb_sfood_busquedas';
        $this->db = $wpdb;

        register_activation_hook(__FILE__, [$this, 'activar']);
        register_deactivation_hook(__FILE__, [$this, 'desactivar']);

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_filter('template_include', [$this, 'template_blank']);
        add_shortcode('ygb_sfood', [$this, 'shortcode']);
        add_shortcode('ygb_sfood_link', [$this, 'shortcode_link']);

        // AJAX nativo (evita bloqueos WAF/404 de REST API)
        add_action('wp_ajax_ygb_buscar', [$this, 'buscar']);
        add_action('wp_ajax_nopriv_ygb_buscar', [$this, 'buscar']);
        add_action('wp_ajax_ygb_agregar', [$this, 'agregar']);
        add_action('wp_ajax_nopriv_ygb_agregar', [$this, 'agregar']);
        add_action('wp_ajax_ygb_sugerencias', [$this, 'sugerencias']);
        add_action('wp_ajax_nopriv_ygb_sugerencias', [$this, 'sugerencias']);
        add_action('wp_ajax_ygb_guardar', [$this, 'guardar_lista']);
        add_action('wp_ajax_ygb_cargar', [$this, 'cargar_listas']);
        add_action('wp_ajax_ygb_eliminar', [$this, 'eliminar_lista']);
        add_action('wp_ajax_ygb_estado_carrito', [$this, 'estado_carrito']);
        add_action('wp_ajax_nopriv_ygb_estado_carrito', [$this, 'estado_carrito']);
    }

    public function activar() {
        $this->crear_tabla();
        $this->agregar_rewrite_rule();
        $this->set_default_colors();
        flush_rewrite_rules();
    }

    public function desactivar() {
        flush_rewrite_rules();
    }

    private function crear_tabla() {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            termino VARCHAR(255) NOT NULL,
            productos_encontrados INT DEFAULT 0,
            productos_agregados INT DEFAULT 0,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$this->db->get_charset_collate()};";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function get_theme_colors() {
        $theme_colors = [];
        
        // Intentar obtener colores del Customizer del tema activo
        $bg_color = get_theme_mod('background_color');
        if ($bg_color) {
            $theme_colors['background'] = '#' . $bg_color;
        }
        
        // Colores comunes en diferentes temas
        $primary_color = get_theme_mod('primary_color', get_theme_mod('color_primary', ''));
        if ($primary_color) {
            $theme_colors['primary'] = $primary_color;
        }
        
        $accent_color = get_theme_mod('accent_color', get_theme_mod('color_accent', ''));
        if ($accent_color) {
            $theme_colors['accent'] = $accent_color;
        }
        
        $link_color = get_theme_mod('link_color', '');
        if ($link_color) {
            $theme_colors['link'] = $link_color;
        }
        
        return $theme_colors;
    }

    private function set_default_colors() {
        $theme_colors = $this->get_theme_colors();
        
        // Colores base proporcionados
        $base_verde = '#61CE70';
        $base_naranja = '#E26143';
        $base_dorado = '#dd9933';
        $base_gris = '#f2f2f2';
        
        // Usar colores del tema si están disponibles, sino usar defaults
        $default_button_bg = isset($theme_colors['primary']) ? $theme_colors['primary'] : $base_verde;
        $default_button_bg_hover = isset($theme_colors['accent']) ? $theme_colors['accent'] : $base_naranja;
        $default_results_bg = isset($theme_colors['background']) ? $theme_colors['background'] : $base_gris;
        
        if (false === get_option('ygb_button_bg')) {
            update_option('ygb_button_bg', $default_button_bg);
            update_option('ygb_button_text', '#ffffff');
            update_option('ygb_button_border', $default_button_bg);
            update_option('ygb_button_bg_hover', $default_button_bg_hover);
            update_option('ygb_button_text_hover', '#ffffff');
            update_option('ygb_button_border_hover', $default_button_bg_hover);
            update_option('ygb_button_radius', '6px');
            update_option('ygb_results_bg', $default_results_bg);
            update_option('ygb_sidebar_bg', $base_gris);
            update_option('ygb_sidebar_text', '#333333');
            update_option('ygb_input_bg', '#ffffff');
            update_option('ygb_input_text', '#333333');
            update_option('ygb_input_border', '#cccccc');
            update_option('ygb_product_bg', '#ffffff');
            update_option('ygb_product_text', '#333333');
            update_option('ygb_price_color', '#555555');
            update_option('ygb_quantity_bg', '#ffffff');
            update_option('ygb_quantity_text', '#333333');
            update_option('ygb_quantity_button_bg', '#f0f0f0');
            update_option('ygb_quantity_button_text', '#333333');
            update_option('ygb_checkbox_color', $base_verde);
            update_option('ygb_link_color', $base_naranja);
            update_option('ygb_header_bg', $base_gris);
            update_option('ygb_header_text', '#333333');
        }
    }

    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'ygb-personalizar') === false && strpos($hook, 'ygb-sfood') === false) return;
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('wp-color-picker', '(function($){ $(document).ready(function(){ $(".ygb-color-field").wpColorPicker(); }); })(jQuery);');
    }

    public function init() {
        $this->agregar_rewrite_rule();
    }

    private function agregar_rewrite_rule() {
        add_rewrite_rule('^' . $this->blank_slug . '/?$', 'index.php?ygb_blank=1', 'top');
        add_rewrite_tag('%ygb_blank%', '([1])');
    }

    public function template_blank($template) {
        if (get_query_var('ygb_blank') == '1') {
            $plugin_template = plugin_dir_path(__FILE__) . 'templates/blank.php';
            if (file_exists($plugin_template)) return $plugin_template;
            // Fallback seguro
            get_header();
            echo '<div id="primary" class="content-area"><main id="main" class="site-main">', do_shortcode('[ygb_sfood]'), '</main></div>';
            get_footer();
            exit;
        }
        return $template;
    }

    public function admin_menu() {
        add_menu_page('ygb-sfood', 'ygb-sfood', 'manage_options', 'ygb-sfood', [$this, 'admin_dashboard'], 'dashicons-search', 30);
        add_submenu_page('ygb-sfood', 'Estadísticas', 'Estadísticas', 'manage_options', 'ygb-estadisticas', [$this, 'admin_estadisticas']);
        add_submenu_page('ygb-sfood', 'Personalizar', 'Personalizar', 'manage_options', 'ygb-personalizar', [$this, 'admin_personalizar']);
    }

    public function admin_dashboard() {
        echo '<div class="wrap"><h1>' . esc_html__('ygb-sfood', 'ygb-sfood') . '</h1>';
        echo '<p>' . esc_html__('Shortcode:', 'ygb-sfood') . ' <code>[ygb_sfood]</code> | ' . esc_html__('Enlace directo:', 'ygb-sfood') . ' <a href="' . esc_url(home_url('/' . $this->blank_slug . '/')) . '" target="_blank">' . esc_html__('Abrir buscador', 'ygb-sfood') . '</a></p>';
        echo '</div>';
    }

    // ✅ Corregido: eliminado "lakang" y estructura HTML válida
    public function admin_estadisticas() {
        $datos = $this->db->get_results("SELECT * FROM {$this->table_name} ORDER BY fecha DESC LIMIT 100");
        echo '<div class="wrap"><h1>' . esc_html__('Estadísticas', 'ygb-sfood') . '</h1><table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>' . esc_html__('Término', 'ygb-sfood') . '</th><th>' . esc_html__('Encontrados', 'ygb-sfood') . '</th><th>' . esc_html__('Agregados', 'ygb-sfood') . '</th><th>' . esc_html__('Fecha', 'ygb-sfood') . '</th></tr></thead><tbody>';
        if (empty($datos)) {
            echo '<tr><td colspan="5">' . esc_html__('No hay registros aún.', 'ygb-sfood') . '</td></tr>';
        } else {
            foreach ($datos as $fila) {
                echo "<tr><td>" . esc_html($fila->id) . "</td><td>" . esc_html($fila->termino) . "</td><td>" . esc_html($fila->productos_encontrados) . "</td><td>" . esc_html($fila->productos_agregados) . "</td><td>" . esc_html($fila->fecha) . "</td></tr>";
            }
        }
        echo '</tbody></table></div>';
    }

    // ✅ Corregido: etiquetas </td> y estructura de tabla limpia
    public function admin_personalizar() {
        if (isset($_POST['ygb_save_colors']) && check_admin_referer('ygb_colors_nonce')) {
            update_option('ygb_button_bg', sanitize_hex_color($_POST['ygb_button_bg']));
            update_option('ygb_button_text', sanitize_hex_color($_POST['ygb_button_text']));
            update_option('ygb_button_border', sanitize_hex_color($_POST['ygb_button_border']));
            update_option('ygb_button_bg_hover', sanitize_hex_color($_POST['ygb_button_bg_hover']));
            update_option('ygb_button_text_hover', sanitize_hex_color($_POST['ygb_button_text_hover']));
            update_option('ygb_button_border_hover', sanitize_hex_color($_POST['ygb_button_border_hover']));
            update_option('ygb_button_radius', sanitize_text_field($_POST['ygb_button_radius']));
            update_option('ygb_results_bg', sanitize_hex_color($_POST['ygb_results_bg']));
            update_option('ygb_sidebar_bg', sanitize_hex_color($_POST['ygb_sidebar_bg']));
            update_option('ygb_sidebar_text', sanitize_hex_color($_POST['ygb_sidebar_text']));
            update_option('ygb_input_bg', sanitize_hex_color($_POST['ygb_input_bg']));
            update_option('ygb_input_text', sanitize_hex_color($_POST['ygb_input_text']));
            update_option('ygb_input_border', sanitize_hex_color($_POST['ygb_input_border']));
            update_option('ygb_product_bg', sanitize_hex_color($_POST['ygb_product_bg']));
            update_option('ygb_product_text', sanitize_hex_color($_POST['ygb_product_text']));
            update_option('ygb_price_color', sanitize_hex_color($_POST['ygb_price_color']));
            update_option('ygb_quantity_bg', sanitize_hex_color($_POST['ygb_quantity_bg']));
            update_option('ygb_quantity_text', sanitize_hex_color($_POST['ygb_quantity_text']));
            update_option('ygb_quantity_button_bg', sanitize_hex_color($_POST['ygb_quantity_button_bg']));
            update_option('ygb_quantity_button_text', sanitize_hex_color($_POST['ygb_quantity_button_text']));
            update_option('ygb_checkbox_color', sanitize_hex_color($_POST['ygb_checkbox_color']));
            update_option('ygb_link_color', sanitize_hex_color($_POST['ygb_link_color']));
            update_option('ygb_header_bg', sanitize_hex_color($_POST['ygb_header_bg']));
            update_option('ygb_header_text', sanitize_hex_color($_POST['ygb_header_text']));
            echo '<div class="notice notice-success"><p>' . esc_html__('Colores guardados.', 'ygb-sfood') . '</p></div>';
        }
        
        // Obtener colores del tema si no hay opciones guardadas
        $theme_colors = $this->get_theme_colors();
        $base_verde = '#61CE70';
        $base_naranja = '#E26143';
        $base_dorado = '#dd9933';
        $base_gris = '#f2f2f2';
        $default_button_bg = isset($theme_colors['primary']) ? $theme_colors['primary'] : $base_verde;
        $default_button_bg_hover = isset($theme_colors['accent']) ? $theme_colors['accent'] : $base_naranja;
        $default_results_bg = isset($theme_colors['background']) ? $theme_colors['background'] : $base_gris;
        
        $button_bg = get_option('ygb_button_bg', $default_button_bg);
        $button_text = get_option('ygb_button_text', '#ffffff');
        $button_border = get_option('ygb_button_border', $default_button_bg);
        $button_bg_hover = get_option('ygb_button_bg_hover', $default_button_bg_hover);
        $button_text_hover = get_option('ygb_button_text_hover', '#ffffff');
        $button_border_hover = get_option('ygb_button_border_hover', $default_button_bg_hover);
        $button_radius = get_option('ygb_button_radius', '6px');
        $results_bg = get_option('ygb_results_bg', $default_results_bg);
        $sidebar_bg = get_option('ygb_sidebar_bg', $base_gris);
        $sidebar_text = get_option('ygb_sidebar_text', '#333333');
        $input_bg = get_option('ygb_input_bg', '#ffffff');
        $input_text = get_option('ygb_input_text', '#333333');
        $input_border = get_option('ygb_input_border', '#cccccc');
        $product_bg = get_option('ygb_product_bg', '#ffffff');
        $product_text = get_option('ygb_product_text', '#333333');
        $price_color = get_option('ygb_price_color', '#555555');
        $quantity_bg = get_option('ygb_quantity_bg', '#ffffff');
        $quantity_text = get_option('ygb_quantity_text', '#333333');
        $quantity_button_bg = get_option('ygb_quantity_button_bg', '#f0f0f0');
        $quantity_button_text = get_option('ygb_quantity_button_text', '#333333');
        $checkbox_color = get_option('ygb_checkbox_color', $base_verde);
        $link_color = get_option('ygb_link_color', $base_naranja);
        $header_bg = get_option('ygb_header_bg', $base_gris);
        $header_text = get_option('ygb_header_text', '#333333');
        ?>
        <div class="wrap"><h1><?php echo esc_html__('Personalizar colores del plugin', 'ygb-sfood'); ?></h1>
        <p><?php echo esc_html__('Usa estos colores como base: #61CE70 (verde), #E26143 (naranja), #dd9933 (dorado), #f2f2f2 (gris claro)', 'ygb-sfood'); ?></p>
        <form method="post">
            <?php wp_nonce_field('ygb_colors_nonce'); ?>
            <h2><?php echo esc_html__('Botones Principales', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo botón', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_bg" value="<?php echo esc_attr($button_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto botón', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_text" value="<?php echo esc_attr($button_text); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Borde botón', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_border" value="<?php echo esc_attr($button_border); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo hover', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_bg_hover" value="<?php echo esc_attr($button_bg_hover); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto hover', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_text_hover" value="<?php echo esc_attr($button_text_hover); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Borde hover', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_border_hover" value="<?php echo esc_attr($button_border_hover); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Radio borde', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_radius" value="<?php echo esc_attr($button_radius); ?>" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Fondos y Contenedores', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo resultados', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_results_bg" value="<?php echo esc_attr($results_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo sidebar', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_sidebar_bg" value="<?php echo esc_attr($sidebar_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto sidebar', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_sidebar_text" value="<?php echo esc_attr($sidebar_text); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo cabecera', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_header_bg" value="<?php echo esc_attr($header_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto cabecera', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_header_text" value="<?php echo esc_attr($header_text); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Campos de Entrada', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo input', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_input_bg" value="<?php echo esc_attr($input_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto input', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_input_text" value="<?php echo esc_attr($input_text); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Borde input', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_input_border" value="<?php echo esc_attr($input_border); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Productos', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo producto', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_product_bg" value="<?php echo esc_attr($product_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto producto', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_product_text" value="<?php echo esc_attr($product_text); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Color precio', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_price_color" value="<?php echo esc_attr($price_color); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Color checkbox', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_checkbox_color" value="<?php echo esc_attr($checkbox_color); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Color enlace', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_link_color" value="<?php echo esc_attr($link_color); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Controles de Cantidad', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_bg" value="<?php echo esc_attr($quantity_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_text" value="<?php echo esc_attr($quantity_text); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo botones cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_button_bg" value="<?php echo esc_attr($quantity_button_bg); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto botones cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_button_text" value="<?php echo esc_attr($quantity_button_text); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <p class="submit"><input type="submit" name="ygb_save_colors" class="button-primary" value="<?php echo esc_attr__('Guardar Colores', 'ygb-sfood'); ?>" /></p>
        </form></div>
        <?php
    }

    // ✅ Inicialización segura de WC en AJAX (evita fatales en PHP 8/Woo 8+)
    private function maybe_init_wc_ajax() {
        if (class_exists('WooCommerce') && function_exists('WC') && WC()) {
            if (!WC()->session) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }
            if (!WC()->cart) {
                WC()->cart = new WC_Cart();
            }
        }
    }

    public function shortcode() {
        if (!class_exists('WooCommerce')) return '<p>WooCommerce no activo.</p>';
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('ygb_nonce');
        $is_logged = is_user_logged_in() ? 'yes' : 'no';

        // Obtener colores del tema si no hay opciones guardadas
        $theme_colors = $this->get_theme_colors();
        $base_verde = '#61CE70';
        $base_naranja = '#E26143';
        $base_dorado = '#dd9933';
        $base_gris = '#f2f2f2';
        $default_button_bg = isset($theme_colors['primary']) ? $theme_colors['primary'] : $base_verde;
        $default_button_bg_hover = isset($theme_colors['accent']) ? $theme_colors['accent'] : $base_naranja;
        $default_results_bg = isset($theme_colors['background']) ? $theme_colors['background'] : $base_gris;

        $button_bg = get_option('ygb_button_bg', $default_button_bg);
        $button_text = get_option('ygb_button_text', '#ffffff');
        $button_border = get_option('ygb_button_border', $default_button_bg);
        $button_bg_hover = get_option('ygb_button_bg_hover', $default_button_bg_hover);
        $button_text_hover = get_option('ygb_button_text_hover', '#ffffff');
        $button_border_hover = get_option('ygb_button_border_hover', $default_button_bg_hover);
        $button_radius = get_option('ygb_button_radius', '6px');
        $results_bg = get_option('ygb_results_bg', $default_results_bg);
        $sidebar_bg = get_option('ygb_sidebar_bg', $base_gris);
        $sidebar_text = get_option('ygb_sidebar_text', '#333333');
        $input_bg = get_option('ygb_input_bg', '#ffffff');
        $input_text = get_option('ygb_input_text', '#333333');
        $input_border = get_option('ygb_input_border', '#cccccc');
        $product_bg = get_option('ygb_product_bg', '#ffffff');
        $product_text = get_option('ygb_product_text', '#333333');
        $price_color = get_option('ygb_price_color', '#555555');
        $quantity_bg = get_option('ygb_quantity_bg', '#ffffff');
        $quantity_text = get_option('ygb_quantity_text', '#333333');
        $quantity_button_bg = get_option('ygb_quantity_button_bg', '#f0f0f0');
        $quantity_button_text = get_option('ygb_quantity_button_text', '#333333');
        $checkbox_color = get_option('ygb_checkbox_color', $base_verde);
        $link_color = get_option('ygb_link_color', $base_naranja);
        $header_bg = get_option('ygb_header_bg', $base_gris);
        $header_text = get_option('ygb_header_text', '#333333');

        ob_start();
        ?>
        <style>
            #ygb-app { font-family: sans-serif; max-width: 900px; margin: 0 auto; padding: 5px 0; }
            .ygb-layout { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-start; }
            .ygb-main { flex: 1; min-width: 300px; }
            .ygb-sidebar { width: 220px; background: <?php echo esc_attr($sidebar_bg); ?>; padding: 10px; border-radius: 6px; color: <?php echo esc_attr($sidebar_text); ?>; }
            .ygb-sidebar h4 { margin: 0 0 8px 0; font-size: 16px; color: <?php echo esc_attr($sidebar_text); ?>; }
            #ygb-app input[type="text"] { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid <?php echo esc_attr($input_border); ?>; border-radius: 4px; background: <?php echo esc_attr($input_bg); ?>; color: <?php echo esc_attr($input_text); ?>; }
            .ygb-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 10px 0; }
            #ygb-buscar, #ygb-guardar, #ygb-agregar-top { height: 38px; line-height: 38px; padding: 0 15px; cursor: pointer; background-color: <?php echo esc_attr($button_bg); ?>; color: <?php echo esc_attr($button_text); ?>; border: 1px solid <?php echo esc_attr($button_border); ?>; border-radius: <?php echo esc_attr($button_radius); ?>; }
            #ygb-buscar:hover, #ygb-guardar:hover, #ygb-agregar-top:hover { background-color: <?php echo esc_attr($button_bg_hover); ?>; color: <?php echo esc_attr($button_text_hover); ?>; border-color: <?php echo esc_attr($button_border_hover); ?>; }
            .ygb-producto { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #eee; flex-wrap: wrap; background: <?php echo esc_attr($product_bg); ?>; color: <?php echo esc_attr($product_text); ?>; }
            .ygb-col-checkbox { width: 30px; flex-shrink: 0; text-align: center; }
            .ygb-col-checkbox input[type="checkbox"] { accent-color: <?php echo esc_attr($checkbox_color); ?>; }
            .ygb-col-imagen { width: 50px; flex-shrink: 0; }
            .ygb-col-info { flex: 2; }
            .ygb-col-cantidad { width: 110px; flex-shrink: 0; text-align: center; }
            .ygb-col-accion { width: 110px; flex-shrink: 0; text-align: center; }
            .ygb-producto img { width: 50px; height: 50px; object-fit: cover; border-radius: 6px; display: block; }
            .ygb-info { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
            .ygb-info strong { font-weight: bold; font-size: 14px; color: <?php echo esc_attr($product_text); ?>; }
            .ygb-info .product-price { color: <?php echo esc_attr($price_color); ?>; font-size: 13px; }
            .ygb-info a { color: <?php echo esc_attr($link_color); ?>; text-decoration: none; }
            .ygb-info a:hover { text-decoration: underline; }
            .ygb-quantity-control { display: inline-flex; align-items: center; gap: 4px; background: <?php echo esc_attr($quantity_bg); ?>; border: 1px solid <?php echo esc_attr($input_border); ?>; border-radius: 4px; height: 38px; box-sizing: border-box; overflow: hidden; }
            .ygb-quantity-control button { width: 30px; height: 36px; background: <?php echo esc_attr($quantity_button_bg); ?>; color: <?php echo esc_attr($quantity_button_text); ?>; border: none; cursor: pointer; font-size: 18px; font-weight: bold; line-height: 1; margin: 0; padding: 0; border-radius: 0; transition: background 0.2s; }
            .ygb-quantity-control button:hover { background: <?php echo esc_attr($button_bg_hover); ?>; color: <?php echo esc_attr($button_text_hover); ?>; }
            .ygb-quantity-control .ygb-cantidad { width: 45px; height: 36px; text-align: center; border: none; border-left: 1px solid <?php echo esc_attr($input_border); ?>; border-right: 1px solid <?php echo esc_attr($input_border); ?>; margin: 0; padding: 0; font-size: 14px; box-sizing: border-box; -moz-appearance: textfield; background: <?php echo esc_attr($quantity_bg); ?>; color: <?php echo esc_attr($quantity_text); ?>; }
            .ygb-quantity-control .ygb-cantidad::-webkit-inner-spin-button,
            .ygb-quantity-control .ygb-cantidad::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
            .ygb-quantity-control button:active { background: <?php echo esc_attr($button_bg); ?>; }
            .ygb-quantity-control button:disabled, .ygb-cantidad:disabled { background: #f5f5f5; color: #aaa; cursor: not-allowed; }
            .ygb-accion, .ygb-add-one { display: inline-block; white-space: nowrap; height: 38px; line-height: 38px; padding: 0 12px; border-radius: <?php echo esc_attr($button_radius); ?>; text-decoration: none; cursor: pointer; box-sizing: border-box; }
            button.ygb-add-one { background-color: <?php echo esc_attr($button_bg); ?>; color: <?php echo esc_attr($button_text); ?>; border: 1px solid <?php echo esc_attr($button_border); ?>; }
            button.ygb-add-one:hover { background-color: <?php echo esc_attr($button_bg_hover); ?>; color: <?php echo esc_attr($button_text_hover); ?>; border-color: <?php echo esc_attr($button_border_hover); ?>; }
            span.ygb-accion { border: 1px solid transparent; background: transparent; color: #c00; }
            .ygb-lista-item { display: block; background: <?php echo esc_attr($sidebar_bg); ?>; padding: 5px 8px; margin: 3px 0; border-radius: 4px; cursor: pointer; font-size: 13px; position: relative; color: <?php echo esc_attr($sidebar_text); ?>; }
            .ygb-lista-item .nombre { display: block; font-weight: bold; margin-bottom: 1px; }
            .ygb-lista-item .consulta { display: block; font-size: 11px; color: <?php echo esc_attr($sidebar_text); ?>; opacity: 0.7; word-break: break-word; }
            .ygb-lista-item .eliminar { position: absolute; right: 5px; top: 4px; color: red; cursor: pointer; font-weight: bold; font-size: 13px; }
            @media (max-width: 600px) {
                .ygb-layout { flex-direction: column; }
                .ygb-sidebar { width: 100%; order: 2; margin-top: 10px; }
                .ygb-main { order: 1; }
                .ygb-producto { flex-wrap: wrap; gap: 8px; }
                .ygb-col-checkbox { order: 1; width: 30px; flex: 0 0 auto; }
                .ygb-col-imagen { order: 2; width: 50px; flex: 0 0 auto; }
                .ygb-col-cantidad { order: 3; width: auto; flex: 0 0 auto; text-align: left; }
                .ygb-col-accion { order: 4; width: auto; flex: 0 0 auto; }
                .ygb-col-info { order: 5; width: 100%; margin-top: 8px; padding-left: 40px; }
                .ygb-quantity-control { height: 36px; }
                .ygb-quantity-control button { width: 28px; height: 34px; }
                .ygb-quantity-control .ygb-cantidad { width: 40px; height: 34px; }
                #ygb-guardar, #ygb-agregar-top { display: inline-block; }
                .ygb-info { flex-wrap: wrap; gap: 6px; }
            }
        </style>
        <div id="ygb-app">
            <div class="ygb-layout">
                <div class="ygb-main">
                    <div style="margin-bottom:10px;"><label>Tu lista de compras mas facil que nunca.</label><br><label>No tienes que nombrar el producto completo para encontrarlo.</label><br><label>Lo mismo funciona para cada palabra separada por (,) en la busqueda.</label><br><label>Puedes espesificar hasta la cantidad para cada uno.</label><input type="text" id="ygb-input" placeholder="Ej: 2 manzanas, leche, pan integral" autocomplete="off" /><div id="ygb-sugerencias" style="position:relative;"></div></div>
                    <div class="ygb-controls">
                        <button id="ygb-buscar" class="button">Buscar</button>
                        <button id="ygb-guardar" class="button" style="display:none;">Guardar lista</button>
                        <button id="ygb-agregar-top" class="button" style="display:none;margin-left:10px;">Añadir seleccionados al carrito</button>
                        <span id="ygb-loader" style="display:none;margin-left:10px;">Buscando...</span>
                    </div>
                    <div id="ygb-resultados" style="display:none;">
                        <h3 style="margin:10px 0;">Resultados</h3>
                        <div id="ygb-lista"></div>
                        <button id="ygb-agregar" class="button" style="margin-top:10px;display:none;">Añadir seleccionados al carrito</button>
                        <span id="ygb-msg" style="margin-left:10px;"></span>
                    </div>
                </div>
                <div class="ygb-sidebar" id="ygb-sidebar" style="display:none;"><h4>Tus listas</h4><div id="ygb-listas-container" style="max-height:300px;overflow-y:auto;"></div></div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            var ygbConfig = <?php echo wp_json_encode(['ajaxurl'=>$ajax_url,'nonce'=>$nonce,'logged'=>$is_logged]); ?>;
            var ajaxurl = ygbConfig.ajaxurl, nonce = ygbConfig.nonce, productos = [], carritoEstado = {};

            function escapeHtml(str){ if(!str) return ''; return str.replace(/[&<>]/g,function(m){return m==='&'?'&amp;':m==='<'?'&lt;':m==='>'?'&gt;':m;}); }
            function refreshCartFragments(){ if(typeof wc_add_to_cart_params!=='undefined') $.ajax({url:wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%','get_refreshed_fragments'),type:'POST',success:function(r){if(r&&r.fragments){$.each(r.fragments,function(k,v){$(k).replaceWith(v);});$(document.body).trigger('wc_fragments_refreshed');}}}); }

            function actualizarEstadoCarrito() {
                $.post(ajaxurl, {action:'ygb_estado_carrito', nonce:nonce}, function(res) {
                    if (res.success) {
                        var nuevoEstado = res.data;
                        $('.ygb-producto').each(function() {
                            var $prod = $(this);
                            var $checkbox = $prod.find('.ygb-check');
                            var id = parseInt($checkbox.val());
                            var enCarritoAhora = nuevoEstado[id] !== undefined;
                            var enCarritoAntes = carritoEstado[id] !== undefined;

                            if (enCarritoAhora !== enCarritoAntes) {
                                if (enCarritoAhora) {
                                    $checkbox.prop('checked', true).prop('disabled', true);
                                    $prod.find('.ygb-cantidad').val(nuevoEstado[id]).prop('disabled', true);
                                    $prod.find('.ygb-quantity-control button').prop('disabled', true);
                                    $prod.find('.ygb-accion').replaceWith('<span class="ygb-accion">Ya en carrito</span>');
                                } else {
                                    $checkbox.prop('checked', false).prop('disabled', false);
                                    var prodData = productos.find(p => p.id == id);
                                    var cantidadOriginal = prodData ? prodData.cantidad : 1;
                                    $prod.find('.ygb-cantidad').val(cantidadOriginal).prop('disabled', false);
                                    $prod.find('.ygb-quantity-control button').prop('disabled', false);
                                    $prod.find('.ygb-accion').replaceWith('<button class="ygb-add-one ygb-accion" data-id="'+id+'">Añadir</button>');
                                }
                            } else if (enCarritoAhora && enCarritoAntes && nuevoEstado[id] !== carritoEstado[id]) {
                                $prod.find('.ygb-cantidad').val(nuevoEstado[id]);
                            }
                        });
                        carritoEstado = nuevoEstado;
                    }
                });
            }

            $(document.body).on('removed_from_cart updated_cart_totals wc_cart_emptied', function() {
                refreshCartFragments();
                actualizarEstadoCarrito();
            });

            function guardarEstadoInicial(data) {
                var estado = {};
                if (data && data.productos) {
                    data.productos.forEach(function(p) {
                        if (p.en_carrito) estado[p.id] = p.cantidad || 1;
                    });
                }
                carritoEstado = estado;
            }

            function realizarBusqueda(query) {
                $('#ygb-sugerencias').empty().hide();
                $('#ygb-loader').show();
                $('#ygb-resultados').hide();
                $.post(ajaxurl, {action:'ygb_buscar', query:query, nonce:nonce}, function(res) {
                    $('#ygb-loader').hide();
                    if (res.success) {
                        productos = res.data.productos;
                        guardarEstadoInicial(res.data);
                        renderProductos();
                        $('#ygb-resultados').show();
                        var haySeleccionables = $('.ygb-check:not(:disabled)').length > 0;
                        if (haySeleccionables) {
                            $('#ygb-agregar-top').show();
                            $('#ygb-agregar').show();
                        } else {
                            $('#ygb-agregar-top').hide();
                            $('#ygb-agregar').hide();
                        }
                    } else {
                        $('#ygb-msg').text(res.data || 'Error en la búsqueda').css('color','red').show();
                    }
                });
            }

            $('#ygb-buscar').click(function() {
                $('#ygb-sugerencias').empty().hide();
                var q = $('#ygb-input').val().trim();
                if (!q) return alert('Escribe algo.');
                realizarBusqueda(q);
            });

            $('#ygb-input').keypress(function(e) { if (e.which == 13) $('#ygb-buscar').click(); });
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#ygb-input, #ygb-sugerencias').length) {
                    $('#ygb-sugerencias').empty().hide();
                }
            });

            function renderProductos() {
                var html = '';
                productos.forEach(function(p) {
                    var enCarrito = p.en_carrito;
                    var enStock = p.in_stock;
                    var qtyVal = enCarrito ? p.cantidad : (enStock ? (p.cantidad || 1) : 0);
                    var btnIndividual = '';
                    if (enCarrito) {
                        btnIndividual = '<span class="ygb-accion">Ya en carrito</span>';
                    } else if (!enStock) {
                        btnIndividual = '<span class="ygb-accion" style="color:red;">Agotado</span>';
                    } else {
                        btnIndividual = '<button class="ygb-add-one ygb-accion" data-id="'+p.id+'">Añadir</button>';
                    }
                    var nombreSeguro = escapeHtml(p.nombre);
                    var precioHtml = p.precio;
                    var disabledAttr = (enCarrito || !enStock) ? 'disabled' : '';
                    var quantityControl = '';
                    if (!enCarrito && enStock) {
                        quantityControl = '<div class="ygb-quantity-control">' +
                            '<button type="button" class="ygb-qty-minus" data-id="'+p.id+'">-</button>' +
                            '<input type="number" class="ygb-cantidad" data-id="'+p.id+'" value="'+qtyVal+'" min="1" step="1" />' +
                            '<button type="button" class="ygb-qty-plus" data-id="'+p.id+'">+</button>' +
                            '</div>';
                    } else {
                        quantityControl = '<input type="number" class="ygb-cantidad" data-id="'+p.id+'" value="'+qtyVal+'" min="0" disabled />';
                    }
                    html += '<div class="ygb-producto">';
                    html += '<div class="ygb-col-checkbox"><input type="checkbox" class="ygb-check" value="'+p.id+'" '+(enCarrito || !enStock ? 'disabled' : '')+' /></div>';
                    html += '<div class="ygb-col-imagen"><img src="'+p.imagen+'" alt="'+nombreSeguro+'" /></div>';
                    html += '<div class="ygb-col-cantidad">'+quantityControl+'</div>';
                    html += '<div class="ygb-col-accion">'+btnIndividual+'</div>';
                    html += '<div class="ygb-col-info"><div class="ygb-info"><strong>'+nombreSeguro+'</strong><span class="product-price">'+precioHtml+'</span></div></div>';
                    html += '</div>';
                });
                $('#ygb-lista').html(html);
                var haySeleccionables = $('.ygb-check:not(:disabled)').length > 0;
                if (haySeleccionables) {
                    $('#ygb-agregar-top').show();
                    $('#ygb-agregar').show();
                } else {
                    $('#ygb-agregar-top').hide();
                    $('#ygb-agregar').hide();
                }
            }

            $(document).on('click', '.ygb-qty-plus', function() {
                var $container = $(this).closest('.ygb-quantity-control');
                var $input = $container.find('.ygb-cantidad');
                if ($input.prop('disabled')) return;
                var val = parseInt($input.val()) || 1;
                $input.val(val + 1).trigger('change');
            });
            $(document).on('click', '.ygb-qty-minus', function() {
                var $container = $(this).closest('.ygb-quantity-control');
                var $input = $container.find('.ygb-cantidad');
                if ($input.prop('disabled')) return;
                var val = parseInt($input.val()) || 1;
                if (val > 1) {
                    $input.val(val - 1).trigger('change');
                }
            });

            $('#ygb-agregar, #ygb-agregar-top').click(function() {
                var ids = [], qtys = [];
                $('.ygb-check:checked:not(:disabled)').each(function() {
                    var id = parseInt($(this).val());
                    ids.push(id);
                    qtys.push(parseInt($('.ygb-cantidad[data-id="'+id+'"]').val()) || 1);
                });
                if (!ids.length) { alert('Selecciona productos.'); return; }
                $.post(ajaxurl, {action:'ygb_agregar', ids:ids, qtys:qtys, nonce:nonce}, function(res) {
                    if (res.success) {
                        $('#ygb-msg').text(res.data.mensaje).css('color','green').show();
                        refreshCartFragments();
                        actualizarEstadoCarrito();
                    } else {
                        alert(res.data);
                    }
                });
            });

            $(document).on('click', '.ygb-add-one', function() {
                var id = $(this).data('id');
                var qty = parseInt($('.ygb-cantidad[data-id="'+id+'"]').val()) || 1;
                var btn = $(this);
                btn.prop('disabled', true).text('Agregando...');
                $.post(ajaxurl, {action:'ygb_agregar', ids:[id], qtys:[qty], nonce:nonce}, function(res) {
                    if (res.success) {
                        btn.replaceWith('<span class="ygb-accion">Ya en carrito</span>');
                        $('.ygb-check[value="'+id+'"]').prop('checked', true).prop('disabled', true);
                        $('.ygb-cantidad[data-id="'+id+'"]').prop('disabled', true);
                        $('.ygb-quantity-control button').prop('disabled', true);
                        refreshCartFragments();
                        actualizarEstadoCarrito();
                    } else {
                        alert(res.data);
                        btn.prop('disabled', false).text('Añadir');
                    }
                });
            });

            var timer;
            $('#ygb-input').on('input', function() {
                clearTimeout(timer);
                var val = $(this).val();
                var lastComma = val.lastIndexOf(',');
                var term = val.substring(lastComma+1).trim();
                if (term.length < 2) {
                    $('#ygb-sugerencias').empty().hide();
                    return;
                }
                timer = setTimeout(function() {
                    $.post(ajaxurl, {action:'ygb_sugerencias', term:term, nonce:nonce}, function(res) {
                        if (res.success && res.data.length) {
                            var html = '<div style="position:absolute;background:white;border:1px solid #ccc;z-index:10;width:100%;">';
                            res.data.forEach(function(item) {
                                html += '<div class="ygb-sug-item" data-val="'+escapeHtml(item.value)+'" style="padding:5px;cursor:pointer;">'+escapeHtml(item.label)+'</div>';
                            });
                            html += '</div>';
                            $('#ygb-sugerencias').html(html).show();
                        } else {
                            $('#ygb-sugerencias').empty().hide();
                        }
                    });
                }, 300);
            });

            $(document).on('click', '.ygb-sug-item', function() {
                var val = $('#ygb-input').val();
                var lastComma = val.lastIndexOf(',');
                var inicio = lastComma >= 0 ? val.substring(0, lastComma+1)+' ' : '';
                var termSeguro = $(this).data('val');
                $('#ygb-input').val(inicio + termSeguro);
                $('#ygb-sugerencias').empty().hide();
            });

            if (ygbConfig.logged === 'yes') {
                $('#ygb-guardar').show();
                cargarListas();
            }

            $('#ygb-guardar').click(function() {
                var q = $('#ygb-input').val().trim();
                if (!q) return;
                var nom = prompt('Nombre para la lista (opcional):');
                if (nom === null) return;
                $.post(ajaxurl, {action:'ygb_guardar', query:q, nombre:nom?nom:'', nonce:nonce}, function(res) {
                    if (res.success) mostrarListas(res.data.listas);
                    else alert(res.data);
                });
            });

            function cargarListas() {
                $.post(ajaxurl, {action:'ygb_cargar', nonce:nonce}, function(res) {
                    if (res.success && res.data.listas.length) mostrarListas(res.data.listas);
                });
            }

            function mostrarListas(listas) {
                var h = '';
                listas.forEach(function(it, idx) {
                    h += '<div class="ygb-lista-item" data-index="'+idx+'" data-consulta="'+escapeHtml(it.consulta)+'">';
                    h += '<span class="nombre">'+escapeHtml(it.nombre || it.consulta)+'</span>';
                    h += '<span class="consulta">'+escapeHtml(it.consulta)+'</span>';
                    h += '<span class="eliminar">&times;</span>';
                    h += '</div>';
                });
                $('#ygb-listas-container').html(h);
                $('#ygb-sidebar').show();
            }

            $(document).on('click', '.ygb-lista-item .eliminar', function(e) {
                e.stopPropagation();
                var idx = $(this).parent().data('index');
                $.post(ajaxurl, {action:'ygb_eliminar', index:idx, nonce:nonce}, function(res) {
                    if (res.success) {
                        if (res.data.listas.length) mostrarListas(res.data.listas);
                        else {
                            $('#ygb-listas-container').empty();
                            $('#ygb-sidebar').hide();
                        }
                    }
                });
            });

            $(document).on('click', '.ygb-lista-item', function(e) {
                if ($(e.target).hasClass('eliminar')) return;
                var consulta = $(this).data('consulta');
                $('#ygb-input').val(consulta);
                $('#ygb-buscar').click();
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    public function shortcode_link($atts) {
        $atts = shortcode_atts(['texto'=>'Buscar alimentos','class'=>''],$atts);
        $url = home_url('/' . $this->blank_slug . '/');
        return '<a href="'.esc_url($url).'" class="'.esc_attr($atts['class']).'" target="_blank">'.esc_html($atts['texto']).'</a>';
    }

    private function check_ajax() {
        check_ajax_referer('ygb_nonce', 'nonce', true);
        $this->maybe_init_wc_ajax();
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(__('WooCommerce no activo.', 'ygb-sfood'));
        }
    }

    public function buscar() {
        $this->check_ajax();
        $input = sanitize_text_field($_POST['query'] ?? '');
        if (empty($input)) {
            wp_send_json_error(__('Sin búsqueda.', 'ygb-sfood'));
        }
        
        /**
         * Filtro: Modificar tiempo de caché para búsquedas de productos.
         * 
         * @since 5.15.0
         * @param int $cache_time Tiempo en segundos. Default 180.
         * @return int
         */
        $cache_time = apply_filters('ygb_sfood_search_cache_time', 180);
        $cache_key = 'ygb_sfood_search_' . md5($input);
        $cached = get_transient($cache_key);
        
        if (false !== $cached) {
            /**
             * Acción: Se usó caché en búsqueda.
             * 
             * @since 5.15.0
             * @param string $input Término buscado.
             * @param array $cached Datos en caché.
             */
            do_action('ygb_sfood_search_cache_hit', $input, $cached);
            wp_send_json_success($cached);
        }
        
        $items = $this->parsear($input);
        if (empty($items)) {
            wp_send_json_error(__('Ingresa alimentos.', 'ygb-sfood'));
        }

        $cart_ids = [];
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $cart_ids[] = $item['product_id'];
            }
        }
        $cart_ids = array_unique($cart_ids);

        $resultados = [];
        $cantidades = [];
        foreach ($items as $item) {
            $productos = wc_get_products([
                'status' => 'publish',
                'limit' => 5,
                's' => $item['nombre'],
                'type' => 'simple'
            ]);
            foreach ($productos as $p) {
                $pid = $p->get_id();
                if (!isset($resultados[$pid])) {
                    $resultados[$pid] = [
                        'id' => $pid,
                        'nombre' => $p->get_name(),
                        'precio' => $p->get_price_html(),
                        'imagen' => wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                        'en_carrito' => in_array($pid, $cart_ids, true),
                        'in_stock' => $p->is_in_stock()
                    ];
                }
                if (!isset($cantidades[$pid]) || $item['cantidad'] > $cantidades[$pid]) {
                    $cantidades[$pid] = $item['cantidad'];
                }
            }
        }
        foreach ($resultados as $pid => &$data) {
            $data['cantidad'] = $cantidades[$pid] ?? 1;
        }
        if (empty($resultados)) {
            $this->log($input, 0, 0);
            wp_send_json_error(__('No encontrado.', 'ygb-sfood'));
        }
        $this->log($input, count($resultados), 0);
        
        $response = ['productos' => array_values($resultados)];
        
        set_transient($cache_key, $response, $cache_time);
        
        /**
         * Acción: Búsqueda completada (sin caché).
         * 
         * @since 5.15.0
         * @param string $input Término buscado.
         * @param array $response Resultados de la búsqueda.
         */
        do_action('ygb_sfood_search_completed', $input, $response);
        
        wp_send_json_success($response);
    }

    public function agregar() {
        $this->check_ajax();
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $qtys = array_map('intval', $_POST['qtys'] ?? []);
        $ids = array_filter($ids);
        if (empty($ids)) {
            wp_send_json_error(__('Selecciona productos.', 'ygb-sfood'));
        }

        $agregados = 0;
        $rechazados = 0;
        foreach ($ids as $i => $pid) {
            $producto = wc_get_product($pid);
            if ($producto && $producto->is_purchasable() && $producto->is_in_stock()) {
                $qty = isset($qtys[$i]) ? max(1, $qtys[$i]) : 1;
                $cart_item_key = WC()->cart->add_to_cart($pid, $qty);
                if ($cart_item_key) {
                    $agregados += $qty;
                } else {
                    $rechazados++;
                }
            } else {
                $rechazados++;
            }
        }

        if ($agregados > 0) {
            $this->log('', 0, $agregados);
            $mensaje = sprintf(
                _n('%d unidad añadida.', '%d unidades añadidas.', $agregados, 'ygb-sfood'),
                $agregados
            );
            if ($rechazados > 0) {
                $mensaje .= ' ' . sprintf(
                    _n('%d producto no disponible.', '%d productos no disponibles.', $rechazados, 'ygb-sfood'),
                    $rechazados
                );
            }
            wp_send_json_success(['mensaje' => $mensaje]);
        } else {
            wp_send_json_error(__('Ninguno de los productos seleccionados está disponible.', 'ygb-sfood'));
        }
    }

    public function sugerencias() {
        $this->check_ajax();
        $term = sanitize_text_field($_POST['term'] ?? '');
        if (empty($term)) {
            wp_send_json_success([]);
        }
        
        /**
         * Filtro: Modificar tiempo de caché para sugerencias.
         * 
         * @since 5.15.0
         * @param int $cache_time Tiempo en segundos. Default 300.
         * @return int
         */
        $cache_time = apply_filters('ygb_sfood_suggestions_cache_time', 300);
        $cache_key = 'ygb_sfood_suggestions_' . md5($term);
        $cached = get_transient($cache_key);
        
        if (false !== $cached) {
            /**
             * Acción: Se usó caché en sugerencias.
             * 
             * @since 5.15.0
             * @param string $term Término buscado.
             * @param array $cached Datos en caché.
             */
            do_action('ygb_sfood_suggestions_cache_hit', $term, $cached);
            wp_send_json_success($cached);
        }
        
        $productos = wc_get_products([
            'status' => 'publish',
            'limit' => 5,
            's' => $term,
            'type' => 'simple'
        ]);
        $sug = [];
        foreach ($productos as $p) {
            $sug[] = [
                'label' => $p->get_name(),
                'value' => $p->get_name()
            ];
        }
        
        set_transient($cache_key, $sug, $cache_time);
        
        /**
         * Acción: Sugerencias generadas (sin caché).
         * 
         * @since 5.15.0
         * @param string $term Término buscado.
         * @param array $sug Lista de sugerencias.
         */
        do_action('ygb_sfood_suggestions_generated', $term, $sug);
        
        wp_send_json_success($sug);
    }

    public function guardar_lista() {
        check_ajax_referer('ygb_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Inicia sesión.', 'ygb-sfood'));
        }
        $query = sanitize_text_field($_POST['query'] ?? '');
        if (empty($query)) {
            wp_send_json_error(__('Sin lista.', 'ygb-sfood'));
        }
        $nombre = sanitize_text_field($_POST['nombre'] ?? '');
        if (empty($nombre)) {
            $nombre = $query;
        }
        $user_id = get_current_user_id();
        $listas = get_user_meta($user_id, 'ygb_listas', true);
        if (!is_array($listas)) {
            $listas = [];
        }
        $listas = array_map(function($i) {
            return is_array($i) ? $i : ['nombre' => $i, 'consulta' => $i];
        }, $listas);
        $listas[] = ['nombre' => $nombre, 'consulta' => $query];
        update_user_meta($user_id, 'ygb_listas', $listas);
        wp_send_json_success(['listas' => $listas]);
    }

    public function cargar_listas() {
        check_ajax_referer('ygb_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Inicia sesión.', 'ygb-sfood'));
        }
        $listas = get_user_meta(get_current_user_id(), 'ygb_listas', true);
        if (!is_array($listas)) {
            $listas = [];
        }
        $listas = array_map(function($i) {
            return is_array($i) ? $i : ['nombre' => $i, 'consulta' => $i];
        }, $listas);
        wp_send_json_success(['listas' => $listas]);
    }

    public function eliminar_lista() {
        check_ajax_referer('ygb_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Inicia sesión.', 'ygb-sfood'));
        }
        $index = intval($_POST['index'] ?? -1);
        $listas = get_user_meta(get_current_user_id(), 'ygb_listas', true);
        if (!is_array($listas)) {
            wp_send_json_error(__('No hay listas.', 'ygb-sfood'));
        }
        if (isset($listas[$index])) {
            array_splice($listas, $index, 1);
            update_user_meta(get_current_user_id(), 'ygb_listas', $listas);
            wp_send_json_success(['listas' => $listas]);
        }
        wp_send_json_error(__('Índice inválido.', 'ygb-sfood'));
    }

    public function estado_carrito() {
        $this->check_ajax();
        $estado = [];
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $estado[$item['product_id']] = $item['quantity'];
            }
        }
        wp_send_json_success($estado);
    }

    private function parsear($input) {
        $partes = preg_split('/[,y]+/u', $input);
        $items = [];
        foreach ($partes as $parte) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
            $parte = trim($parte);
            if (empty($parte)) {
                continue;
            }
            if (preg_match('/^(\d+)\s+(.+)/u', $parte, $m)) {
                $items[] = [
                    'nombre' => trim($m[2]),
                    'cantidad' => max(1, intval($m[1]))
                ];
            } else {
                $items[] = [
                    'nombre' => trim($parte),
                    'cantidad' => 1
                ];
            }
        }
        return $items;
    }

    private function log($termino, $enc, $agr) {
        $this->db->insert($this->table_name, [
            'termino' => $termino ?: '--',
            'productos_encontrados' => $enc,
            'productos_agregados' => $agr,
            'fecha' => current_time('mysql')
        ]);
    }
}
new YGB_SFood();