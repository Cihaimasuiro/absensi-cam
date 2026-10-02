<?php

namespace App\Domain\Device\Services;

use Exception;

class NetworkScannerService
{
    private const UDP_PORT = 55555;
    private const DISCOVER_PAYLOAD = "ABSENSI_DISCOVER";
    private const TIMEOUT_SECONDS = 2;

    /**
     * Broadcasts a UDP message and waits for replies from Edge Engines.
     *
     * @return array
     */
    public function scan(): array
    {
        $devices = [];
        
        // Create UDP socket
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$socket) {
            return [];
        }

        // Enable broadcast
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        
        // Set timeout for receiving
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => self::TIMEOUT_SECONDS, "usec" => 0]);

        // Send broadcast
        $broadcastAddress = '255.255.255.255';
        socket_sendto($socket, self::DISCOVER_PAYLOAD, strlen(self::DISCOVER_PAYLOAD), 0, $broadcastAddress, self::UDP_PORT);

        // Listen for responses
        $startTime = time();
        while ((time() - $startTime) < self::TIMEOUT_SECONDS) {
            $buffer = '';
            $ip = '';
            $port = 0;
            
            // socket_recvfrom will block until timeout
            $bytes = @socket_recvfrom($socket, $buffer, 1024, 0, $ip, $port);
            
            if ($bytes !== false && $buffer) {
                try {
                    $data = json_decode($buffer, true);
                    if ($data && isset($data['device_id'])) {
                        // Ensure unique device list
                        $devices[$data['device_id']] = [
                            'device_id' => $data['device_id'],
                            'ip_address' => $ip,
                            'status' => $data['status'] ?? 'unknown',
                            'port' => $data['port'] ?? 5000,
                        ];
                    }
                } catch (Exception $e) {
                    // Ignore malformed JSON
                }
            }
        }
        
        socket_close($socket);
        
        return array_values($devices);
    }
}
