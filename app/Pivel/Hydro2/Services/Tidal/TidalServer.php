<?php

namespace Pivel\Hydro2\Services\Tidal;

use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Models\Identity\User;
use Pivel\Hydro2\Models\Uuid;
use Pivel\Hydro2\Services\ILoggerService;
use Socket;

class TidalServer implements ITidalServer
{
    private Hydro2 $_app;
    private ILoggerService $_logger;
    private string $_address;

    private array $_subscriptions = [];
    /**
     * Key is token, value is an array of connections for that token.
     * @var array<string, array<int, mixed>> $_authenticatedConnections
     */
    private array $_authenticatedConnections = [];
    /**
     * Key is event, value is an array of client tokens for that event.
     * @var array<string, array<int, mixed>> $_clientSubscriptions
     */
    private array $_clientSubscriptions = [];

    // key is unique string, value is TidalConnection
    // contains all connections, even if they haven't submitted a token yet.
    private array $_connections = [];

    public function __construct(Hydro2 $app, ILoggerService $logger, string $address)
    {
        $this->_app = $app;
        $this->_logger = $logger;
        $this->_address = $address;
    }

    public function Start(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) {
            $this->_logger->Error("TidalServer", "Failed to create socket: " . socket_strerror(socket_last_error()));
            return;
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->_logger->Error("TidalServer", "Failed to set socket option: " . socket_strerror(socket_last_error($socket)));
            return;
        }

        $address_parts = explode(':', $this->_address);
        $ip = $address_parts[0];
        $port = (int)$address_parts[1] ?? 8080;

        if (!socket_bind($socket, $ip, $port)) {
            $this->_logger->Error("TidalServer", "Failed to bind socket: " . socket_strerror(socket_last_error($socket)));
            return;
        }

        if (!socket_listen($socket)) {
            $this->_logger->Error("TidalServer", "Failed to listen on socket: " . socket_strerror(socket_last_error($socket)));
            return;
        }

