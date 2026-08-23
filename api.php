<?php
/**
 * REST API Proxy & Backend POS - Discreta.cbo
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Cabeceras CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Credenciales WooCommerce API
define('WC_URL', 'https://discretacbo.com/wp-json/wc/v3/');
define('WC_CK',  'ck_10ad592e6f09661d92f9ba48763d3a9d192d8ebc');
define('WC_CS',  'cs_d06b9a710a254ab8046e3de350ad32afdcb7781e');

// Incluir credenciales de BD
if (file_exists('config_db.php')) {
    require_once 'config_db.php';
}

// Función helper para obtener conexión PDO al POS
function getPosDB() {
    if (function_exists('getDBConnection') && defined('DB_POS_NAME')) {
        return getDBConnection(DB_POS_NAME, DB_POS_USER, DB_POS_PASS);
    }
    return null;
}

// Función de respuesta JSON uniforme
function respondJSON($status, $message, $data = null) {
    http_response_code($status);
    $response = [
        "status" => $status,
        "message" => $message,
        "timestamp" => date('Y-m-d H:i:s')
    ];
    if ($data !== null) {
        $response["data"] = $data;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// Consulta cURL a WooCommerce REST API
function callWooCommerceAPI($path, $params = []) {
    $queryParams = array_merge([
        'consumer_key' => WC_CK,
        'consumer_secret' => WC_CS
    ], $params);

    $url = WC_URL . ltrim($path, '/') . '?' . http_build_query($queryParams);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return false;
    }

    return json_decode($response, true);
}

// Obtener Tasa BCV (Obtiene la última de la BD o valor default)
function getBCVRate() {
    $pdo = getPosDB();
    if ($pdo !== null) {
        try {
            $stmt = $pdo->query("SELECT tasa FROM historial_tasas_bcv ORDER BY id DESC LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && floatval($row['tasa']) > 0) {
                return floatval($row['tasa']);
            }
        } catch (Exception $e) {}
    }
    return 784.66; // Fallback por defecto si BD no responde
}

// Procesar Endpoint
$endpoint = $_GET['endpoint'] ?? '';
if (empty($endpoint)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true);
        $endpoint = $input['endpoint'] ?? '';
    }
}

try {
    switch ($endpoint) {

        // 1. TEST DE PING Y BASE DE DATOS
        case 'ping':
        case 'test':
        case 'status':
            $pdo = getPosDB();
            respondJSON(200, "API POS en línea.", [
                'woocommerce' => 'Conectado',
                'database' => ($pdo !== null) ? 'Conectado a BD POS' : 'Error en BD POS'
            ]);
            break;

        // 2. OBTENER TASA BCV ACTUAL
        case 'get_bcv_rate':
            $tasa = getBCVRate();
            respondJSON(200, "Tasa BCV obtenida exitosamente.", [
                "tasa_bcv" => $tasa,
                "fecha" => date('Y-m-d H:i:s')
            ]);
            break;

        // 3. GUARDAR NUEVA TASA BCV MANUALMENTE
        case 'set_bcv_rate':
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            $nuevaTasa = floatval($input['tasa'] ?? $_GET['tasa'] ?? 0);

            if ($nuevaTasa <= 0) {
                respondJSON(400, "Monto de tasa no válido.");
            }

            $pdo = getPosDB();
            if ($pdo !== null) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO historial_tasas_bcv (tasa, fecha) VALUES (?, NOW())");
                    $stmt->execute([$nuevaTasa]);
                    respondJSON(200, "Tasa BCV guardada correctamente.", ["tasa_bcv" => $nuevaTasa]);
                } catch (Exception $e) {
                    respondJSON(500, "Error de base de datos: " . $e->getMessage());
                }
            } else {
                respondJSON(500, "No hay conexión a la base de datos POS.");
            }
            break;

        // 4. PRODUCTOS WOOCOMMERCE
        case 'products':
        case 'get_products':
            $page = intval($_GET['page'] ?? 1);
            $per_page = intval($_GET['per_page'] ?? 100);

            $products = callWooCommerceAPI('products', [
                'page' => $page,
                'per_page' => $per_page,
                'status' => 'publish'
            ]);

            if ($products === false) {
                respondJSON(500, "Error de comunicación con WooCommerce");
            }

            echo json_encode($products, JSON_UNESCAPED_UNICODE);
            exit();
            break;

        // 5. CONSULTAR CARTERAS DE TESORERÍA
        // 5. CONSULTAR CARTERAS DE TESORERÍA
        case 'get_tesoreria':
            $tasa = floatval($_GET['tasa'] ?? 0);
            if ($tasa <= 0) {
                $tasa = getBCVRate();
            }

            $carteras = [];
            $totalGeneralUSD = 0;
            $pdo = getPosDB();

            if ($pdo !== null) {
                try {
                    // Consulta limpia a la tabla verificada en phpMyAdmin
                    $stmt = $pdo->query("SELECT id, codigo_metodo, nombre, moneda, saldo_actual, permite_compra_moneda, activa FROM tesoreria_carteras ORDER BY id ASC");
                    $carteras = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($carteras as &$c) {
                        $saldo = floatval($c['saldo_actual'] ?? 0);
                        $moneda = strtoupper(trim($c['moneda'] ?? 'USD'));
                        
                        // Formatear montos a flotante para evitar cadenas numéricas en JS
                        $c['saldo_actual'] = $saldo;
                        
                        if ($moneda === 'BS' || $moneda === 'VEF') {
                            $c['equivalente_usd'] = ($tasa > 0) ? ($saldo / $tasa) : 0;
                        } else {
                            $c['equivalente_usd'] = $saldo;
                        }
                        $totalGeneralUSD += $c['equivalente_usd'];
                    }
                } catch (Exception $e) {
                    http_response_code(500);
                    echo json_encode(["status" => 500, "message" => "Error BD: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                    exit();
                }
            }

            // Estructura de respuesta con compatibilidad doble para el JS frontend
            echo json_encode([
                "status" => 200,
                "success" => true,
                "message" => "Datos obtenidos correctamente.",
                "tasa_bcv" => $tasa,
                "total_patrimonio_usd" => round($totalGeneralUSD, 2),
                "carteras" => $carteras,
                "data" => [
                    "tasa_bcv" => $tasa,
                    "total_patrimonio_usd" => round($totalGeneralUSD, 2),
                    "carteras" => $carteras
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit();
            break;

        // 6. SINCRONIZACIÓN DE VENTAS POS
        case 'sync_sales':
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            $ventas = $input['ventas'] ?? [];
            $procesadas = [];
            $pdo = getPosDB();

            if ($pdo !== null && !empty($ventas)) {
                try {
                    $pdo->exec("INSERT INTO sucursales (id, nombre, codigo) VALUES (1, 'Discreta Ciudad Bolívar', 'CBO-01') ON DUPLICATE KEY UPDATE id=id");

                    foreach ($ventas as $v) {
                        $uuid = $v['uuid'] ?? '';
                        if (empty($uuid)) continue;

                        $check = $pdo->prepare("SELECT id FROM ventas WHERE uuid = ?");
                        $check->execute([$uuid]);
                        if ($check->fetch()) {
                            $procesadas[] = $uuid;
                            continue;
                        }

                        $pdo->beginTransaction();

                        $tasaBCV = floatval($v['tasa_bcv'] ?? 1);
                        $fechaVenta = date('Y-m-d H:i:s', strtotime($v['fecha'] ?? 'now'));

                        $stmtVenta = $pdo->prepare("INSERT INTO ventas (uuid, sucursal_id, fecha_venta, subtotal_usd, total_usd, total_vef, tasa_bcv) VALUES (?, 1, ?, ?, ?, ?, ?)");
                        $stmtVenta->execute([
                            $uuid,
                            $fechaVenta,
                            floatval($v['total_usd'] ?? 0),
                            floatval($v['total_usd'] ?? 0),
                            floatval($v['total_vef'] ?? 0),
                            $tasaBCV
                        ]);

                        $stmtItem = $pdo->prepare("INSERT INTO ventas_detalles (venta_uuid, producto_id, nombre_producto, precio_unitario_usd, cantidad, subtotal_usd) VALUES (?, ?, ?, ?, ?, ?)");
                        if (isset($v['items']) && is_array($v['items'])) {
                            foreach ($v['items'] as $item) {
                                $precio = floatval($item['precio'] ?? 0);
                                $cant = intval($item['cantidad'] ?? 1);
                                $stmtItem->execute([
                                    $uuid,
                                    intval($item['id'] ?? 0),
                                    $item['name'] ?? 'Producto',
                                    $precio,
                                    $cant,
                                    ($precio * $cant)
                                ]);
                            }
                        }

                        if (isset($v['metodos_pago']) && is_array($v['metodos_pago'])) {
                            $stmtTesMov = $pdo->prepare("INSERT INTO tesoreria_movimientos (venta_uuid, metodo, monto_original, moneda, monto_equivalente_usd, estado_conciliacion) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmtUpdateCartera = $pdo->prepare("UPDATE tesoreria_carteras SET saldo_actual = saldo_actual + ? WHERE codigo_metodo = ?");

                            foreach ($v['metodos_pago'] as $metodo => $monto) {
                                $montoFloat = floatval($monto);
                                if ($montoFloat > 0) {
                                    $codigoMetodo = substr(strtolower(trim($metodo)), 0, 30);
                                    $moneda = ($codigoMetodo === 'pago_movil' || $codigoMetodo === 'tarjeta_lote_pend') ? 'Bs' : 'USD';
                                    $montoUSD = ($moneda === 'Bs') ? (($tasaBCV > 0) ? ($montoFloat / $tasaBCV) : 0) : $montoFloat;

                                    $stmtTesMov->execute([$uuid, $codigoMetodo, $montoFloat, $moneda, $montoUSD, 'liquidado']);
                                    $stmtUpdateCartera->execute([$montoFloat, $codigoMetodo]);
                                }
                            }
                        }

                        $pdo->commit();
                        $procesadas[] = $uuid;
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("Error insertando ventas: " . $e->getMessage());
                }
            } else {
                foreach ($ventas as $v) {
                    if (!empty($v['uuid'])) $procesadas[] = $v['uuid'];
                }
            }

            respondJSON(200, "Ventas sincronizadas exitosamente.", ["procesadas" => $procesadas]);
            break;

        default:
            respondJSON(400, "Endpoint no especificado o inválido.");
            break;
    }

} catch (Exception $e) {
    respondJSON(500, "Error interno del servidor: " . $e->getMessage());
}