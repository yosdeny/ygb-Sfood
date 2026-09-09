<?php
/**
 * Template: Blank Page for ygb-sfood
 * Fondo dinámico (usa el color configurado en Personalizar > Fondo resultados).
 * Versión: 2.1
 */

if (!defined('ABSPATH')) exit;

// Obtener el color de fondo guardado (mismo que usa #ygb-resultados)
$bg_color = get_option('ygb_results_bg', '#f9f9f9');

/**
 * 1. INYECTAR ESTILOS CON FONDO DINÁMICO
 */
add_action('wp_head', function() use ($bg_color) {
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <style>
        /* Fondo general de la página: usa el color configurado en el plugin */
        html, body, #page, .site, .site-inner, .content-area, .site-content, .main-content, .entry-content,
        .elementor-section, .fl-row, .wp-block-group, .gb-container {
            background: <?php echo esc_attr($bg_color); ?> !important;
            background-color: <?php echo esc_attr($bg_color); ?> !important;
        }
        /* Eliminar pseudo-elementos que pudieran añadir fondos extra */
        body::before, body::after, #page::before, #page::after, .site::before, .site::after {
            background: transparent !important;
        }
        /* Ocultar elementos que ensucian la vista del buscador */
        .ygb-blank-template .entry-header,
        .ygb-blank-template .entry-footer,
        .ygb-blank-template .comments-area,
        .ygb-blank-template .post-navigation,
        .ygb-blank-template .sidebar,
        .ygb-blank-template #secondary,
        .ygb-blank-template .widget-area {
            display: none !important;
        }
        /* Ajuste si la barra de admin está visible */
        .admin-bar.ygb-blank-template { padding-top: 32px; }
    </style>
    <?php
}, 1);

/**
 * 2. AÑADIR CLASES AL BODY
 */
add_filter('body_class', function($classes) {
    $classes[] = 'ygb-blank-template';
    $classes[] = 'ygb-dynamic-bg';
    return $classes;
});

/**
 * 3. LIMPIAR INTERFERENCIAS DE WOOCOMMERCE/TEMAS
 */
add_action('wp', function() {
    remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
    if (!current_user_can('manage_options')) {
        add_filter('show_admin_bar', '__return_false');
    }
});

// ==========================================================
// RENDERIZADO PRINCIPAL
// ==========================================================

if (!class_exists('WooCommerce')) {
    get_header(); ?>
    <div id="primary" class="content-area ygb-blank-template">
        <main id="main" class="site-main">
            <div style="max-width:600px;margin:60px auto;padding:40px;background:#fff3cd;border:2px solid #ffc107;border-radius:12px;text-align:center;font-family:system-ui,-apple-system,sans-serif;">
                <h2 style="margin:0 0 15px;color:#856404;">⚠️ WooCommerce no está activo</h2>
                <p style="margin:0 0 25px;color:#856404;">El buscador requiere WooCommerce para funcionar.</p>
                <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" style="display:inline-block;padding:12px 24px;background:#0073aa;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">
                    Activar WooCommerce →
                </a>
            </div>
        </main>
    </div>
    <?php
    get_footer();
    return;
}

get_header(); ?>
<div id="primary" class="content-area ygb-blank-template">
    <main id="main" class="site-main">
        <?php do_action('ygb_sfood_before_shortcode'); ?>
        <?php echo do_shortcode('[ygb_sfood]'); ?>
        <?php do_action('ygb_sfood_after_shortcode'); ?>
    </main>
</div>
<?php
do_action('ygb_sfood_blank_after_content');
get_footer();