<?php

namespace Ecomerciar\Enviopack;

use DateTime;
use Ecomerciar\Enviopack\Helper;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Enviopack
{
    private $api_key, $api_secret, $access_token;

    public function __construct()
    {

        $this->api_key = get_option('enviopack_api_key');
        $this->api_secret = get_option('enviopack_api_secret');
        $this->logger = wc_get_logger();

        $access_token = get_option('enviopack_access_token');
        if (!$access_token) {
            $this->set_access_token();
            $access_token = get_option('enviopack_access_token');
        }
        $this->access_token = $access_token;
    }

    public function get_api_key()
    {
        return $this->api_key;
    }

    public function get_api_secret()
    {
        return $this->api_secret;
    }

    public function get_access_token()
    {
        return $this->access_token;
    }

    public function create_shipment($order = '', $customer = array(), $province_id = '', $zone_name = '')
    {
        if (!$order || empty($customer) || !$province_id) {
            return false;
        }
        /**
         * Traemos los items de la orden y por cada uno, traemos el producto y agregamos los datos de los productos
         * para ser identificados en EP. (Fullfilment)
         */
        $items       = $order->get_items();
        $products    = [];
        $productsIds = [];
        $helper      = new Helper();

        foreach($items as $item) {
            $product     = wc_get_product($item->get_product_id());
            if ($product && !$product->is_virtual()) {
                // Le mandamos a la api de EP la info del producto para el caso en que se deba importar el producto
                $productoData = $helper->prepareProductForAPI($item->get_product_id(), $product);
                $sku          = $product->get_sku();
                $productsIds[]  = $product->get_meta('enviopack_product_id');

                if(empty($sku)) {
                    $sku = 'WC-'. $item->get_product_id();
                }

                $products[]  = [
                    'tipo_identificador'  => 'SKU',
                    'identificador'       => $sku,
                    'cantidad'            => $item->get_quantity(),
                    'dataProducto'        => $productoData
                ];
            }
        }

        $now = new DateTime;

        $params = array(
            'access_token' => $this->get_access_token(),
            'id_externo' => 'WC-' . $order->get_id(),
            'nombre' => $customer['name'],
            'apellido' => $customer['last_name'],
            'email' => $customer['email'],
            'telefono' => $customer['phone'],
            'monto' => $order->get_total(),
            'fecha_alta' => $now->format(DateTime::ISO8601),
            'pagado' => true,
            'provincia' => $province_id,
            'localidad' => $zone_name
        );

        if (!empty($products)) {
            $params['productos'] = $products;
        }

        $response = $this->call_api('POST', '/pedidos', $params);

        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al crear pedido: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            Helper::setFlash('error', 'Enviopack -> WP Error al crear pedido: ' . $response->get_error_message());
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            $this->sincronizeProductsMetadata($productsIds);
            Helper::setFlash('success', 'Enviopack -> Pedido creado con ??xito');
            return $response;
        } else {
            $this->logger->error('Enviopack -> Crear pedido - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Crear pedido - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            Helper::setFlash('error', 'Enviopack -> Crear pedido - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje');

            if (isset($response['errors'])) {
                foreach ($response['errors'] as $key => $value) {
                    foreach ($value as $response_key => $response_error) {
                        $this->logger->error('Enviopack -> Response errors:  ' . $key . ' -> ' . $response_key . ' -> ' . $response_error, ECOM_LOGGER_CONTEXT);
                        Helper::setFlash('error', 'Enviopack -> Response errors:  ' . $key . ' -> ' . $response_key . ' -> ' . $response_error);
                    }
                }
            }
            $this->logger->error('Enviopack -> Crear envio - Data enviada: ' . wc_print_r(json_encode($params), true), ECOM_LOGGER_CONTEXT);
            return false;
        }
    }
    
    public function get_company_mode() 
    {
        $params = array(
            'access_token' => $this->get_access_token()
        );
        $response = $this->call_api('GET', '/configuracion/empresa', $params);

        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener configuracion de empresa: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $empresa = json_decode($response['body'], true);
            return $empresa['modo'];
        } else {
            $this->logger->error('Enviopack -> Configuracion de Empresa - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Configuracion de Empresa - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_despacho_from_modo_empresa() 
    {
        $modoEmpresa = $this->get_company_mode();
        if ($modoEmpresa == 'S') {
            return 'S';
        } else {
            return 'D';
        }
        return false;
    }    

    public function confirm_shipment($order = '', $courier_id = '')
    {
        if (!$order) {
            return false;
        }

        $shipment = unserialize($order->get_meta('enviopack_shipment', true));
        $this->logger->error(print_r($shipment, 1) . __FILE__ . " " . __LINE__);
        $shipment_info = unserialize($order->get_meta('enviopack_shipping_info', true));
        if (!isset($shipment_info['service']) || !$shipment_info['service']) return;
        $helper = new Helper($order);

        $products = $helper->get_items_from_order($order);

        if (!$products) {
            $this->logger->error('Enviopack -> Error al buscar productos de la orden', ECOM_LOGGER_CONTEXT);
            return false;
        }
        //$dimensions = explode('x', $products['shipping_info']['products_details_1']);
        $productos = [];
        $orderItems = $order->get_items();

        foreach($orderItems as $item) {
            $product     = wc_get_product($item->get_product_id());
            if ($product && !$product->is_virtual()) {
                $sku            = $product->get_sku();
                $productsIds[]  = $product->get_meta('enviopack_product_id');

                if(empty($sku)) {
                    $sku = 'WC-'. $item->get_product_id();
                }

                $productos[]  = [
                    'tipo_identificador'  => 'SKU',
                    'identificador'       => $sku,
                    'cantidad'            => $item->get_quantity(),
                ];
            }
        }

        $params = array(
            'access_token' => $this->get_access_token(),
            'pedido' => $shipment['id'],
            'direccion_envio' => intval(get_option('enviopack_address_id')),
            'destinatario' => $shipment['nombre'] . ' ' . $shipment['apellido'],
            'observaciones' => $helper->get_comments(),
            'confirmado' => false,
            'productos' => $productos,
            'despacho' => $this->get_despacho_from_modo_empresa(),
            'modalidad' => $shipment_info['type'],
            'servicio' => $shipment_info['service']
        );

        /* if (empty($params['pedido']) || strlen($params['destinatario']) > 50 || empty($params['modalidad'])) {
            return false;
        } */

        if (empty($params['pedido'])) {
            $params['pedido'] = "";
        }
        if (strlen($params['destinatario']) > 50) {
            $params['destinatario'] = "";
        }
        if (empty($params['modalidad'])) {
            $params['modalidad'] = "";
        }

        if ($shipment_info['type'] === 'D') {

            $direction_param = $helper->get_address($order);

            $params['correo'] = ((int)$courier_id === -1 ? '' : $courier_id);
            $params['calle'] = $direction_param['street'];
            $params['numero'] = $direction_param['number'];
            $params['piso'] = $direction_param['floor'];
            $params['depto'] = $direction_param['apartment'];
            $params['referencia_domicilio'] = '';
            $params['codigo_postal'] = filter_var($helper->get_postal_code(), FILTER_SANITIZE_NUMBER_INT);
            $params['provincia'] = $shipment['provincia'];
            $params['localidad'] = $shipment['localidad'];

            /* if (empty($params['calle']) || strlen($params['calle']) > 30 || empty($params['numero']) || strlen($params['numero']) > 5 || strlen($params['piso']) > 6 || strlen($params['depto']) > 4 || strlen($params['referencia_domicilio']) > 30 || !preg_match('/^\d{4}$/', $params['codigo_postal'], $res) || empty($params['provincia']) || empty($params['localidad'])) {
                return false;
            } */

            if (empty($params['calle']) || strlen($params['calle']) > 30) {
                $params['calle'] = "";
            }
            if (empty($params['numero']) || strlen($params['numero']) > 5) {
                $params['numero'] = "";
            }
            if (strlen($params['piso']) > 6) {
                $params['piso'] = "";
            }
            if (strlen($params['depto']) > 4) {
                $params['depto'] = "";
            }
            if (strlen($params['referencia_domicilio']) > 30) {
                $params['referencia_domicilio'] = "";
            }
            if (!preg_match('/^\d{4}$/', $params['codigo_postal'], $res)) {
                $params['codigo_postal'] = "";
            }
            if (empty($params['provincia'])) {
                $params['provincia'] = "";
            }
            if (empty($params['localidad'])) {
                $params['localidad'] = "";
            }

        } else {
            $params['sucursal'] = $shipment_info['office'];
            if (empty($params['sucursal'])) {
                return false;
            }
        }

        $response = $this->call_api('POST', '/envios', $params);
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al crear envio: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            Helper::setFlash('error', 'Enviopack -> WP Error al crear envio: ' . $response->get_error_message());
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            Helper::setFlash('success', 'EnvioPack - Envio creado con ??xito');
            return $response;
        } else {
            $this->logger->error('Enviopack -> Crear envio - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Crear envio - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            $this->logger->error('Enviopack -> Crear envio - Data enviada: ' . wc_print_r(json_encode($params), true), ECOM_LOGGER_CONTEXT);

            Helper::setFlash('error', 'Enviopack -> Crear envio - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje');
            $message = 'Error al realizar el env??o: ';
            if (isset($response['errors']['global'])) {
                foreach ($response['errors']['global'] as $key => $value) {
                    $message .= "$value, ";
                }
            }

            foreach ($response['errors']['campos'] as $key => $value) {
                $message .= "<br>$key - $value";
            }

            Helper::setFlash('error', $message);
            
            $order->update_meta_data('confirm_shipment_last_error', $message);
            return false;
        }
    }

    public function get_price_to_office($province = '', $cp = '', $weight = 0, $packages = '', $order_subtotal = 0)
    {
        if (!$province || !$cp || !$weight || !$packages) {
            return false;
        }
        $response = $this->call_api('GET', '/cotizar/precio/a-sucursal', array('access_token' => $this->get_access_token(), 'provincia' => $province, 'codigo_postal' => $cp, 'peso' => $weight, 'paquetes' => $packages, 'monto_pedido' => $order_subtotal, 'plataforma' => 'woocommerce'));

        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener precio sucursales: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            $new_offices = array();
            foreach ($response as $office) {
                $new_office = array();
                switch ($office['servicio']) {
                    case 'N':
                    default:
                        $new_office['service_name'] = 'est??ndar';
                        break;
                    case 'P':
                        $new_office['service_name'] = 'prioritario';
                        break;
                    case 'X':
                        $new_office['service_name'] = 'express';
                        break;
                    case 'R':
                        $new_office['service_name'] = 'devoluci??n';
                        break;
                }
                $new_office['courier'] = $office['sucursal']['correo']['nombre'];
                $new_office['shipping_time'] = $office['horas_entrega'];
                $new_office['service'] = $office['servicio'];
                $new_office['address'] = $office['sucursal']['calle'] . ' ' . $office['sucursal']['numero'];
                $new_office['id'] = $office['sucursal']['id'];
                $new_office['name'] = $office['sucursal']['nombre'];
                $new_office['lat'] = $office['sucursal']['latitud'];
                $new_office['lng'] = $office['sucursal']['longitud'];
                $new_office['full_address'] = $office['sucursal']['calle'] . ' ' . $office['sucursal']['numero'] . ', ' . $office['sucursal']['localidad']['nombre'];
                $new_office['phone'] = $office['sucursal']['telefono'];
                $new_office['zone_id'] = $office['sucursal']['localidad']['id'];
                $new_office['zone_name'] = $office['sucursal']['localidad']['nombre'];
                $new_office['price'] = $office['valor'];
                $new_offices[] = $new_office;
            }
            return $new_offices;
        } else {
            $this->logger->error('Enviopack -> Precio sucursales - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Precio sucursales - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_packages()
    {
        $response = $this->call_api('GET', '/tipos-de-paquetes', array('access_token' => $this->get_access_token()));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener tipos de paquetes: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            return $response;
        } else {
            $this->logger->error('Enviopack -> Tipo de Paquetes - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Tipo de Paquetes - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_default_package()
    {
        $response = $this->call_api('GET', '/tipos-de-paquetes/default', array('access_token' => $this->get_access_token()));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener precio domicilio: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            return $response;
        } else {
            $this->logger->error('Enviopack -> Precio domicilio - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Precio domicilio - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_package($package)
    {
        if (empty($package)) return false;

        $response = $this->call_api('GET', '/tipos-de-paquetes/' . (string)$package, array('access_token' => $this->get_access_token()));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener tipos de paquetes: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            return $response;
        } else {
            $this->logger->error('Enviopack -> Tipo de Paquete - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Tipo de Paquete - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_price_to_home($province = '', $cp = '', $weight = 0, $packages = '', $order_subtotal = 0)
    {
        if (!$province || !$cp || !$weight || !$packages) {
            return false;
        }
        $response = $this->call_api('GET', '/cotizar/precio/a-domicilio', array('access_token' => $this->get_access_token(), 'provincia' => $province, 'codigo_postal' => $cp, 'peso' => $weight, 'paquetes' => $packages, 'monto_pedido' => $order_subtotal, 'plataforma' => 'woocommerce'));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener precio domicilio: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            //$this->logger->error(print_r($response, 1) . __FILE__ . ": " . __LINE__, ECOM_LOGGER_CONTEXT);
            $new_homes = array();
            foreach ($response as $home) {
                $new_home = array();
                $new_home['service'] = $home['servicio'];
                $new_home['shipping_time'] = $home['horas_entrega'];
                $new_home['price'] = $home['valor'];
                switch ($home['servicio']) {
                    case 'N':
                    default:
                        $new_home['service_name'] = 'est??ndar';
                        break;
                    case 'P':
                        $new_home['service_name'] = 'prioritario';
                        break;
                    case 'X':
                        $new_home['service_name'] = 'express';
                        break;
                    case 'R':
                        $new_home['service_name'] = 'devoluci??n';
                        break;

                }
                $new_homes[] = $new_home;
            }
            usort($new_homes, function ($a, $b) {
                return $a['shipping_time'] > $b['shipping_time'];
            });
            return $new_homes;
        } else {
            $this->logger->error('Enviopack -> Precio domicilio - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Precio domicilio - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_prices_for_vendor($order)
    {
        if (!$order) {
            return false;
        }
        $helper = new Helper($order);
        $province_id = $helper->get_province_id();
        $cp = $helper->get_postal_code();
        $products = $helper->get_items_from_order($order);

        if (!$products) {
            $this->logger->error('Enviopack -> Error buscando productos para el vendedor', ECOM_LOGGER_CONTEXT);
            return false;
        }

        $weight = 0;
        foreach ($products as $product) {
            $weight += $product['peso'];
        }

        $response = $this->call_api('GET', '/cotizar/costo', array('access_token' => $this->get_access_token(), 'provincia' => $province_id, 'codigo_postal' => $cp, 'peso' => $weight, 'paquetes' => array_values($products), 'modalidad' => 'D'));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener precio vendedor: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            $new_prices = array();
            foreach ($response as $price) {
                $new_price = array();
                $new_price['id'] = $price['correo']['id'];
                $new_price['name'] = $price['correo']['nombre'];
                $new_price['price'] = $price['valor'];
                switch ($price['servicio']) {
                    case 'N':
                    default:
                        $new_price['service_name'] = 'est??ndar';
                        break;
                    case 'P':
                        $new_price['service_name'] = 'prioritario';
                        break;
                    case 'X':
                        $new_price['service_name'] = 'express';
                        break;
                    case 'R':
                        $new_price['service_name'] = 'devoluci??n';
                        break;

                }
                $new_prices[] = $new_price;
            }
            return $new_prices;
        } else {
            $this->logger->error('Enviopack -> Precio vendedor - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Precio vendedor - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_offices($courier_id = '', $zone_id = 0)
    {
        $this->logger->error('Enviopack -> Buscando oficinas con parametros: courier_id = ' . $courier_id . ' y zone_id = ' . $zone_id, ECOM_LOGGER_CONTEXT);
        if (!$courier_id || !$zone_id) {
            return false;
        }
        $response = $this->call_api('GET', '/sucursales', array('access_token' => $this->get_access_token(), 'id_correo' => $courier_id, 'id_localidad' => $zone_id));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener sucursales: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            $response = array_map(function ($val) {
                unset($val['correo']);
                unset($val['localidad']);
                return $val;
            }, $response);
            return $response;
        } else {
            $this->logger->error('Enviopack -> Sucursales - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Sucursales - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_provinces()
    {
        $response = $this->call_api('GET', '/provincias', array('access_token' => $this->get_access_token()));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener provincias: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            return $response;
        } else {
            $this->logger->error('Enviopack -> Provincias - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Provincias - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_zones($province_id = '')
    {
        if (!$province_id) {
            return false;
        }
        $response = $this->call_api('GET', '/localidades', array('access_token' => $this->get_access_token(), 'id_provincia' => $province_id));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener localidades: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            return $response;
        } else {
            $this->logger->error('Enviopack -> Localidades - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Localidades - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

     public function get_zone_name($province_id, $locality) {
        $zones = $this->get_zones($province_id);

        foreach($zones as $zone) {
            if ($zone['id'] === $locality) {
                return $zone['nombre'];
            }
        }
    }

    public function get_tracking_statuses($tracking_id = '')
    {
        if (!$tracking_id) {
            return false;
        }
        $response = $this->call_api('GET', '/tracking', array('tracking_number' => $tracking_id));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener etiquetas: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            return $response;
        } else {
            $response_code = $response['response']['code'];
            if ($response_code !== 404)
                $this->logger->error('Enviopack -> Tracking - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Tracking - Error del servidor para tracking: ' . $tracking_id . ' | ' . $response['message'], ECOM_LOGGER_CONTEXT);
            if ($response_code !== 404) $response_code = false;
            return $response_code;
        }
    }

    public function get_label($order = '')
    {
        if (!$order) {
            return false;
        }
        $shipment_info = unserialize($order->get_meta('enviopack_confirmed_shipment', true));
        if (!$shipment_info['pedido']) {
            return false;
        }
        $response = $this->call_api('GET', '/envios/' . $shipment_info['pedido'] . '/etiqueta', array('access_token' => $this->get_access_token(), 'formato' => 'pdf'));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener etiquetas: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = $response['body'];
            return $response;
        } else {
            $this->logger->error('Enviopack -> Etiquetas - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Etiquetas - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_shipping_addresses()
    {
        $response = $this->call_api('GET', '/direcciones-de-envio', array('access_token' => $this->get_access_token()));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener direcciones de envio: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            $new_addresses = array();
            foreach ($response as $address) {
                $new_address = array();
                $new_address['id'] = $address['id'];
                $new_address['default'] = $address['es_default'];
                $new_address['address'] = $address['calle'] . ' ' . $address['numero'];
                $new_addresses[] = $new_address;
            }
            return $new_addresses;
        } else {
            $this->logger->error('Enviopack -> Direcciones de Envio - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Direcciones de Envio - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    public function get_couriers()
    {
        $response = $this->call_api('GET', '/correos', array('access_token' => $this->get_access_token()));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener correos: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            $new_couriers = array();
            foreach ($response as $courier) {
                if ($courier['activo']) {
                    $new_courier = array();
                    $new_courier['id'] = $courier['id'];
                    $new_courier['name'] = $courier['nombre'];
                    $new_couriers[] = $new_courier;
                }
            }
            return $new_couriers;
        } else {
            $this->logger->error('Enviopack -> Correos - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Correos - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    private function set_access_token()
    {
        $response = $this->call_api('POST', '/auth', array('api-key' => $this->get_api_key(), 'secret-key' => $this->get_api_secret()), array('Content-Type' => 'application/x-www-form-urlencoded'));
        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener access token: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }
        if ($response['response']['code'] === 200) {
            $response = json_decode($response['body'], true);
            if (isset($response['token'])) {
                update_option('enviopack_access_token', $response['token']);
                $this->access_token = $response['token'];
            } else {
                $this->logger->error('Enviopack -> Error del servidor al obtener access token: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
                return false;
            }
        } else {
            $this->logger->error('Enviopack -> Access token - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Access token - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        }
    }

    /**
     * Importa productos a Enviopack. $data debe recibir un array con la key "productos" la cual debe ser un array
     * donde cada posicion debe ser el resultado de pasar el WC_Product por la funcion prepareProductForAPI del la clase Helper
     */
    public function create_product($data) 
    {
        $params                 = $data;
        $params['access_token'] = $this->get_access_token();
        $response               = $this->call_api('POST', '/extensiones/woocommerce/importar/productos', $params, $headers);

        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al obtener access token: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            return false;
        }

        if ($response['response']['code'] !== 200) {
            $this->logger->error('Enviopack -> Importar Producto - Error del servidor codigo: ' . (isset($response['response']['code']) ? $response['response']['code'] : 'Sin codigo'), ECOM_LOGGER_CONTEXT);
            $response = json_decode($response['body'], true);
            $this->logger->error('Enviopack -> Importar Producto - Error del servidor mensaje: ' . (isset($response['message']) && isset($response['errors']['global'][0])) ? $response['message'] . ': ' . $response['errors']['global'][0] : 'Sin mensaje', ECOM_LOGGER_CONTEXT);
            return false;
        } else {
            $response = json_decode($response['body'], true);

            return $response;
        }
    }

    /**
     * Luego de crear el pedido, traemos los datos de los productos que se enviaron en el pedido para guardar la metadata 
     * de los productos que se importaron en el proceso y por lo tanto no tenemos su respectivo id de EP y los ids de los paquetes
     */
    public function sincronizeProductsMetadata($productsIds) {
        $this->logger->error('Enviopack -> Se van a sincronizar los productos del pedido',ECOM_LOGGER_CONTEXT); 
        $response = $this->call_api('POST', 'extensiones/woocommerce/obtener/metadata/productos', ['productos' => $productsIds]);

        if (is_wp_error($response)) {
            $this->logger->error('Enviopack -> WP Error al sincronizar Productos: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
            Helper::setFlash('error', 'Enviopack -> WP Error al sincronizar productos: ' . $response->get_error_message());
            return false;
        }

        $data     = json_decode($response['body']);

        foreach ($data['metadata'] as $productData) {
            $productId = wc_get_product_id_by_sku($productData['sku']);
            if ($productId) {
                $product = wc_get_product($productId);
                if ($product) {
                    $product->update_meta_data('enviopack_product_id',  $productData['id']);
                    $product->update_meta_data('enviopack_product_paquetes',  json_encode($productData['paquetes']));
                    $product->save();
                }
            } else {
                /**
                 * Si no encontramos el producto por el sku recibido desde EP, puede ser porque no se estan
                 * manejando SKU en WC, en Enviopack al importar el producto si no va el sku se le asigna el sku
                 * WC-{idProductoWC}, por lo que eliminando los 3 primeros caracteres del string, reintentamos buscar
                 * por el id del producto
                 */
                $id = substr_replace($productData['sku'], '', 0, 3);
                $product = wc_get_product($id);
                if ($product) {
                    $product->update_meta_data('enviopack_product_id',  $productData['id']);
                    $product->update_meta_data('enviopack_product_paquetes',  json_encode($productData['paquetes']));
                    $product->save();
                }
            }
        }
    }

    public function call_api($method = '', $endpoint = '', $params = array(), $headers = array())
    {
       /* echo "Method:"; var_dump($method);echo "<hr>";
        echo "endpoint:"; var_dump($endpoint);echo "<hr>";
        echo "params:"; var_dump($params);echo "<hr>";
        echo "headers:"; var_dump($headers);echo "<hr>";die;*/
        if ($method && $endpoint) {
            $url = $this->getApiBaseUrl() . $endpoint;
            if ($method === 'GET') {
                $response = wp_remote_get($url . '?' . http_build_query($params));
            } else {
                $url .= '?access_token=' . (isset($params['access_token']) ? $params['access_token'] : '');
                unset($params['access_token']);
                $args = array(
                    'headers' => $headers,
                    'body' => ($endpoint === '/auth' ? http_build_query($params) : json_encode($params))
                );
                $response = wp_remote_post($url, $args);
            }
            // Token call, return it to prevent endless execution
            if ($endpoint === '/auth') {
                return $response;
            }

            if (is_wp_error($response)) {
                $this->logger->error('Enviopack -> WP Error antes de reintentar al llamar a la API: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
                return false;
            }

            // Bad call - Maybe invalid Access token?
            if ($response['response']['code'] === 401) {
                $this->set_access_token();
                if (isset($params['access_token'])) {
                    $params['access_token'] = $this->get_access_token();
                }
            } else {
            // Good call - return data
                return $response;
            }

            // New Access token, retry call
            if ($method === 'GET') {
                $response = wp_remote_get($url . '?' . http_build_query($params));
            } else {
                $args = array(
                    'headers' => $headers,
                    'body' => json_encode($params)
                );
                $response = wp_remote_post($url, $args);
            }
            if (is_wp_error($response)) {
                $this->logger->error('Enviopack -> WP Error luego de reintentar al llamar a la API: ' . $response->get_error_message(), ECOM_LOGGER_CONTEXT);
                return false;
            }
            return $response;
        }
    }

    /**
     * Devuelve la URL base a la api de EP. Si estamos en desarrollo apunta a la api en forma local
     * Remitirse a https://docs.google.com/document/d/1NOuyY4VRLnVG-Hmbd5RoxoPnp8UDogvaD6OSretmyaU/edit
     * para configurar el ambiente de internacionalizacion
     * 
     */
    public function getApiBaseUrl() 
    {
       
        $url = 'https://api.enviopack.com';

        if (ECOM_ENV_DEV) {
            $url = 'http://api.enviopack.pm/app_dev.php';
        }

        return $url;
    }
}
