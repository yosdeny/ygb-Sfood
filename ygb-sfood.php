<?php
/**
 * Plugin Name: YGB-SFood
 * Plugin URI: https://url/lista-de-compras/
 * Description: Buscador de alimentos con controles +- en cantidad, admin-ajax, colores personalizables, responsive.
 * Version: 6.1.0
 * Author: YGB
 * Author URI: https://github.com/yosdeny
 * Requires at least: 7.0
 * Tested up to: 7.1.2
 * Requires PHP: 8.0
 * Tested PHP: 8.2
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ygb-sfood
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

class YGB_SFood {

    private const PAGE_META_KEY   = '_ygb_sfood_page';
    private const PAGE_OPTION_KEY = 'ygb_sfood_page_id';
    private const PAGE_SLUG       = 'lista-de-compras';
    private const MAX_ITEMS       = 50;

    private string $table_name;
    private wpdb $db;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ygb_sfood_busquedas';
        $this->db         = $wpdb;

        register_activation_hook(__FILE__, [$this, 'activar']);
        register_deactivation_hook(__FILE__, [$this, 'desactivar']);

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_action('admin_post_ygb_create_page', [$this, 'handle_create_page']);
        add_shortcode('ygb_sfood', [$this, 'shortcode']);
        add_shortcode('ygb_sfood_link', [$this, 'shortcode_link']);

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

    public function activar(): void {
        $this->crear_tabla();
        $this->set_default_colors();
        $this->maybe_create_page();
        flush_rewrite_rules(false);
    }

    public function desactivar(): void {
        flush_rewrite_rules(false);
    }

    public function init(): void {
        load_plugin_textdomain(
            'ygb-sfood',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    private function crear_tabla(): void {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            termino VARCHAR(255) NOT NULL,
            productos_encontrados INT DEFAULT 0,
            productos_agregados INT DEFAULT 0,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$this->db->get_charset_collate()};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // =====================================================================
    // Página automática
    // =====================================================================

    /**
     * Devuelve la página del buscador si existe; null si no.
     *
     * Prioridad:
     *  1) Por ID guardado en opción (rápido).
     *  2) Por meta key `_ygb_sfood_page`.
     *  3) Por slug `lista-de-compras`.
     */
    public function find_sfood_page(): ?WP_Post {
        // 1) Opción.
        $id = (int) get_option(self::PAGE_OPTION_KEY, 0);
        if ($id > 0) {
            $page = get_post($id);
            if ($page instanceof WP_Post && $page->post_type === 'page' && $page->post_status !== 'trash') {
                return $page;
            }
            // La opción apunta a algo que ya no existe: límpiala.
            delete_option(self::PAGE_OPTION_KEY);
        }

        // 2) Por meta key.
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'all',
            'meta_key'       => self::PAGE_META_KEY,
            'meta_value'     => '1',
            'no_found_rows'  => true,
        ]);
        if (!empty($pages)) {
            $page = $pages[0];
            update_option(self::PAGE_OPTION_KEY, $page->ID);
            return $page;
        }

        // 3) Por slug.
        $page = get_page_by_path(self::PAGE_SLUG);
        if ($page instanceof WP_Post && $page->post_status !== 'trash') {
            update_post_meta($page->ID, self::PAGE_META_KEY, '1');
            update_option(self::PAGE_OPTION_KEY, $page->ID);
            return $page;
        }

        return null;
    }

    /**
     * Crea la página del buscador si no existe.
     * Devuelve el ID de la página (existente o nueva) o 0 si falla.
     */
    public function maybe_create_page(): int {
        $existing = $this->find_sfood_page();
        if ($existing instanceof WP_Post) {
            return $existing->ID;
        }

        $page_id = wp_insert_post([
            'post_title'   => __('Lista de Compras', 'ygb-sfood'),
            'post_name'    => self::PAGE_SLUG,
            'post_content' => '[ygb_sfood]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id() ?: 1,
            'meta_input'   => [
                self::PAGE_META_KEY => '1',
            ],
        ], true);

        if (is_wp_error($page_id) || !$page_id) {
            return 0;
        }

        update_option(self::PAGE_OPTION_KEY, (int) $page_id);
        return (int) $page_id;
    }

    /**
     * Handler admin: recrear la página bajo demanda.
     * Ruta: admin-post.php?action=ygb_create_page
     */
    public function handle_create_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permisos insuficientes.', 'ygb-sfood'));
        }
        check_admin_referer('ygb_create_page');

        $page_id = $this->maybe_create_page();

        $redirect = add_query_arg(
            [
                'page'      => 'ygb-sfood',
                'ygb_notice' => $page_id > 0 ? 'page_ok' : 'page_fail',
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    // =====================================================================
    // Colores
    // =====================================================================

    private function get_theme_colors(): array {
        $theme_colors = [];

        $bg_color = get_theme_mod('background_color');
        if ($bg_color) {
            $theme_colors['background'] = '#' . ltrim((string) $bg_color, '#');
        }
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

    private function get_default_colors(): array {
        $theme = $this->get_theme_colors();

        return [
            'ygb_button_bg'             => $theme['primary'] ?? '#ffffff',
            'ygb_button_text'           => '#61ce70',
            'ygb_button_border'         => '#61ce70',
            'ygb_button_bg_hover'       => $theme['accent'] ?? '#ffffff',
            'ygb_button_text_hover'     => '#E26143',
            'ygb_button_border_hover'   => '#61ce70',
            'ygb_button_radius'         => '6px',
            'ygb_results_bg'            => $theme['background'] ?? '#f2f2f2',
            'ygb_sidebar_bg'            => '#f2f2f2',
            'ygb_sidebar_text'          => '#333333',
            'ygb_input_bg'              => '#ffffff',
            'ygb_input_text'            => '#333333',
            'ygb_input_border'          => '#cccccc',
            'ygb_product_bg'            => '#ffffff',
            'ygb_product_text'          => '#333333',
            'ygb_price_color'           => '#dd3333',
            'ygb_quantity_bg'           => '#ffffff',
            'ygb_quantity_text'         => '#333333',
            'ygb_quantity_button_bg'    => '#f0f0f0',
            'ygb_quantity_button_text'  => '#333333',
            'ygb_checkbox_color'        => '#61CE70',
            'ygb_link_color'            => '#E26143',
            'ygb_header_bg'             => '#f2f2f2',
            'ygb_header_text'           => '#333333',
        ];
    }

    private function set_default_colors(): void {
        foreach ($this->get_default_colors() as $key => $value) {
            if (false === get_option($key)) {
                update_option($key, $value);
            }
        }
    }

    public function admin_enqueue_scripts(string $hook): void {
        if (!str_contains($hook, 'ygb-sfood')
            && !str_contains($hook, 'ygb-personalizar')
            && !str_contains($hook, 'ygb-estadisticas')) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script(
            'wp-color-picker',
            '(function($){ $(function(){ $(".ygb-color-field").wpColorPicker(); }); })(jQuery);'
        );
    }

    // =====================================================================
    // Admin
    // =====================================================================

    public function admin_menu(): void {
        add_menu_page(
            'YGB-SFood',
            'YGB-SFood',
            'manage_options',
            'ygb-sfood',
            [$this, 'admin_dashboard'],
            'dashicons-search',
            30
        );
        add_submenu_page('ygb-sfood', __('Estadísticas', 'ygb-sfood'), __('Estadísticas', 'ygb-sfood'), 'manage_options', 'ygb-estadisticas', [$this, 'admin_estadisticas']);
        add_submenu_page('ygb-sfood', __('Personalizar', 'ygb-sfood'), __('Personalizar', 'ygb-sfood'), 'manage_options', 'ygb-personalizar', [$this, 'admin_personalizar']);
    }

    public function admin_dashboard(): void {
        $page = $this->find_sfood_page();
        $notice = isset($_GET['ygb_notice']) ? sanitize_key(wp_unslash($_GET['ygb_notice'])) : '';

        echo '<div class="wrap"><h1>' . esc_html__('YGB-SFood', 'ygb-sfood') . '</h1>';

        if ($notice === 'page_ok') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Página del buscador creada correctamente.', 'ygb-sfood') . '</p></div>';
        } elseif ($notice === 'page_fail') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('No se pudo crear la página del buscador.', 'ygb-sfood') . '</p></div>';
        }

        if ($page instanceof WP_Post) {
            echo '<div class="notice notice-success inline"><p>';
            echo sprintf(
                /* translators: %s: enlace a la página del buscador. */
                esc_html__('Página del buscador activa: %s.', 'ygb-sfood'),
                '<a href="' . esc_url((string) get_permalink($page->ID)) . '" target="_blank" rel="noopener">' . esc_html(get_the_title($page)) . '</a>'
            );
            echo ' &nbsp; ';
            echo sprintf(
                /* translators: %s: enlace a la edición de la página. */
                esc_html__('Editar: %s', 'ygb-sfood'),
                '<a href="' . esc_url((string) get_edit_post_link($page->ID)) . '">' . esc_html__('abrir en el editor', 'ygb-sfood') . '</a>'
            );
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__('No existe la página del buscador. Puedes crearla con el botón de abajo.', 'ygb-sfood');
            echo '</p></div>';

            $create_url = wp_nonce_url(
                admin_url('admin-post.php?action=ygb_create_page'),
                'ygb_create_page'
            );
            echo '<p><a href="' . esc_url($create_url) . '" class="button button-primary">' . esc_html__('Crear página "Lista de Compras"', 'ygb-sfood') . '</a></p>';
        }

        echo '<div class="notice notice-info inline"><p>';
        echo sprintf(
            /* translators: %s: shortcode. */
            esc_html__('También puedes mostrar el buscador pegando el shortcode %s en cualquier página o entrada.', 'ygb-sfood'),
            '<code>[ygb_sfood]</code>'
        );
        echo '</p></div>';
        echo '</div>';
    }

    public function admin_estadisticas(): void {
        $datos = $this->db->get_results(
            $this->db->prepare("SELECT * FROM {$this->table_name} ORDER BY fecha DESC LIMIT %d", 100)
        );
        echo '<div class="wrap"><h1>' . esc_html__('Estadísticas', 'ygb-sfood') . '</h1>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<th>' . esc_html__('ID', 'ygb-sfood') . '</th>';
        echo '<th>' . esc_html__('Término', 'ygb-sfood') . '</th>';
        echo '<th>' . esc_html__('Encontrados', 'ygb-sfood') . '</th>';
        echo '<th>' . esc_html__('Agregados', 'ygb-sfood') . '</th>';
        echo '<th>' . esc_html__('Fecha', 'ygb-sfood') . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($datos)) {
            echo '<tr><td colspan="5">' . esc_html__('No hay registros aún.', 'ygb-sfood') . '</td></tr>';
        } else {
            foreach ($datos as $fila) {
                echo '<tr>';
                echo '<td>' . esc_html($fila->id) . '</td>';
                echo '<td>' . esc_html($fila->termino) . '</td>';
                echo '<td>' . esc_html($fila->productos_encontrados) . '</td>';
                echo '<td>' . esc_html($fila->productos_agregados) . '</td>';
                echo '<td>' . esc_html($fila->fecha) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    public function admin_personalizar(): void {
        if (isset($_POST['ygb_save_colors']) && check_admin_referer('ygb_colors_nonce')) {
            $color_fields = [
                'ygb_button_bg', 'ygb_button_text', 'ygb_button_border',
                'ygb_button_bg_hover', 'ygb_button_text_hover', 'ygb_button_border_hover',
                'ygb_results_bg', 'ygb_sidebar_bg', 'ygb_sidebar_text',
                'ygb_input_bg', 'ygb_input_text', 'ygb_input_border',
                'ygb_product_bg', 'ygb_product_text', 'ygb_price_color',
                'ygb_quantity_bg', 'ygb_quantity_text', 'ygb_quantity_button_bg',
                'ygb_quantity_button_text', 'ygb_checkbox_color', 'ygb_link_color',
                'ygb_header_bg', 'ygb_header_text',
            ];
            foreach ($color_fields as $field) {
                if (!isset($_POST[$field])) {
                    continue;
                }
                $val = sanitize_hex_color(wp_unslash((string) $_POST[$field]));
                if (null !== $val) {
                    update_option($field, $val);
                }
            }
            if (isset($_POST['ygb_button_radius'])) {
                $radius = sanitize_text_field(wp_unslash((string) $_POST['ygb_button_radius']));
                if (preg_match('/^\d+(?:\.\d+)?(?:px|em|rem|%)?$/', $radius)) {
                    update_option('ygb_button_radius', $radius);
                }
            }
            echo '<div class="notice notice-success"><p>' . esc_html__('Colores guardados.', 'ygb-sfood') . '</p></div>';
        }

        $defaults = $this->get_default_colors();
        $options  = [];
        foreach ($defaults as $key => $value) {
            $options[$key] = get_option($key, $value);
        }
        ?>
        <div class="wrap"><h1><?php echo esc_html__('Personalizar colores del plugin', 'ygb-sfood'); ?></h1>
        <p><?php echo esc_html__('Colores base actuales: Botón (fondo #ffffff, texto #61ce70, borde #61ce70), Hover (fondo #ffffff, texto #E26143, borde #61ce70), Precio (#dd3333)', 'ygb-sfood'); ?></p>
        <form method="post">
            <?php wp_nonce_field('ygb_colors_nonce'); ?>
            <h2><?php echo esc_html__('Botones Principales', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo botón', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_bg" value="<?php echo esc_attr($options['ygb_button_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto botón', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_text" value="<?php echo esc_attr($options['ygb_button_text']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Borde botón', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_border" value="<?php echo esc_attr($options['ygb_button_border']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo hover', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_bg_hover" value="<?php echo esc_attr($options['ygb_button_bg_hover']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto hover', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_text_hover" value="<?php echo esc_attr($options['ygb_button_text_hover']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Borde hover', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_border_hover" value="<?php echo esc_attr($options['ygb_button_border_hover']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Radio borde', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_button_radius" value="<?php echo esc_attr($options['ygb_button_radius']); ?>" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Fondos y Contenedores', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo resultados', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_results_bg" value="<?php echo esc_attr($options['ygb_results_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo sidebar', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_sidebar_bg" value="<?php echo esc_attr($options['ygb_sidebar_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto sidebar', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_sidebar_text" value="<?php echo esc_attr($options['ygb_sidebar_text']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo cabecera', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_header_bg" value="<?php echo esc_attr($options['ygb_header_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto cabecera', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_header_text" value="<?php echo esc_attr($options['ygb_header_text']); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Campos de Entrada', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo input', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_input_bg" value="<?php echo esc_attr($options['ygb_input_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto input', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_input_text" value="<?php echo esc_attr($options['ygb_input_text']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Borde input', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_input_border" value="<?php echo esc_attr($options['ygb_input_border']); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Productos', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo producto', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_product_bg" value="<?php echo esc_attr($options['ygb_product_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto producto', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_product_text" value="<?php echo esc_attr($options['ygb_product_text']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Color precio', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_price_color" value="<?php echo esc_attr($options['ygb_price_color']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Color checkbox', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_checkbox_color" value="<?php echo esc_attr($options['ygb_checkbox_color']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Color enlace', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_link_color" value="<?php echo esc_attr($options['ygb_link_color']); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <h2><?php echo esc_html__('Controles de Cantidad', 'ygb-sfood'); ?></h2>
            <table class="form-table">
                <tr><th><?php echo esc_html__('Fondo cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_bg" value="<?php echo esc_attr($options['ygb_quantity_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_text" value="<?php echo esc_attr($options['ygb_quantity_text']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Fondo botones cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_button_bg" value="<?php echo esc_attr($options['ygb_quantity_button_bg']); ?>" class="ygb-color-field" /></td></tr>
                <tr><th><?php echo esc_html__('Texto botones cantidad', 'ygb-sfood'); ?></th><td><input type="text" name="ygb_quantity_button_text" value="<?php echo esc_attr($options['ygb_quantity_button_text']); ?>" class="ygb-color-field" /></td></tr>
            </table>
            <p class="submit"><input type="submit" name="ygb_save_colors" class="button-primary" value="<?php echo esc_attr__('Guardar Colores', 'ygb-sfood'); ?>" /></p>
        </form></div>
        <?php
    }

    // =====================================================================
    // Shortcode
    // =====================================================================

    private function maybe_init_wc_ajax(): void {
        if (!function_exists('WC') || !WC()) {
            return;
        }
        if (is_null(WC()->session) && method_exists(WC(), 'initialize_session')) {
            WC()->initialize_session();
        }
        if (is_null(WC()->cart)) {
            WC()->cart = new WC_Cart();
        }
    }

    public function shortcode(): string {
        if (!class_exists('WooCommerce')) {
            return '<p>' . esc_html__('WooCommerce no activo.', 'ygb-sfood') . '</p>';
        }

        $ajax_url = admin_url('admin-ajax.php');
        $nonce    = wp_create_nonce('ygb_nonce');
        $is_logged = is_user_logged_in() ? 'yes' : 'no';

        $defaults = $this->get_default_colors();
        $c = [];
        foreach ($defaults as $key => $value) {
            $c[$key] = get_option($key, $value);
        }

        ob_start();
        ?>
        <style>
            #ygb-app { font-family: sans-serif; max-width: 900px; margin: 0 auto; padding: 5px 0; }
            .ygb-layout { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-start; }
            .ygb-main { flex: 1; min-width: 300px; }
            .ygb-sidebar { width: 220px; background: <?php echo esc_attr($c['ygb_sidebar_bg']); ?>; padding: 10px; border-radius: 6px; color: <?php echo esc_attr($c['ygb_sidebar_text']); ?>; }
            .ygb-sidebar h4 { margin: 0 0 8px 0; font-size: 16px; color: <?php echo esc_attr($c['ygb_sidebar_text']); ?>; }
            #ygb-app input[type="text"] { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid <?php echo esc_attr($c['ygb_input_border']); ?>; border-radius: 4px; background: <?php echo esc_attr($c['ygb_input_bg']); ?>; color: <?php echo esc_attr($c['ygb_input_text']); ?>; }
            .ygb-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 10px 0; }
            #ygb-buscar, #ygb-guardar, #ygb-agregar-top, #ygb-agregar { height: 38px; line-height: 38px; padding: 0 15px; cursor: pointer; background-color: <?php echo esc_attr($c['ygb_button_bg']); ?>; color: <?php echo esc_attr($c['ygb_button_text']); ?>; border: 1px solid <?php echo esc_attr($c['ygb_button_border']); ?>; border-radius: <?php echo esc_attr($c['ygb_button_radius']); ?>; }
            #ygb-buscar:hover, #ygb-guardar:hover, #ygb-agregar-top:hover, #ygb-agregar:hover { background-color: <?php echo esc_attr($c['ygb_button_bg_hover']); ?>; color: <?php echo esc_attr($c['ygb_button_text_hover']); ?>; border-color: <?php echo esc_attr($c['ygb_button_border_hover']); ?>; }
            .ygb-producto { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #eee; flex-wrap: wrap; background: <?php echo esc_attr($c['ygb_product_bg']); ?>; color: <?php echo esc_attr($c['ygb_product_text']); ?>; }
            .ygb-col-checkbox { width: 30px; flex-shrink: 0; text-align: center; }
            .ygb-col-checkbox input[type="checkbox"] { accent-color: <?php echo esc_attr($c['ygb_checkbox_color']); ?>; }
            .ygb-col-imagen { width: 50px; flex-shrink: 0; }
            .ygb-col-info { flex: 2; }
            .ygb-col-cantidad { width: 110px; flex-shrink: 0; text-align: center; }
            .ygb-col-accion { width: 110px; flex-shrink: 0; text-align: center; }
            .ygb-producto img { width: 50px; height: 50px; object-fit: cover; border-radius: 6px; display: block; }
            .ygb-info { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
            .ygb-info strong { font-weight: bold; font-size: 14px; color: <?php echo esc_attr($c['ygb_product_text']); ?>; }
            .ygb-info .product-price { color: <?php echo esc_attr($c['ygb_price_color']); ?>; font-size: 13px; }
            .ygb-info a { color: <?php echo esc_attr($c['ygb_link_color']); ?>; text-decoration: none; }
            .ygb-info a:hover { text-decoration: underline; }
            .ygb-quantity-control { display: inline-flex; align-items: center; gap: 4px; background: <?php echo esc_attr($c['ygb_quantity_bg']); ?>; border: 1px solid <?php echo esc_attr($c['ygb_input_border']); ?>; border-radius: 4px; height: 38px; box-sizing: border-box; overflow: hidden; }
            .ygb-quantity-control button { width: 30px; height: 36px; background: <?php echo esc_attr($c['ygb_quantity_button_bg']); ?>; color: <?php echo esc_attr($c['ygb_quantity_button_text']); ?>; border: none; cursor: pointer; font-size: 18px; font-weight: bold; line-height: 1; margin: 0; padding: 0; border-radius: 0; transition: background 0.2s; }
            .ygb-quantity-control button:hover { background: <?php echo esc_attr($c['ygb_button_bg_hover']); ?>; color: <?php echo esc_attr($c['ygb_button_text_hover']); ?>; }
            .ygb-quantity-control .ygb-cantidad { width: 45px; height: 36px; text-align: center; border: none; border-left: 1px solid <?php echo esc_attr($c['ygb_input_border']); ?>; border-right: 1px solid <?php echo esc_attr($c['ygb_input_border']); ?>; margin: 0; padding: 0; font-size: 14px; box-sizing: border-box; -moz-appearance: textfield; background: <?php echo esc_attr($c['ygb_quantity_bg']); ?>; color: <?php echo esc_attr($c['ygb_quantity_text']); ?>; }
            .ygb-quantity-control .ygb-cantidad::-webkit-inner-spin-button,
            .ygb-quantity-control .ygb-cantidad::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
            .ygb-quantity-control button:active { background: <?php echo esc_attr($c['ygb_button_bg']); ?>; }
            .ygb-quantity-control button:disabled, .ygb-cantidad:disabled { background: #f5f5f5; color: #aaa; cursor: not-allowed; }
            .ygb-accion, .ygb-add-one { display: inline-block; white-space: nowrap; height: 38px; line-height: 38px; padding: 0 12px; border-radius: <?php echo esc_attr($c['ygb_button_radius']); ?>; text-decoration: none; cursor: pointer; box-sizing: border-box; }
            button.ygb-add-one { background-color: <?php echo esc_attr($c['ygb_button_bg']); ?>; color: <?php echo esc_attr($c['ygb_button_text']); ?>; border: 1px solid <?php echo esc_attr($c['ygb_button_border']); ?>; }
            button.ygb-add-one:hover { background-color: <?php echo esc_attr($c['ygb_button_bg_hover']); ?>; color: <?php echo esc_attr($c['ygb_button_text_hover']); ?>; border-color: <?php echo esc_attr($c['ygb_button_border_hover']); ?>; }
            span.ygb-accion { border: 1px solid transparent; background: transparent; color: #c00; }
            .ygb-lista-item { display: block; background: <?php echo esc_attr($c['ygb_sidebar_bg']); ?>; padding: 5px 8px; margin: 3px 0; border-radius: 4px; cursor: pointer; font-size: 13px; position: relative; color: <?php echo esc_attr($c['ygb_sidebar_text']); ?>; }
            .ygb-lista-item .nombre { display: block; font-weight: bold; margin-bottom: 1px; }
            .ygb-lista-item .consulta { display: block; font-size: 11px; color: <?php echo esc_attr($c['ygb_sidebar_text']); ?>; opacity: 0.7; word-break: break-word; }
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
                    <div style="margin-bottom:10px;">
                        <label>Tu lista de compras mas facil que nunca.</label><br>
                        <label>No tienes que nombrar el producto completo para encontrarlo.</label><br>
                        <label>Lo mismo funciona para cada palabra separada por (,) en la busqueda.</label><br>
                        <label>Puedes especificar hasta la cantidad para cada uno.</label>
                        <input type="text" id="ygb-input" placeholder="Ej: 2 manzanas, leche, pan integral" autocomplete="off" />
                        <div id="ygb-sugerencias" style="position:relative;"></div>
                    </div>
                    <div class="ygb-controls">
                        <button id="ygb-buscar" class="button" type="button">Buscar</button>
                        <button id="ygb-guardar" class="button" type="button" style="display:none;">Guardar lista</button>
                        <button id="ygb-agregar-top" class="button" type="button" style="display:none;margin-left:10px;">Añadir seleccionados al carrito</button>
                        <span id="ygb-loader" style="display:none;margin-left:10px;">Buscando...</span>
                    </div>
                    <div id="ygb-resultados" style="display:none;">
                        <h3 style="margin:10px 0;">Resultados</h3>
                        <div id="ygb-lista"></div>
                        <button id="ygb-agregar" class="button" type="button" style="margin-top:10px;display:none;">Añadir seleccionados al carrito</button>
                        <span id="ygb-msg" style="margin-left:10px;"></span>
                    </div>
                </div>
                <div class="ygb-sidebar" id="ygb-sidebar" style="display:none;">
                    <h4>Tus listas</h4>
                    <div id="ygb-listas-container" style="max-height:300px;overflow-y:auto;"></div>
                </div>
            </div>
        </div>
        <script>
        jQuery(function($) {
            var ygbConfig = <?php echo wp_json_encode([
                'ajaxurl' => $ajax_url,
                'nonce'   => $nonce,
                'logged'  => $is_logged,
            ]); ?>;
            var ajaxurl = ygbConfig.ajaxurl, nonce = ygbConfig.nonce, productos = [], carritoEstado = {};

            function escapeHtml(str) {
                if (str === null || str === undefined) return '';
                return String(str).replace(/[&<>"']/g, function(m) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
                });
            }

            function refreshCartFragments() {
                if (typeof wc_add_to_cart_params === 'undefined') return;
                $.ajax({
                    url: wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_refreshed_fragments'),
                    type: 'POST',
                    success: function(r) {
                        if (r && r.fragments) {
                            $.each(r.fragments, function(k, v) { $(k).replaceWith(v); });
                            $(document.body).trigger('wc_fragments_refreshed');
                        }
                    }
                });
            }

            function actualizarEstadoCarrito() {
                $.post(ajaxurl, {action: 'ygb_estado_carrito', nonce: nonce}, function(res) {
                    if (!res.success) return;
                    var nuevoEstado = res.data;
                    $('.ygb-producto').each(function() {
                        var $prod = $(this);
                        var $checkbox = $prod.find('.ygb-check');
                        var id = parseInt($checkbox.val(), 10);
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
                                var prodData = productos.find(function(p) { return p.id == id; });
                                var cantidadOriginal = prodData ? prodData.cantidad : 1;
                                $prod.find('.ygb-cantidad').val(cantidadOriginal).prop('disabled', false);
                                $prod.find('.ygb-quantity-control button').prop('disabled', false);
                                $prod.find('.ygb-accion').replaceWith('<button type="button" class="ygb-add-one ygb-accion" data-id="' + id + '">Añadir</button>');
                            }
                        } else if (enCarritoAhora && enCarritoAntes && nuevoEstado[id] !== carritoEstado[id]) {
                            $prod.find('.ygb-cantidad').val(nuevoEstado[id]);
                        }
                    });
                    carritoEstado = nuevoEstado;
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

            function actualizarBotonesLote() {
                var haySeleccionables = $('.ygb-check:not(:disabled)').length > 0;
                if (haySeleccionables) {
                    $('#ygb-agregar-top').show();
                    $('#ygb-agregar').show();
                } else {
                    $('#ygb-agregar-top').hide();
                    $('#ygb-agregar').hide();
                }
            }

            function realizarBusqueda(query) {
                $('#ygb-sugerencias').empty().hide();
                $('#ygb-loader').show();
                $('#ygb-resultados').hide();
                $.post(ajaxurl, {action: 'ygb_buscar', query: query, nonce: nonce}, function(res) {
                    $('#ygb-loader').hide();
                    if (res.success) {
                        productos = res.data.productos;
                        guardarEstadoInicial(res.data);
                        renderProductos();
                        $('#ygb-resultados').show();
                        actualizarBotonesLote();
                    } else {
                        $('#ygb-msg').text(res.data || 'Error en la búsqueda').css('color', 'red').show();
                    }
                });
            }

            $('#ygb-buscar').on('click', function() {
                $('#ygb-sugerencias').empty().hide();
                var q = $('#ygb-input').val().trim();
                if (!q) { alert('Escribe algo.'); return; }
                realizarBusqueda(q);
            });

            $('#ygb-input').on('keypress', function(e) {
                if (e.which === 13) { e.preventDefault(); $('#ygb-buscar').trigger('click'); }
            });

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
                    var btnIndividual;
                    if (enCarrito) {
                        btnIndividual = '<span class="ygb-accion">Ya en carrito</span>';
                    } else if (!enStock) {
                        btnIndividual = '<span class="ygb-accion" style="color:red;">Agotado</span>';
                    } else {
                        btnIndividual = '<button type="button" class="ygb-add-one ygb-accion" data-id="' + p.id + '">Añadir</button>';
                    }
                    var nombreSeguro = escapeHtml(p.nombre);
                    var imgSeguro = escapeHtml(p.imagen);
                    var precioHtml = p.precio;
                    var quantityControl;
                    if (!enCarrito && enStock) {
                        quantityControl = '<div class="ygb-quantity-control">' +
                            '<button type="button" class="ygb-qty-minus" data-id="' + p.id + '">-</button>' +
                            '<input type="number" class="ygb-cantidad" data-id="' + p.id + '" value="' + qtyVal + '" min="1" step="1" />' +
                            '<button type="button" class="ygb-qty-plus" data-id="' + p.id + '">+</button>' +
                            '</div>';
                    } else if (!enStock) {
                        quantityControl = '<div class="ygb-quantity-control" style="text-align:center;width:40px;"><input type="number" class="ygb-cantidad" value="0" min="0" disabled style="text-align:center;" /></div>';
                    } else {
                        quantityControl = '<input type="number" class="ygb-cantidad" data-id="' + p.id + '" value="' + qtyVal + '" min="0" disabled />';
                    }
                    html += '<div class="ygb-producto">';
                    html += '<div class="ygb-col-checkbox"><input type="checkbox" class="ygb-check" value="' + p.id + '" ' + (enCarrito || !enStock ? 'disabled' : '') + ' /></div>';
                    html += '<div class="ygb-col-imagen"><img src="' + imgSeguro + '" alt="' + nombreSeguro + '" /></div>';
                    html += '<div class="ygb-col-cantidad">' + quantityControl + '</div>';
                    html += '<div class="ygb-col-accion">' + btnIndividual + '</div>';
                    html += '<div class="ygb-col-info"><div class="ygb-info"><strong>' + nombreSeguro + '</strong><span class="product-price">' + precioHtml + '</span></div></div>';
                    html += '</div>';
                });
                $('#ygb-lista').html(html);
                actualizarBotonesLote();
            }

            $(document).on('click', '.ygb-qty-plus', function() {
                var $input = $(this).closest('.ygb-quantity-control').find('.ygb-cantidad');
                if ($input.prop('disabled')) return;
                $input.val((parseInt($input.val(), 10) || 1) + 1).trigger('change');
            });
            $(document).on('click', '.ygb-qty-minus', function() {
                var $input = $(this).closest('.ygb-quantity-control').find('.ygb-cantidad');
                if ($input.prop('disabled')) return;
                var val = parseInt($input.val(), 10) || 1;
                if (val > 1) $input.val(val - 1).trigger('change');
            });

            $(document).on('click', '#ygb-agregar, #ygb-agregar-top', function() {
                var $botones = $('#ygb-agregar, #ygb-agregar-top');
                var ids = [], qtys = [];
                $('.ygb-check:checked:not(:disabled)').each(function() {
                    var id = parseInt($(this).val(), 10);
                    ids.push(id);
                    qtys.push(parseInt($('.ygb-cantidad[data-id="' + id + '"]').val(), 10) || 1);
                });
                if (!ids.length) { alert('Selecciona productos.'); return; }
                $botones.prop('disabled', true);
                $.post(ajaxurl, {action: 'ygb_agregar', ids: ids, qtys: qtys, nonce: nonce}, function(res) {
                    if (res.success) {
                        $('#ygb-msg').text(res.data.mensaje).css('color', 'green').show();
                        refreshCartFragments();
                        actualizarEstadoCarrito();
                    } else {
                        alert(res.data);
                    }
                }).always(function() { $botones.prop('disabled', false); });
            });

            $(document).on('click', '.ygb-add-one', function() {
                var id = $(this).data('id');
                var qty = parseInt($('.ygb-cantidad[data-id="' + id + '"]').val(), 10) || 1;
                var btn = $(this);
                btn.prop('disabled', true).text('Agregando...');
                $.post(ajaxurl, {action: 'ygb_agregar', ids: [id], qtys: [qty], nonce: nonce}, function(res) {
                    if (res.success) {
                        btn.replaceWith('<span class="ygb-accion">Ya en carrito</span>');
                        $('.ygb-check[value="' + id + '"]').prop('checked', true).prop('disabled', true);
                        $('.ygb-cantidad[data-id="' + id + '"]').prop('disabled', true);
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
                var term = val.substring(lastComma + 1).trim();
                if (term.length < 2) { $('#ygb-sugerencias').empty().hide(); return; }
                timer = setTimeout(function() {
                    $.post(ajaxurl, {action: 'ygb_sugerencias', term: term, nonce: nonce}, function(res) {
                        if (res.success && res.data.length) {
                            var html = '<div style="position:absolute;background:white;border:1px solid #ccc;z-index:10;width:100%;">';
                            res.data.forEach(function(item) {
                                html += '<div class="ygb-sug-item" data-val="' + escapeHtml(item.value) + '" style="padding:5px;cursor:pointer;">' + escapeHtml(item.label) + '</div>';
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
                var inicio = lastComma >= 0 ? val.substring(0, lastComma + 1) + ' ' : '';
                var termSeguro = $(this).data('val');
                $('#ygb-input').val(inicio + termSeguro);
                $('#ygb-sugerencias').empty().hide();
            });

            if (ygbConfig.logged === 'yes') {
                $('#ygb-guardar').show();
                cargarListas();
            }

            $('#ygb-guardar').on('click', function() {
                var q = $('#ygb-input').val().trim();
                if (!q) return;
                var nom = prompt('Nombre para la lista (opcional):');
                if (nom === null) return;
                $.post(ajaxurl, {action: 'ygb_guardar', query: q, nombre: nom ? nom : '', nonce: nonce}, function(res) {
                    if (res.success) mostrarListas(res.data.listas);
                    else alert(res.data);
                });
            });

            function cargarListas() {
                $.post(ajaxurl, {action: 'ygb_cargar', nonce: nonce}, function(res) {
                    if (res.success && res.data.listas.length) mostrarListas(res.data.listas);
                });
            }

            function mostrarListas(listas) {
                var h = '';
                listas.forEach(function(it, idx) {
                    h += '<div class="ygb-lista-item" data-index="' + idx + '" data-consulta="' + escapeHtml(it.consulta) + '">';
                    h += '<span class="nombre">' + escapeHtml(it.nombre || it.consulta) + '</span>';
                    h += '<span class="consulta">' + escapeHtml(it.consulta) + '</span>';
                    h += '<span class="eliminar">&times;</span>';
                    h += '</div>';
                });
                $('#ygb-listas-container').html(h);
                $('#ygb-sidebar').show();
            }

            $(document).on('click', '.ygb-lista-item .eliminar', function(e) {
                e.stopPropagation();
                var idx = $(this).parent().data('index');
                $.post(ajaxurl, {action: 'ygb_eliminar', index: idx, nonce: nonce}, function(res) {
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
                $('#ygb-buscar').trigger('click');
            });
        });
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Devuelve la URL de la página del buscador si existe; si no, la home.
     */
    private function get_buscador_url(): string {
        $page = $this->find_sfood_page();
        if ($page instanceof WP_Post) {
            $permalink = get_permalink($page->ID);
            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }
        return home_url('/');
    }

    public function shortcode_link($atts): string {
        $atts = shortcode_atts(['texto' => 'Buscar alimentos', 'class' => ''], $atts);
        $url  = $this->get_buscador_url();
        return '<a href="' . esc_url($url) . '" class="' . esc_attr($atts['class']) . '">' . esc_html($atts['texto']) . '</a>';
    }

    // =====================================================================
    // AJAX
    // =====================================================================

    private function check_ajax(): void {
        check_ajax_referer('ygb_nonce', 'nonce', true);
        $this->maybe_init_wc_ajax();
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(__('WooCommerce no activo.', 'ygb-sfood'));
        }
    }

    private function get_cart_product_ids(): array {
        $ids = [];
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $ids[] = (int) $item['product_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    private function enrich_with_cart_status(array $payload, array $cart_ids): array {
        if (isset($payload['productos']) && is_array($payload['productos'])) {
            foreach ($payload['productos'] as &$p) {
                $p['en_carrito'] = in_array((int) $p['id'], $cart_ids, true);
            }
            unset($p);
        }
        return $payload;
    }

    public function buscar(): void {
        $this->check_ajax();
        $input = sanitize_text_field(wp_unslash((string) ($_POST['query'] ?? '')));
        if (empty($input)) {
            wp_send_json_error(__('Sin búsqueda.', 'ygb-sfood'));
        }

        $cache_time = (int) apply_filters('ygb_sfood_search_cache_time', 180);
        $cache_key  = 'ygb_sfood_search_' . md5($input);
        $cached     = get_transient($cache_key);
        $cart_ids   = $this->get_cart_product_ids();

        if (is_array($cached) && isset($cached['productos'])) {
            $response = $this->enrich_with_cart_status($cached, $cart_ids);
            do_action('ygb_sfood_search_cache_hit', $input, $response);
            wp_send_json_success($response);
        }

        $items = $this->parsear($input);
        if (empty($items)) {
            wp_send_json_error(__('Ingresa alimentos.', 'ygb-sfood'));
        }

        $resultados = [];
        $cantidades = [];
        foreach ($items as $item) {
            $productos = wc_get_products([
                'status' => 'publish',
                'limit'  => 5,
                's'      => $item['nombre'],
                'type'   => 'simple',
            ]);
            foreach ($productos as $p) {
                $pid = $p->get_id();
                if (!isset($resultados[$pid])) {
                    $resultados[$pid] = [
                        'id'       => $pid,
                        'nombre'   => $p->get_name(),
                        'precio'   => $p->get_price_html(),
                        'imagen'   => wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail'),
                        'in_stock' => $p->is_in_stock(),
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
        unset($data);

        if (empty($resultados)) {
            $this->log($input, 0, 0);
            wp_send_json_error(__('No encontrado.', 'ygb-sfood'));
        }

        $this->log($input, count($resultados), 0);

        $cache_payload = ['productos' => array_values($resultados)];
        if ($cache_time > 0) {
            set_transient($cache_key, $cache_payload, $cache_time);
        }

        $response = $this->enrich_with_cart_status($cache_payload, $cart_ids);
        do_action('ygb_sfood_search_completed', $input, $response);

        wp_send_json_success($response);
    }

    public function agregar(): void {
        $this->check_ajax();

        $raw_ids = isset($_POST['ids']) && is_array($_POST['ids']) ? wp_unslash($_POST['ids']) : [];
        $raw_qty = isset($_POST['qtys']) && is_array($_POST['qtys']) ? wp_unslash($_POST['qtys']) : [];
        $ids     = array_values(array_filter(array_map('intval', $raw_ids)));
        $qtys    = array_map('intval', $raw_qty);

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
                /* translators: %d: number of units added. */
                _n('%d unidad añadida.', '%d unidades añadidas.', $agregados, 'ygb-sfood'),
                $agregados
            );
            if ($rechazados > 0) {
                $mensaje .= ' ' . sprintf(
                    /* translators: %d: number of unavailable products. */
                    _n('%d producto no disponible.', '%d productos no disponibles.', $rechazados, 'ygb-sfood'),
                    $rechazados
                );
            }
            wp_send_json_success(['mensaje' => $mensaje]);
        } else {
            wp_send_json_error(__('Ninguno de los productos seleccionados está disponible.', 'ygb-sfood'));
        }
    }

    public function sugerencias(): void {
        $this->check_ajax();
        $term = sanitize_text_field(wp_unslash((string) ($_POST['term'] ?? '')));
        if (empty($term)) {
            wp_send_json_success([]);
        }

        $cache_time = (int) apply_filters('ygb_sfood_suggestions_cache_time', 300);
        $cache_key  = 'ygb_sfood_suggestions_' . md5($term);
        $cached     = get_transient($cache_key);

        if (is_array($cached)) {
            do_action('ygb_sfood_suggestions_cache_hit', $term, $cached);
            wp_send_json_success($cached);
        }

        $productos = wc_get_products([
            'status' => 'publish',
            'limit'  => 5,
            's'      => $term,
            'type'   => 'simple',
        ]);
        $sug = [];
        foreach ($productos as $p) {
            $sug[] = [
                'label' => $p->get_name(),
                'value' => $p->get_name(),
            ];
        }

        if ($cache_time > 0) {
            set_transient($cache_key, $sug, $cache_time);
        }

        do_action('ygb_sfood_suggestions_generated', $term, $sug);

        wp_send_json_success($sug);
    }

    public function guardar_lista(): void {
        check_ajax_referer('ygb_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Inicia sesión.', 'ygb-sfood'));
        }
        $query = sanitize_text_field(wp_unslash((string) ($_POST['query'] ?? '')));
        if (empty($query)) {
            wp_send_json_error(__('Sin lista.', 'ygb-sfood'));
        }
        $nombre = sanitize_text_field(wp_unslash((string) ($_POST['nombre'] ?? '')));
        if (empty($nombre)) {
            $nombre = $query;
        }
        $user_id = get_current_user_id();
        $listas  = get_user_meta($user_id, 'ygb_listas', true);
        if (!is_array($listas)) {
            $listas = [];
        }
        $listas = array_map(static function ($i) {
            return is_array($i) ? $i : ['nombre' => $i, 'consulta' => $i];
        }, $listas);
        $listas[] = ['nombre' => $nombre, 'consulta' => $query];
        update_user_meta($user_id, 'ygb_listas', $listas);
        wp_send_json_success(['listas' => $listas]);
    }

    public function cargar_listas(): void {
        check_ajax_referer('ygb_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(__('Inicia sesión.', 'ygb-sfood'));
        }
        $listas = get_user_meta(get_current_user_id(), 'ygb_listas', true);
        if (!is_array($listas)) {
            $listas = [];
        }
        $listas = array_map(static function ($i) {
            return is_array($i) ? $i : ['nombre' => $i, 'consulta' => $i];
        }, $listas);
        wp_send_json_success(['listas' => $listas]);
    }

    public function eliminar_lista(): void {
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

    public function estado_carrito(): void {
        $this->check_ajax();
        $estado = [];
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $estado[(int) $item['product_id']] = (int) $item['quantity'];
            }
        }
        wp_send_json_success($estado);
    }

    private function parsear(string $input): array {
        $partes = preg_split('/\s*,\s*|\s+[yY]\s+/u', $input);
        $items  = [];
        foreach ($partes as $parte) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
            $parte = trim($parte);
            if ($parte === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s+(.+)/u', $parte, $m)) {
                $items[] = [
                    'nombre'   => trim($m[2]),
                    'cantidad' => max(1, (int) $m[1]),
                ];
            } else {
                $items[] = [
                    'nombre'   => $parte,
                    'cantidad' => 1,
                ];
            }
        }
        return $items;
    }

    private function log(string $termino, int $enc, int $agr): void {
        $this->db->insert($this->table_name, [
            'termino'                => $termino !== '' ? $termino : '--',
            'productos_encontrados'  => $enc,
            'productos_agregados'    => $agr,
            'fecha'                  => current_time('mysql'),
        ]);
    }
}

new YGB_SFood();