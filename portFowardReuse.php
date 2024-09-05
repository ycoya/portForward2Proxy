<?php
/**
 * Aqu'i se trat'o de que cuando msgSock se cae, tratar de levantar esto sin caer la conexi'on de salida(sockSend) y reusarla. Tambi'en
 * a la inversa, si se cae la conexi'on de salida, tratar de levantarla de nuevo y seguir usando el msgSock de entrada.
 */
require('./lib/ConnectionHandlerReuse.php');

$localAddress = '127.0.0.1';
$remoteAddress = '127.0.0.1';
$lclPort = 8081;
$dstPort = 8083;



error_reporting(E_ALL);

/* Permitir al script esperar para conexiones. */
set_time_limit(0);

/* Activar el volcado de salida implícito, así veremos lo que estamos obteniendo
 * mientras llega. */
ob_implicit_flush();


//Preparing sockets
$sockListen = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sockListen == false) {
    echo "socket_create() listen falló: razón: " . socket_strerror(socket_last_error()) . "\n";
    exit;
}

if (socket_bind($sockListen, $localAddress, $lclPort) === false) {
    echo "socket_bind() para listen falló: razón: " . socket_strerror(socket_last_error($sockListen)) . "\n";
    exit;
}

//Starting to listen
if (socket_listen($sockListen, 5) === false) {
    echo "socket_listen() listen failed: reason: " . socket_strerror(socket_last_error($sockListen)) . "\n";
    exit;
}


socket_set_nonblock($sockListen);
$connections = [];
$connectionsToRemove = [];

$checkForNewConn = true;
while (true) {
    // echo count($connections);
    if ($checkForNewConn) {
        $con = new ConnectionHandlerReuse($sockListen, $remoteAddress, $dstPort);
        if ($con->isConnected()) {
            $connections[] = $con;
        }
    }

    foreach ($connections as $key => $connection) {

        // echo "forwarding...\n";
        $connection->forward();
        // echo "forwardingFinished...\n";

        if (!$connection->isConnected()) {
            $connectionsToRemove[] = $key;
        } else if (!$connection->isSockSendStatusConnected()) {
            // echo "lost connection to server\n";
            $connection->startConx2Server();
        } else if (!$connection->isMsgSockStatusConnected()) {
            // echo "lost connection to client\n";
            $checkForNewConn = false;
            $connection->startAcceptCnx();
            if ($connection->isMsgSockStatusConnected()) {
                $checkForNewConn = true;
            }
        }
    }

    foreach ($connectionsToRemove as $index) {
        // echo "removing $index...\n";
        unset($connections[$index]);
    }
    if (count($connectionsToRemove) != 0) {
        $connectionsToRemove = [];
    }
    usleep(500);
}
