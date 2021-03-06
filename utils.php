<?php

namespace Ecomerciar\Enviopack\Utils;

use Doctrine\ORM\Query\Expr\Func;
use Ecomerciar\Enviopack\Enviopack;
use Ecomerciar\Enviopack\Helper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
} 

function add_method($methods)
{
    $methods['enviopack'] = 'Ecomerciar\Enviopack\WC_Enviopack';
    return $methods;
}

function add_maps()
{
    if (!wp_script_is('offices-map', $list = 'enqueued')) {
        wp_enqueue_script('offices-map', plugin_dir_url(__FILE__) . 'js/gmap.js', array('jquery'), 1.00001, true);
    }
    if (!wp_script_is('offices-map-init', $list = 'enqueued')) {
        wp_enqueue_script('offices-map-init', 'https://maps.googleapis.com/maps/api/js?key=' . get_option('enviopack_gmap_key', 'AIzaSyDuhF23s4P90AFdaW-ffxcAAMgbu-oKDCQ'), array('jquery'), 1.00001, true);
    }
    wp_localize_script('offices-map', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    $chosen_shipping_method = WC()->session->get('chosen_shipping_methods');
    $chosen_shipping_method = $chosen_shipping_method[0];
    $chosen_shipping_method = explode(" ", $chosen_shipping_method);
    if ($chosen_shipping_method[0] === 'enviopack' && $chosen_shipping_method[1] === 'S') {
        echo '<h4>Seleccioná la sucursal donde querés recibir tu pedido</h4>';
        echo '<div style="height: 100%; margin-bottom:10px">';
        echo '<a id="ep-show-map" onclick="initMap()" class="button btn alt">Elegir sucursal</a>';
        echo '<div id="enviopack-map"></div>';
        echo '</div>';
    }

    if (!wp_script_is('review_checkout', $list = 'enqueued')) {
        wp_register_script('review_checkout', plugin_dir_url(__FILE__) . 'js/gmap.js', array('jquery'), 1.0, true);
    }
}

/**
 * Esta funcion es llamada por el hook wp_footer para encolar todos los scripts js personalizados
 * Se debe de agregar aqui la llamada a encolamiento de los scripts
 **/
function customScripts($hook)
{
    //review_order();
    add_button_css_file($hook);
}

function review_order() 
{
    
}

function get_offices()
{

    $cp = WC()->customer->get_shipping_postcode();
    if (!$cp) {
        $cp = WC()->customer->get_billing_postcode();
    }

    $province = WC()->customer->get_shipping_state();
    if (empty($province)) {
        $province = WC()->customer->get_billing_state();
    }
    $order_subtotal = WC()->cart->get_subtotal();
    if (!empty($order_subtotal)) {
        $order_subtotal = number_format($order_subtotal, 2, '.', '');
    }
    $helper = new Helper;
    $ep = new Enviopack;

    $products = $helper->get_items_from_cart();
    
    if (!$products) {
        return false;
    }

    $prices = $ep->get_price_to_office($province, $cp, $products['shipping_info']['total_weight'], $products['shipping_info']['products_details_1'], $order_subtotal);
    
    $center_coords = $helper->get_state_center($province);
    if (!$prices || !$center_coords) {
        return false;
    }
    wp_send_json_success(array('offices' => $prices, 'center_coords' => $center_coords));
}

function set_office()
{
    if (!isset(WC()->session)) {
        wp_die();
    }
    WC()->session->set('enviopack_office_address', preg_replace('/[^A-Za-z0-9\-\s]/', '', sanitize_text_field($_POST['office_address'])));
    WC()->session->set('enviopack_office_service', filter_var(sanitize_text_field($_POST['office_service'], FILTER_SANITIZE_STRING)));
    WC()->session->set('enviopack_office_price', filter_var(sanitize_text_field($_POST['office_price'], FILTER_VALIDATE_FLOAT)));
    WC()->session->set('enviopack_office_id', filter_var(sanitize_key($_POST['office_id'], FILTER_VALIDATE_INT)));
    wp_send_json_success(array('office_id' => filter_var(sanitize_key($_POST['office_id'], FILTER_VALIDATE_INT))));
}

function create_office_field($checkout)
{
    $id = WC()->session->get('enviopack_office_id');
    woocommerce_form_field('enviopack_office', array(
        'type' => 'text',
        'class' => array('form-row-first', 'hidden-field'),
        'label' => __('Sucursal Envío'),
        'default' => ($id ? $id : '-1'),
        'required' => true
    ), $checkout->get_value('enviopack_office'));
}

function check_office_field()
{
    $chosen_shipping_method = WC()->session->get('chosen_shipping_methods');
    $chosen_shipping_method = $chosen_shipping_method[0];
    $chosen_shipping_method = explode(" ", $chosen_shipping_method);
    if ($chosen_shipping_method[0] === 'enviopack' && $chosen_shipping_method[1] === 'S' && (int)sanitize_key($_POST['enviopack_office']) === -1) {
        wc_add_notice('Por favor elige una sucursal de envío', 'error');
    }
}

function update_order_meta($order_id)
{
    $order = wc_get_order($order_id);
    if (!$order) return false;

    $chosen_shipping_method = WC()->session->get('chosen_shipping_methods');
    $chosen_shipping_method = reset($chosen_shipping_method);
    $chosen_shipping_method = explode(" ", $chosen_shipping_method);
    if ($chosen_shipping_method[0] === 'enviopack') {
        $data = array();
        $data['type'] = $chosen_shipping_method[1];
        $data['service'] = $chosen_shipping_method[2];
        $data['office'] = (isset($chosen_shipping_method[3]) ? $chosen_shipping_method[3] : '');
        $order->update_meta_data('enviopack_shipping_info', serialize($data));
        $order->save();

        $config_status = get_option('enviopack_shipping_status');
        $order_status = $order->get_status();
        if ($config_status && ($order_status === $config_status)) {
            $ep     = new Enviopack;
            $helper = new Helper($order);

            $customer    = $helper->get_customer();
            $province_id = $helper->get_province_id();
            $zone_name   = $order->get_shipping_city();
            $shipment    = $ep->create_shipment($order, $customer, $province_id, $zone_name);
            if ($shipment) {
                $order->update_meta_data('enviopack_shipment', serialize($shipment));
                $order->save();
                confirm_shipment($order_id);
            }
        }
    }
}

function process_order_status($order_id, $old_status, $new_status, $force = false)
{
    $order = wc_get_order($order_id);

    $shipping_method = reset($order->get_items('shipping'));

    if (!$shipping_method) {
        return false;
    }

    $order_shipping_method = $shipping_method->get_method_id();

    $config_status = get_option('enviopack_shipping_status');
    if (!$order || !$config_status) return false;
    if ($order_shipping_method !== 'enviopack') return false;

    if ($order->get_meta('enviopack_shipment', true) && !$order->get_meta('enviopack_confirmed_shipment', true) && !$force) {
        if ($config_status && ('wc-' . $new_status) === $config_status) {
            confirm_shipment($order_id);

        } else if ($config_status && $new_status === $config_status) {
            confirm_shipment($order_id);
        }
    } else if ($config_status && (('wc-' . $new_status) === $config_status || $new_status === $config_status) || $force) {
        $ep     = new Enviopack;
        $helper = new Helper($order);

        $customer    = $helper->get_customer();
        $province_id = $helper->get_province_id();
        $zone_name   = $order->get_shipping_city();

        $shipment = $ep->create_shipment($order, $customer, $province_id, $zone_name);

        if ($shipment) {
            $order->update_meta_data('enviopack_shipment', serialize($shipment));
            $order->save();

            confirm_shipment($order_id);
        }
    } else if ($force){
        $ep     = new Enviopack;
        $helper = new Helper($order);

        $customer    = $helper->get_customer();
        $province_id = $helper->get_province_id();
        $zone_name   = $order->get_shipping_city();

        $shipment = $ep->create_shipment($order, $customer, $province_id, $zone_name);

        if ($shipment) {
            $order->update_meta_data('enviopack_shipment', serialize($shipment));
            $order->save();

            confirm_shipment($order_id);
        }
    }
}

function confirm_shipment($order_id, $courier_id = -1, $source = 'auto')
{
    $order = wc_get_order($order_id);
    if (!$order) return false;

    $chosen_shipping_method = reset($order->get_shipping_methods());
    if (!$chosen_shipping_method) return false;
    $chosen_shipping_method_id = $chosen_shipping_method->get_method_id();
    $chosen_shipping_method = explode(" ", $chosen_shipping_method_id);
    if ($chosen_shipping_method[0] === 'enviopack') {
        $shipping_method = unserialize($order->get_meta('enviopack_shipping_info', true));
        if (!$shipping_method) {
            $logger->error('Order ID ' . $order_id . ' no posee informacion de tipo de envio',  ECOM_LOGGER_CONTEXT);
            return false;
        } 

        
        $ep = new Enviopack;
        if ($shipping_method['type'] === 'D') {
            $shipment = $ep->confirm_shipment($order, $courier_id);
        } else {
            $shipment = $ep->confirm_shipment($order);
        }
        if ($shipment) {
            $order->update_meta_data('enviopack_confirmed_shipment', serialize($shipment));
            $order->update_meta_data('enviopack_order_number', $shipment['id']);
            if (isset($shipment['tracking_number']))
                $order->update_meta_data('enviopack_tracking_number', $shipment['tracking_number']);
        }
        $order->save();
    }
}

function add_box()
{
    global $post;
    $order = wc_get_order($post->ID);
    if ($order) {
        
        $shipping_data = $order->get_shipping_methods();
        $chosen_shipping_method = reset($shipping_data);
        if (!$chosen_shipping_method) {
            return false;
        }
        $chosen_shipping_method_id = $chosen_shipping_method->get_method_id();
        $chosen_shipping_method = explode(" ", $chosen_shipping_method_id);
        if ($chosen_shipping_method[0] === 'enviopack') {
            add_meta_box(
                'enviopack_box',
                'EnvioPack',
                __NAMESPACE__ . '\box_content',
                'shop_order',
                'side'
            );
        }
    }

    $product = wc_get_product($post);

    if ($product) {
        add_meta_box(
            'enviopack_product_box',
            'EnvioPack',
            __NAMESPACE__ . '\box_product_content',
            'product',
            'normal'
        );
    }

    return false;
}

function box_content()
{
    global $post;

    $order = wc_get_order($post->ID);
    $confirmed_shipment = $order->get_meta('enviopack_confirmed_shipment', true);
    if (!empty($confirmed_shipment) && $confirmed_shipment !== 'b:0;') {
        $tracking_number = $order->get_meta('enviopack_tracking_number', true);
        echo 'Se ha generado el envío de este pedido, debes confirmarlo desde el <a href="https://app.enviopack.com/pedidos/por-confirmar/" target="_blank">panel de EnvíoPack</a>';
        if ($tracking_number) {
            echo '<br> Número de rastreo: <strong>' . $tracking_number . '</strong>';
            $page_link = get_page_by_title('Rastreo')->ID;
            if ($page_link) {
                $store_url = get_page_link($page_link);
                if (strpos($store_url, '?') === false) {
                    $store_url .= '?';
                } else {
                    $store_url .= '&';
                }
                $store_url .= 'id=' . $tracking_number;
                echo '<br> <a href="' . $store_url . '" target="_blank">Rastrear pedido</a>';
            }
        }
        return;
    } else {
        echo $order->get_meta('confirm_shipment_last_error', true);
    }
    $shipping_method = $order->get_shipping_methods();
    $shipping_method = array_shift($shipping_method);
    if ($shipping_method) {
        $shipping_method = explode(" ", $shipping_method->get_method_id());
        if ($shipping_method[0] === 'enviopack') {
            echo '<div style="margin: 20px 0">';
            $shipping_mode = get_option('enviopack_shipping_mode');
            if ($shipping_mode && $shipping_mode !== 'manual' && $order->get_status() !== 'completed') {
                echo 'Cuando el pedido esté completado, este se ingresará automáticamente al sistema usando el correo: ' . ucfirst(($shipping_mode));
                echo '</div>';
                return;
            }
            $shipping_method = unserialize($order->get_meta('enviopack_shipping_info', true));
            if (isset($shipping_method['type']) && $shipping_method['type'] === 'D') {

                echo 'Una vez que el pedido esté pago se importará automáticamente a EnvíoPack. Gestioná tus envíos ingresando a tu cuenta.';

            } else if (isset($shipping_method['type']) && $shipping_method['type'] === 'S') {
                echo '<strong>Correo: </strong> Ya seleccionado por la sucursal';
            }
            $config_status = get_option('enviopack_shipping_status');
            $status = $order->get_status();
            if ($status === $config_status || ('wc-' . $status) === $config_status) {
                echo '<button class="button btn alt" style="display:block; width:100%; margin-top:20px; font-size:16px;text-align: center;">Enviar</button>';
            }
            echo '</div>';
        }
    }
}

function save_box($order_id)
{
    $order = wc_get_order($order_id);

    if (empty($_POST['woocommerce_meta_nonce'])) {
        return;
    }
    if (!current_user_can('edit_post', $order_id)) {
        return;
    }
    if (empty($_POST['post_ID']) || $_POST['post_ID'] != $order_id) {
        return;
    }
    if (!empty($order->get_meta('enviopack_confirmed_shipment', true)) && unserialize($order->get_meta('enviopack_confirmed_shipment', true))) {
        return;
    }

    if (!isset($_POST['ep_courier']) || empty($_POST['ep_courier'])) {
        $courier_id = '';
    } else {
        $courier_id = filter_var(sanitize_text_field($_POST['ep_courier']), FILTER_SANITIZE_STRING);
    }

    $status = $order->get_status();
    process_order_status($order_id, $status, $status);
}

function add_action_button($actions, $order)
{
    $chosen_shipping_method = reset($order->get_shipping_methods());
    if (!$chosen_shipping_method) {
        return $actions;
    }
    $chosen_shipping_method_id = $chosen_shipping_method->get_method_id();
    $chosen_shipping_method = explode(" ", $chosen_shipping_method_id);
    if ($chosen_shipping_method[0] === 'enviopack') {
        $shipment_info = $order->get_meta('enviopack_confirmed_shipment', true);
        if ($shipment_info) {
            $actions['ep-label'] = array(
                'url' => plugin_dir_url(__FILE__) . 'labels/label-' . $order->get_id() . '.pdf',
                'name' => 'Ver etiqueta',
                'action' => 'ep-label',
            );
        }
    }
    return $actions;
}

function add_button_css_file($hook)
{
    if ($hook !== 'edit.php') return;
    wp_enqueue_style('action-button.css', plugin_dir_url(__FILE__) . 'css/action-button.css', array(), 1.0);
}

function create_page()
{
    global $wp_version;

    if (version_compare(PHP_VERSION, '5.6', '<')) {
        $flag = 'PHP';
        $version = '5.6';
    } else if (version_compare($wp_version, '4.9', '<')) {
        $flag = 'WordPress';
        $version = '4.9';
    } else {

        if (defined('ECOM_ENVIOPACK_APIKEY') && defined('ECOM_ENVIOPACK_SECRETKEY') && !empty('ECOM_ENVIOPACK_APIKEY') && !empty('ECOM_ENVIOPACK_SECRETKEY')) {
            update_option('enviopack_api_key', ECOM_ENVIOPACK_APIKEY);
            update_option('enviopack_api_secret', ECOM_ENVIOPACK_SECRETKEY);
        }
        $zone = new \WC_Shipping_Zone();
        if ($zone) {
            $zone->set_zone_name('Argentina');
            $helper = new Helper();
            $zone->set_locations($helper->get_zones_names_for_shipping_zone());
            $zone->add_shipping_method('enviopack');
            $zone->save();
        }
        return;
    }
    deactivate_plugins(basename(__FILE__));
    wp_die('<p><strong>Enviopack</strong> Requiere al menos ' . $flag . ' version ' . $version . ' o mayor.</p>', 'Plugin Activation Error', array('response' => 200, 'back_link' => true));
}

function create_shortcode()
{
    $content = '<h2 class="enviopack-tracking-form-title">Número de envío</h2>
		<form method="get" class="enviopack-tracking-form">
		<input type="text" name="enviopack_form_id" style="width:40%" class="enviopack-tracking-form-field"><br>
		<br />
		<input name="submit_button" type="submit"  value="Consultar"  id="update_button"  class="enviopack-tracking-form-submit update_button" style="cursor: pointer;background-color: #f83885;border: 1px solid #f83885;color: white;padding: 5px 10px;display: inline-block;border-radius: 4px;font-weight: 600;margin-bottom: 10px;text-align: center;"/>
		</form>';
    if (isset($_GET['enviopack_form_id'])) {
        $ep = new Enviopack;
        $ep_id = filter_var(sanitize_key($_GET['enviopack_form_id']), FILTER_SANITIZE_SPECIAL_CHARS);
        $tracking_statuses = $ep->get_tracking_statuses($ep_id);
        if (is_array($tracking_statuses)) {
            $tracking_statuses = $tracking_statuses[0];
            $content .= '<h3 class="enviopack-tracking-results-number">Envío Nro: ' . $ep_id . '</h3>';
            if (isset($tracking_statuses['correo']['nombre']) && !empty($tracking_statuses['correo']['nombre']))
                $content .= '<h4>Correo: ' . $tracking_statuses['correo']['nombre'] . '</h4>';
            if (isset($tracking_statuses['modalidad']) && ($tracking_statuses['modalidad'] === 'S' || $tracking_statuses['modalidad'] === 'D')) {
                if ($tracking_statuses['modalidad'] === 'S') $modality = 'Sucursal';
                if ($tracking_statuses['modalidad'] === 'D') $modality = 'Domicilio';
                $content .= '<h4>Servicio a ' . $modality . '</h4>';
            }
            $content .= '<table class="enviopack-tracking-results-table">';
            $content .= "<tr>";
            $content .= "<th width=\"30%\" style=\"background-color: #f83885;color: #fff;\">Fecha</th>";
            $content .= "<th width=\"70%\" style=\"background-color: #f83885;color: #fff;\">Estado actual</th>";
            $content .= "</tr>";
            foreach ($tracking_statuses['tracking'] as $tracking_status) {
                $content .= "<tr>";
                $content .= "<td>" . $tracking_status['fecha'] . "</td>";
                $content .= "<td>" . $tracking_status['mensaje'] . "</td>";
                $content .= "</tr>";
            }
            $content .= "</table>";
        } else {
            if ($tracking_statuses === 404) {
                $content .= '<h3  class="enviopack-tracking-error">No existe un envío con el nº de tracking informado</h3>';
            } else {
                $content .= '<h3  class="enviopack-tracking-error">Hubo un error, por favor intenta nuevamente</h3>';
            }
        }
    }
    return $content;
}

function create_settings_link($links)
{
    $links[] = '<a href="' . esc_url(get_admin_url(null, 'options-general.php?page=enviopack_settings')) . '">Ajustes</a>';
    return $links;
}

function enviopack_add_free_shipping_label($label, $method)
{
    $label_tmp = explode(':', $label);
    if ($method->get_cost() == 0 && $method->get_method_id() == 'enviopack') {
        $label = $label_tmp[0] . __(' - ¡Envío Gratis!', 'woocommerce');
    }
    return $label;
}

function clear_cache()
{
    $packages = WC()->cart->get_shipping_packages();
    foreach ($packages as $key => $value) {
        $shipping_session = "shipping_for_package_$key";
        unset(WC()->session->$shipping_session);
    }
}

function handle_webhook()
{
    $enviopack_order_number = sanitize_key($_GET["id"]);
    $enviopack_order_number = filter_var($enviopack_order_number, FILTER_SANITIZE_NUMBER_INT);

    $args = array(
        'meta_key' => 'enviopack_order_number',
        'meta_value' => $enviopack_order_number
    );
    $orders = wc_get_orders($args);
    if (empty($orders)) {
        wc_get_logger()->error('Webhook recibido para la orden: ' . $enviopack_order_number . ' no se encontró orden con esta meta data');
        return false;
    }
    $order = $orders[0];

    $status = get_option('enviopack_status_on_processed', false);
    if ($status) {
        $order->set_status($status);
        $order->save();
    } else {
        wc_get_logger()->error('Webhook - No se encontró el nuevo estado a colocar en orden');
    }

}

/**
 * Al crear un producto, si esta en estado "publish", se importa a Enviopack
 */
function create_product($product_id)
{
    $helper  = new Helper(); 
    $ep      = new Enviopack();
    $product = wc_get_product($product_id);
    
    if ($product && $product->get_status() === 'publish' && !$product->is_virtual()) {
        $product_data = $helper->prepareProductForAPI($product_id, $product);
        $response     = $ep->create_product(['productos' => [$product_data]]);

        if ($response['count'] > 0) {
            foreach($response['productosImportados'] as $importado) {
                $productId = wc_get_product_id_by_sku($importado['sku']);
                if ($productId) {
                    $product = wc_get_product($productId);
                    if ($product) {
                        $product->update_meta_data('enviopack_product_id',  $importado['id']);
                        $product->update_meta_data('enviopack_product_paquetes',  json_encode($importado['paquetes']));
                        $product->save();
                    }
                }
            }
        }
    }
}

/**
 * Agrega la accion de importar productos por lotes al listado de acciones en la tabla de productos
 */
function add_bulk_import_product_action($bulk_array)
{
    $bulk_array['ep_bulk_import_product'] = 'Exportar a Enviopack';

    return $bulk_array;
}

/**
 * Agrega la accion de forzar los pedidos en las acciones del detalle de 
 */
function add_process_order_action($bulk_array)
{
    $bulk_array['ep_process_order'] = 'Exportar a Enviopack';

    return $bulk_array;
}

/**
 * Define el comportamiento de la accion por lote al importar varios productos
 */
function product_action_handler($redirect, $doaction, $object_ids) 
{
    $redirect = remove_query_arg( array( 'ep_bulk_import_product' ), $redirect );

    if ($doaction === 'ep_bulk_import_product') {

        $ep     = new Enviopack();
        $helper = new Helper();
        $data   = [];

        foreach($object_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->get_status() === 'publish' && !$product->is_virtual()) {
                $product_data = $helper->prepareProductForAPI($product_id, $product);
                $data[] = $product_data;
            }
        }

        if (!empty($data)) {
            
            $response       = $ep->create_product(['productos' => $data]);
            $products_count = $response ['count'];

            foreach($response['productosImportados'] as $importado) {
                $productId = wc_get_product_id_by_sku($importado['sku']);
                if ($productId) {
                    $product = wc_get_product($productId);
                    if ($product) {
                        $product->update_meta_data('enviopack_product_id',  $importado['id']);
                        $product->update_meta_data('enviopack_product_paquetes',  json_encode($importado['paquetes']));
                        $product->save();
                    }
                }
            }
        }

        $redirect = add_query_arg(
			'ep_import_products_count', 
			$products_count,
		$redirect );
    }

    return $redirect;
}