        $this->loop($socket);
    }

    public function Stop(): void
    {
        throw new \Exception("Not implemented");
    }

    public function Subscribe(string $event, callable $callback): void
    {
        $this->_subscriptions[$event][] = $callback;
    }

    public function Publish(string $event, object|null $data = null): void
    {
        throw new \Exception("Not implemented");
    }

    public function SendToAll(string $event, object|null $data = null): void
    {
        foreach ($this->_authenticatedConnections as $token => $connections) {
            $this->SendToClient($token, $event, $data);
        }
    }

    public function SendToUser(User $user, string $event, object|null $data = null): void
    {
        $this->SendToUserId($user->Id, $event, $data);
    }

    public function SendToUserId(Uuid $user, string $event, object|null $data = null): void
    {
        throw new \Exception("Not implemented");
    }

    public function SendToClient(string $token, string $event, object|null $data = null): void
    {
        if (!isset($this->_authenticatedConnections[$token])) {
            return;
        }

        foreach ($this->_authenticatedConnections[$token] as $connection) {
            // send the event and data to the connection
            //$connection->send(json_encode(['event' => $event, 'data' => $data]));
        }
    }

    // ======= Socket management methods =======
    private function loop(Socket $socket)
    {
        while (true) {
            $r = $w = $e = array_merge(
                [$socket],
                array_map(function(TidalConnection $conn) { return $conn->socket; }, $this->_connections)
            );
            socket_select($r, $w, $e, 1);
            foreach ($r as $read_socket) {
                if ($read_socket === $socket) {
                    $client_socket = socket_accept($socket);
                    if (!$client_socket) {
                        $this->_logger->Error("TidalServer", "Failed to accept client connection: " . socket_strerror(socket_last_error($socket)));
                        continue;
                    }

                    $this->handleNewConnection($client_socket);
                    $this->_logger->Info("TidalServer", "New client connected.");
                    continue;
                }

                $n = socket_recv($read_socket, $buffer, 2048, 0);
                if ($n === false) {
                    switch (socket_last_error($read_socket)) {
                        case SOCKET_ENETRESET:
                        case SOCKET_ECONNABORTED:
                        case SOCKET_ECONNRESET:
                        case SOCKET_ESHUTDOWN:
                        case SOCKET_ETIMEDOUT:
                        case SOCKET_ECONNREFUSED:
                        case SOCKET_EHOSTDOWN:
                        case SOCKET_EHOSTUNREACH:
                        case SOCKET_EREMOTEIO:
                        case 125: // ECANCELED
                            $this->_logger->Info("TidalServer", "Client disconnected.");
                            $this->disconnectClient($read_socket);
                            break;
                        default:
                            $this->_logger->Error("TidalServer", "Socket error: " . socket_strerror(socket_last_error($read_socket)));
                    }
                    continue;
                }

                if ($n === 0) {
                    $this->_logger->Info("TidalServer", "Client disconnected.");
                    $this->disconnectClient($read_socket);
                    continue;
                }

                // get TidalConnection from socket
                $connection = $this->getTidalConnectionFromSocket($read_socket);
                if (!$connection) {
                    $this->_logger->Error("TidalServer", "Failed to get TidalConnection from socket.");
                    continue;
                }

                // if handshake is not complete, perform handshake
                // add buffer to connection's buffer
                // if buffer contains a complete message, process it
            }
        }
    }


    // unadjusted code
    private function process(TidalConnection $connection, string $message)
    {
        echo "Processing message from user {$connection->id}: $message\n";
        //$connection->processMessage($this, $message);
    }

    private function onConnected($user) {}

    private function onClosed($user) {}

    private function handleNewConnection(Socket $socket)
    {
        $connection = new TidalConnection($socket, uniqid('u'));
        // = new WebSocketUser(uniqid('u'), $socket);
        $this->_connections[$connection->id] = $connection;
    }

    private function disconnectClient(Socket $socket, $triggerClosed = true, $sockErrNo = null)
    {
        $connection = $this->getTidalConnectionFromSocket($socket);

        if ($connection === null) {
            return;
        }

        // remove from list of all connections
        unset($this->_connections[$connection->id]);

        // remove from authenticatedconnections[$connection->token]
        if (!is_null($connection->token) && isset($this->_authenticatedConnections[$connection->token])) {
            $index = array_search($connection, $this->_authenticatedConnections[$connection->token]);
            if ($index !== false) {
                unset($this->_authenticatedConnections[$connection->token][$index]);
            }
        }

        // remove from clientSubscriptions[$event] for each event in $connection->subscriptions
        foreach ($connection->subscriptions as $event) {
            if (isset($this->_clientSubscriptions[$event])) {
                $index = array_search($connection->token, $this->_clientSubscriptions[$event]);
                if ($index !== false) {
                    unset($this->_clientSubscriptions[$event][$index]);
                }
            }
        }

        if (!is_null($sockErrNo)) {
            socket_clear_error($socket);
        }

        if ($triggerClosed) {
            $this->_logger->Info("TidalServer", "Client disconnected. Triggering closed event.");
            $this->onClosed($connection);
            socket_close($connection->socket);
        } else {
            $message = $this->frame('', $connection, 'close');
            socket_write($connection->socket, $message, strlen($message));
        }
    }

    private function getTidalConnectionFromSocket(Socket $socket) : TidalConnection|null
    {
        foreach ($this->_connections as $id => $connection) {
            if ($connection->socket == $socket) {
                return $connection;
            }
        }

        return null;
    }

    private function doHandshake(TidalConnection $connection, string $buffer)
    {
        $magicGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
        $headers = array();
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            if (strpos($line, ":") !== false) {
                $header = explode(":", $line, 2);
                $headers[strtolower(trim($header[0]))] = trim($header[1]);
            } elseif (stripos($line, "get ") !== false) {
                preg_match("/GET (.*) HTTP/i", $buffer, $reqResource);
                $headers['get'] = trim($reqResource[1]);
            }
        }
        if (isset($headers['get'])) {
            //$connection->requestedResource = $headers['get'];
        } else {
            // todo: fail the connection
            $handshakeResponse = "HTTP/1.1 405 Method Not Allowed\r\n\r\n";
        }
        if (!isset($headers['host']) || !$this->checkHost($headers['host'])) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['upgrade']) || strtolower($headers['upgrade']) != 'websocket') {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === FALSE) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['sec-websocket-key'])) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        } else {
        }
        if (!isset($headers['sec-websocket-version']) || strtolower($headers['sec-websocket-version']) != 13) {
            $handshakeResponse = "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocketVersion: 13";
        }
        if (!isset($headers['origin']) || !$this->checkOrigin($headers['origin'])) {
            $handshakeResponse = "HTTP/1.1 403 Forbidden";
        }
        if (isset($headers['sec-websocket-protocol']) && !$this->checkWebsocProtocol($headers['sec-websocket-protocol'])) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (isset($headers['sec-websocket-extensions']) && !$this->checkWebsocExtensions($headers['sec-websocket-extensions'])) {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }

        // Done verifying the _required_ headers and optionally required headers.

        if (isset($handshakeResponse)) {
            socket_write($connection->socket, $handshakeResponse, strlen($handshakeResponse));
            $this->disconnectClient($connection->socket);
            return;
        }

        $connection->headers = $headers;
        $connection->isHandshakeComplete = true;

        $webSocketKeyHash = sha1($headers['sec-websocket-key'] . $magicGUID);

        $rawToken = "";
        for ($i = 0; $i < 20; $i++) {
            $rawToken .= chr(hexdec(substr($webSocketKeyHash, $i * 2, 2)));
        }
        $handshakeToken = base64_encode($rawToken) . "\r\n";

        $subProtocol = (isset($headers['sec-websocket-protocol'])) ? $this->processProtocol($headers['sec-websocket-protocol']) : "";
        $extensions = (isset($headers['sec-websocket-extensions'])) ? $this->processExtensions($headers['sec-websocket-extensions']) : "";

        $handshakeResponse = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $handshakeToken$subProtocol$extensions\r\n";
        socket_write($connection->socket, $handshakeResponse, strlen($handshakeResponse));
        $this->onConnected($connection);
    }

    protected function checkHost($hostName)
    {
        return true; // Override and return false if the host is not one that you would expect.
        // Ex: You only want to accept hosts from the my-domain.com domain,
        // but you receive a host from malicious-site.com instead.
    }

    protected function checkOrigin($origin)
    {
        return true; // Override and return false if the origin is not one that you would expect.
    }

    protected function checkWebsocProtocol($protocol)
    {
        return true; // Override and return false if a protocol is not found that you would expect.
    }

    protected function checkWebsocExtensions($extensions)
    {
        return true; // Override and return false if an extension is not found that you would expect.
    }

    protected function processProtocol($protocol)
    {
        return ""; // return either "Sec-WebSocket-Protocol: SelectedProtocolFromClientList\r\n" or return an empty string.  
        // The carriage return/newline combo must appear at the end of a non-empty string, and must not
        // appear at the beginning of the string nor in an otherwise empty string, or it will be considered part of 
        // the response body, which will trigger an error in the client as it will not be formatted correctly.
    }

    protected function processExtensions($extensions)
    {
        return ""; // return either "Sec-WebSocket-Extensions: SelectedExtensions\r\n" or return an empty string.
    }

    private function splitPacket(int $length, string $packet, TidalConnection $connection)
    {
        //add PartialPacket and calculate the new $length
        if ($connection->handlingPartialPacket) {
            $packet = $connection->buffer . $packet;
            $connection->handlingPartialPacket = false;
            $length = strlen($packet);
        }
        $fullpacket = $packet;
        $frame_pos = 0;
        $frame_id = 1;

        while ($frame_pos < $length) {
            $headers = $this->extractHeaders($packet);
            $headers_size = $this->calcoffset($headers);
            $framesize = $headers['length'] + $headers_size;

            //split frame from packet and process it
            $frame = substr($fullpacket, $frame_pos, $framesize);

            if (($message = $this->deframe($frame, $connection)) !== FALSE) {
                if ($connection->hasSentClose) {
                    $this->disconnectClient($connection->socket);
                } else {
                    if ((preg_match('//u', $message)) || ($headers['opcode'] == 2)) {
                        //$this->stdout("Text msg encoded UTF-8 or Binary msg\n".$message); 
                        $this->process($connection, $message);
                    } else {
                        $this->_logger->Warn("TidalServer", "Text msg not encoded UTF-8");
                    }
                }
            }
            //get the new position also modify packet data
            $frame_pos += $framesize;
            $packet = substr($fullpacket, $frame_pos);
            $frame_id++;
        }
    }

    public function send(TidalConnection $connection, string $message)
    {
        if ($connection->isHandshakeComplete) {
            $message = $this->frame($message, $connection);
            $result = socket_write($connection->socket, $message, strlen($message));
        } else {
            // User has not yet performed their handshake.  Store for sending later.
            //$holdingMessage = array('user' => $connection, 'message' => $message);
            //$this->heldMessages[] = $holdingMessage;
        }
    }

    private function frame(string $message, TidalConnection $connection, string $messageType = "text", bool $messageContinues = false)
    {
        $b1 = 0;
        switch ($messageType) {
            case 'continuous':
                $b1 = 0;
                break;
            case 'text':
                $b1 = ($connection->sendingContinuous) ? 0 : 1;
                break;
            case 'binary':
                $b1 = ($connection->sendingContinuous) ? 0 : 2;
                break;
            case 'close':
                $b1 = 8;
                break;
            case 'ping':
                $b1 = 9;
                break;
            case 'pong':
                $b1 = 10;
                break;
        }
        if ($messageContinues) {
            $connection->sendingContinuous = true;
        } else {
            $b1 += 128;
            $connection->sendingContinuous = false;
        }

        $length = strlen($message);
        $lengthField = "";
        if ($length < 126) {
            $b2 = $length;
        } elseif ($length < 65536) {
            $b2 = 126;
            $hexLength = dechex($length);
            //$this->stdout("Hex Length: $hexLength");
            if (strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;

            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 2) {
                $lengthField = chr(0) . $lengthField;
            }
        } else {
            $b2 = 127;
            $hexLength = dechex($length);
            if (strlen($hexLength) % 2 == 1) {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;

            for ($i = $n; $i >= 0; $i = $i - 2) {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 8) {
                $lengthField = chr(0) . $lengthField;
            }
        }

        return chr($b1) . chr($b2) . $lengthField . $message;
    }

    protected function calcoffset(array $headers)
    {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } elseif ($headers['length'] > 125) {
            $offset += 2;
        }
        return $offset;
    }

    protected function deframe(string $message, TidalConnection &$connection)
    {
        //echo $this->strtohex($message);
        $headers = $this->extractHeaders($message);
        $pongReply = false;
        $willClose = false;
        switch ($headers['opcode']) {
            case 0:
            case 1:
            case 2:
                break;
            case 8:
                // todo: close the connection
                $connection->hasSentClose = true;
                return "";
            case 9:
                $pongReply = true;
            case 10:
                break;
            default:
                //$this->disconnect($user); // todo: fail connection
                $willClose = true;
                break;
        }

        /* Deal by split_packet() as now deframe() do only one frame at a time.
        if ($user->handlingPartialPacket) {
          $message = $user->partialBuffer . $message;
          $user->handlingPartialPacket = false;
          return $this->deframe($message, $user);
        }
        */

        if ($this->checkRSVBits($headers, $connection)) {
            return false;
        }

        if ($willClose) {
            // todo: fail the connection
            return false;
        }

        $payload = $connection->partialMessage . $this->extractPayload($message, $headers);

        if ($pongReply) {
            $reply = $this->frame($payload, $connection, 'pong');
            socket_write($connection->socket, $reply, strlen($reply));
            return false;
        }
        if ($headers['length'] > strlen($this->applyMask($headers, $payload))) {
            $connection->handlingPartialPacket = true;
            $connection->buffer = $message;
            return false;
        }

        $payload = $this->applyMask($headers, $payload);

        if ($headers['fin']) {
            $connection->partialMessage = "";
            return $payload;
        }
        $connection->partialMessage = $payload;
        return false;
    }

    protected function extractHeaders(string $message): array
    {
        $header = array(
            'fin'     => $message[0] & chr(128),
            'rsv1'    => $message[0] & chr(64),
            'rsv2'    => $message[0] & chr(32),
            'rsv3'    => $message[0] & chr(16),
            'opcode'  => ord($message[0]) & 15,
            'hasmask' => $message[1] & chr(128),
            'length'  => 0,
            'mask'    => ""
        );
        $header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);

        if ($header['length'] == 126) {
            if ($header['hasmask']) {
                $header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
            }
            $header['length'] = ord($message[2]) * 256
                + ord($message[3]);
        } elseif ($header['length'] == 127) {
            if ($header['hasmask']) {
                $header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
            }
            $header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256
                + ord($message[3]) * 65536 * 65536 * 65536
                + ord($message[4]) * 65536 * 65536 * 256
                + ord($message[5]) * 65536 * 65536
                + ord($message[6]) * 65536 * 256
                + ord($message[7]) * 65536
                + ord($message[8]) * 256
                + ord($message[9]);
        } elseif ($header['hasmask']) {
            $header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
        }
        //echo $this->strtohex($message);
        //$this->printHeaders($header);
        return $header;
    }

    protected function extractPayload(string $message, array $headers): string
    {
        $offset = 2;
        if ($headers['hasmask']) {
            $offset += 4;
        }
        if ($headers['length'] > 65535) {
            $offset += 8;
        } elseif ($headers['length'] > 125) {
            $offset += 2;
        }
        return substr($message, $offset);
    }

    protected function applyMask(array $headers, string $payload): string
    {
        $effectiveMask = "";
        if ($headers['hasmask']) {
            $mask = $headers['mask'];
        } else {
            return $payload;
        }

        while (strlen($effectiveMask) < strlen($payload)) {
            $effectiveMask .= $mask;
        }
        while (strlen($effectiveMask) > strlen($payload)) {
            $effectiveMask = substr($effectiveMask, 0, -1);
        }
        return $effectiveMask ^ $payload;
    }

    protected function checkRSVBits(array $headers, TidalConnection $connection): bool
    { // override this method if you are using an extension where the RSV bits are used.
        if (ord($headers['rsv1']) + ord($headers['rsv2']) + ord($headers['rsv3']) > 0) {
            //$this->disconnect($connection); // todo: fail connection
            return true;
        }
        return false;
    }

    protected function strtohex(string $str): string
    {
        $strout = "";
        for ($i = 0; $i < strlen($str); $i++) {
            $strout .= (ord($str[$i]) < 16) ? "0" . dechex(ord($str[$i])) : dechex(ord($str[$i]));
            $strout .= " ";
            if ($i % 32 == 7) {
                $strout .= ": ";
            }
            if ($i % 32 == 15) {
                $strout .= ": ";
            }
            if ($i % 32 == 23) {
                $strout .= ": ";
            }
            if ($i % 32 == 31) {
                $strout .= "\n";
            }
        }
        return $strout . "\n";
    }
}
