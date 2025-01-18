<?php

/**
 * Realtime bandwidth meter with modern JavaScript and Chart.js
 *
 * Original authors:
 * @author Andreas Beder <andreas@codejungle.org>
 * @author Nikola Petkanski <nikola@petkanski.com>
 */

// Allow CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

define('OS_LINUX', 'Linux');
define('OS_FREEBSD', 'FreeBSD');
define('OS_OSX', 'Darwin');
define('PATH_NETSTAT', '/usr/sbin/netstat');

/**
 * Validate if an interface exists and is active
 */
function validateInterface($interface) {
    if (isLinux()) {
        return file_exists("/sys/class/net/$interface");
    }

    if (isFreeBSD() || isOSX()) {
        exec(sprintf("%s -I %s 2>/dev/null", PATH_NETSTAT, $interface), $output, $return_var);
        return $return_var === 0;
    }

    return false;
}

/**
 * Get the default network interface based on the operating system
 */
function getDefaultInterface() {
    if (isLinux()) {
        // Try to get the default route interface from /proc/net/route
        if (file_exists('/proc/net/route')) {
            $routes = file('/proc/net/route');
            foreach ($routes as $route) {
                $fields = preg_split('/\s+/', $route);
                if (isset($fields[1]) && $fields[1] === '00000000') {
                    return trim($fields[0]);
                }
            }
        }

        // Fallback: try to parse ip route command
        exec('ip route show default 2>/dev/null', $output, $return_var);
        if ($return_var === 0 && !empty($output)) {
            if (preg_match('/dev\s+(\w+)/', $output[0], $matches)) {
                return $matches[1];
            }
        }
    } elseif (isOSX() || isFreeBSD()) {
        // For macOS and FreeBSD
        exec('route -n get default 2>/dev/null | grep interface', $output, $return_var);
        if ($return_var === 0 && !empty($output)) {
            if (preg_match('/interface:\s*(\w+)/', $output[0], $matches)) {
                return $matches[1];
            }
        }
    }

    // Fallback to common interfaces if we couldn't detect
    $common_interfaces = ['eth0', 'en0', 'ens33', 'wlan0', 'em0'];
    foreach ($common_interfaces as $interface) {
        if (file_exists("/sys/class/net/$interface") ||
            (exec(sprintf("%s -I %s 2>/dev/null", PATH_NETSTAT, $interface), $out, $ret) && $ret === 0)) {
            return $interface;
        }
    }

    throw new Exception('Could not detect default network interface');
}

// Get interface from query string or use default
$interface = null;
if (isset($_GET['interface']) && !empty($_GET['interface'])) {
    $requested_interface = trim($_GET['interface']);
    if (validateInterface($requested_interface)) {
        $interface = $requested_interface;
    } else {
        die(json_encode([
            'error' => "Requested interface '$requested_interface' not found or not active",
            'status' => 'error'
        ]));
    }
}

// If no interface specified or invalid, try to detect default
if ($interface === null) {
    try {
        $interface = getDefaultInterface();
    } catch (Exception $e) {
        die(json_encode([
            'error' => $e->getMessage(),
            'status' => 'error'
        ]));
    }
}

session_start();

$rx[] = getInterfaceReceivedBytes($interface);
$tx[] = getInterfaceSentBytes($interface);
sleep(1);
$rx[] = getInterfaceReceivedBytes($interface);
$tx[] = getInterfaceSentBytes($interface);

$tbps = $tx[1] - $tx[0];
$rbps = $rx[1] - $rx[0];

$round_rx = round($rbps/1024, 2);
$round_tx = round($tbps/1024, 2);

$time = date("U")."000";
$_SESSION['rx'][] = array($time, $round_rx);
$_SESSION['tx'][] = array($time, $round_tx);

// Clean up old data more aggressively to prevent memory issues
if (count($_SESSION['rx']) > 30) {
    $_SESSION['rx'] = array_slice($_SESSION['rx'], -30, 30, true);
    $_SESSION['tx'] = array_slice($_SESSION['tx'], -30, 30, true);
}

// Ensure proper JSON response with additional metadata
echo json_encode([
    'label' => $interface,
    'data' => array_values($_SESSION['rx']),
    'tx_data' => array_values($_SESSION['tx']),
    'timestamp' => $time,
    'current' => [
        'rx' => $round_rx,
        'tx' => $round_tx
    ],
    'status' => 'success'
], JSON_NUMERIC_CHECK);

function isOSX() {
    $uname = explode(' ', php_uname());
    return $uname[0] === OS_OSX;
}

function isLinux() {
    $uname = explode(' ', php_uname());
    return $uname[0] === OS_LINUX;
}

function isFreeBSD() {
    $uname = explode(' ', php_uname());
    return $uname[0] === OS_FREEBSD;
}

function getInterfaceReceivedBytes($interface) {
    if (isLinux()) {
        $filepath = '/sys/class/net/%s/statistics/rx_bytes';
        $output = file_get_contents(sprintf($filepath, $interface));

        return $output;
    }

    if (isFreeBSD() || isOSX()) {
        $command = "%s -ibn| grep %s | grep Link | awk '{print $7}'";
        exec(sprintf($command, PATH_NETSTAT, $interface), $output);

        return array_shift($output);
    }

    throw new Exception('Unable to guess OS');
}

function getInterfaceSentBytes($interface) {
    if (isLinux()) {
        $filepath = '/sys/class/net/%s/statistics/tx_bytes';
        $output = file_get_contents(sprintf($filepath, $interface));

        return $output;
    }

    if (isFreeBSD() || isOSX()) {
        $command = "%s -ibn| grep %s | grep Link | awk '{print $10}'";
        exec(sprintf($command, PATH_NETSTAT, $interface), $output);

        return array_shift($output);
    }

    throw new Exception('Unable to guess OS');
}