/**
 * Define el comportamiento de la accion por lote al procesar varias ordenes
 */
function shop_order_action_handler($order) 
{
    process_order_status($order->id, '', 'wc-processing', true);
}

/**
 * Esta funcion imprime alertas sobre las vistas. Sirve para comunicar mensajes al usuario luego de una accion
 * Se debe de usar la funcion setFlash de la clase Helper para crear el alerta
 */
function enviopack_notices()
{
    if ( !empty($_REQUEST['ep_import_products_count'])) {
        echo '<div id="ep_message" class="updated notice is-dismissible">
            <p>Se exportaron '. esc_html ($_REQUEST['ep_import_products_count']) . ' productos a Enviopack</p>
        </div>';
    }

    $flashes = Helper::getFlashes();

    foreach($flashes as $flash) {
        echo esc_html($flash);
    }

}

/**
 * Agrega la caja de datos extras en el form de productos
 */
function box_product_content()
{
    global $post;

    $product = wc_get_product($post->ID);
    
    echo '<p class="form-field _epId_field ">
            <label for="_epId"><abbr title="ID del producto en Enviopack">ID Enviopack</abbr></label>
            <input type="text" class="short" style="" name="_epId" id="_epId" value="'.$product->get_meta('enviopack_product_id').'" placeholder="" disabled>
         </p>';

    echo '<p class="form-field _epPaquetes_field ">
            <label for="_epPaquetes"><abbr title="ID de el/los paquetes del producto en Enviopack">IDs Paquetes</abbr></label>
            <input type="text" class="short" style="" name="_epPaquetes" id="_epPaquetes" value="'.$product->get_meta('enviopack_product_paquetes').'" placeholder="" disabled>
         </p>';
}

