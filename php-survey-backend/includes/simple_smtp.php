<?php
/**
 * Simple SMTP Client for PHP
 * Lightweight, no dependencies, perfect for shared hosting
 *
 * Supports:
 * - SMTP authentication (LOGIN, PLAIN)
 * - TLS/STARTTLS encryption
 * - HTML and plain text emails
 * - UTF-8 encoding
 */
class SimpleSMTP {
    private $host;
    private $port;
    private $username;
    private $password;
    private $encryption;
    private $socket;
    private $lastError = '';

    private const CRLF = "\r\n";
    private const TIMEOUT = 30;

    /**
     * Constructor
     *
     * @param string $host SMTP server hostname
     * @param int $port SMTP port (587 for TLS, 465 for SSL)
     * @param string $username SMTP username
     * @param string $password SMTP password
     * @param string $encryption Encryption type: 'tls', 'ssl', or 'none'
     */
    public function __construct(string $host, int $port, string $username, string $password, string $encryption = 'tls') {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->encryption = strtolower($encryption);
    }

    /**
     * Send an email
     *
     * @param string $from From email address
     * @param string $fromName From name
     * @param string $to To email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML body
     * @param string $textBody Plain text body
     * @param string $replyTo Reply-to email address
     * @param string $replyToName Reply-to name
     * @return bool Success
     */
    public function send(string $from, string $fromName, string $to, string $subject, string $htmlBody, string $textBody = '', string $replyTo = '', string $replyToName = ''): bool {
        try {
            // Connect to SMTP server
            if (!$this->connect()) {
                return false;
            }

            // Authenticate
            if (!$this->authenticate()) {
                $this->disconnect();
                return false;
            }

            // Send MAIL FROM
            if (!$this->command("MAIL FROM:<{$from}>", 250)) {
                $this->disconnect();
                return false;
            }

            // Send RCPT TO
            if (!$this->command("RCPT TO:<{$to}>", 250)) {
                $this->disconnect();
                return false;
            }

            // Send DATA
            if (!$this->command("DATA", 354)) {
                $this->disconnect();
                return false;
            }

            // Build email message
            $message = $this->buildMessage($from, $fromName, $to, $subject, $htmlBody, $textBody, $replyTo, $replyToName);

            // Send message data
            if (!$this->sendData($message)) {
                $this->disconnect();
                return false;
            }

            // End with CRLF.CRLF
            if (!$this->command(".", 250)) {
                $this->disconnect();
                return false;
            }

            // Disconnect
            $this->disconnect();

            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->disconnect();
            return false;
        }
    }

    /**
     * Get last error message
     *
     * @return string Error message
     */
    public function getLastError(): string {
        return $this->lastError;
    }

    /**
     * Connect to SMTP server
     *
     * @return bool Success
     */
    private function connect(): bool {
        $errno = 0;
        $errstr = '';

        // Determine connection protocol
        if ($this->encryption === 'ssl') {
            $host = 'ssl://' . $this->host;
        } else {
            $host = $this->host;
        }

        // Open socket connection
        $this->socket = fsockopen($host, $this->port, $errno, $errstr, self::TIMEOUT);

        if (!$this->socket) {
            $this->lastError = "Failed to connect: {$errstr} ({$errno})";
            return false;
        }

        stream_set_timeout($this->socket, self::TIMEOUT);

        // Read greeting
        if (!$this->readResponse(220)) {
            return false;
        }

        // Send EHLO
        if (!$this->command("EHLO {$this->host}", 250)) {
            return false;
        }

        // Start TLS if needed
        if ($this->encryption === 'tls') {
            if (!$this->command("STARTTLS", 220)) {
                return false;
            }

            // Enable TLS encryption
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->lastError = "Failed to enable TLS encryption";
                return false;
            }

            // Send EHLO again after TLS
            if (!$this->command("EHLO {$this->host}", 250)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Authenticate with SMTP server
     *
     * @return bool Success
     */
    private function authenticate(): bool {
        // Try AUTH LOGIN
        if (!$this->command("AUTH LOGIN", 334)) {
            return false;
        }

        // Send username (base64 encoded)
        if (!$this->command(base64_encode($this->username), 334)) {
            return false;
        }

        // Send password (base64 encoded)
        if (!$this->command(base64_encode($this->password), 235)) {
            return false;
        }

        return true;
    }

    /**
     * Send SMTP command and check response
     *
     * @param string $command Command to send
     * @param int $expectedCode Expected response code
     * @return bool Success
     */
    private function command(string $command, int $expectedCode): bool {
        if (!$this->socket) {
            $this->lastError = "Not connected";
            return false;
        }

        // Send command
        fwrite($this->socket, $command . self::CRLF);

        // Read response
        return $this->readResponse($expectedCode);
    }

    /**
     * Read SMTP response
     *
     * @param int $expectedCode Expected response code
     * @return bool Success
     */
    private function readResponse(int $expectedCode): bool {
        if (!$this->socket) {
            $this->lastError = "Not connected";
            return false;
        }

        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;

            // Check if this is the last line (format: "250 OK" vs "250-Extended")
            if (preg_match('/^(\d{3}) /', $line, $matches)) {
                $code = (int)$matches[1];

                if ($code !== $expectedCode) {
                    $this->lastError = "Unexpected response: {$response}";
                    return false;
                }

                return true;
            }
        }

        $this->lastError = "No response from server";
        return false;
    }

    /**
     * Send data (without checking response)
     *
     * @param string $data Data to send
     * @return bool Success
     */
    private function sendData(string $data): bool {
        if (!$this->socket) {
            $this->lastError = "Not connected";
            return false;
        }

        // Split data into lines and escape dots at beginning
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            // Escape dots at beginning of line (SMTP transparency)
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }

            fwrite($this->socket, $line . self::CRLF);
        }

        return true;
    }

    /**
     * Disconnect from SMTP server
     *
     * @return void
     */
    private function disconnect(): void {
        if ($this->socket) {
            fwrite($this->socket, "QUIT" . self::CRLF);
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Build email message with headers and body
     *
     * @param string $from From email
     * @param string $fromName From name
     * @param string $to To email
     * @param string $subject Subject
     * @param string $htmlBody HTML body
     * @param string $textBody Text body
     * @param string $replyTo Reply-to email
     * @param string $replyToName Reply-to name
     * @return string Email message
     */
    private function buildMessage(string $from, string $fromName, string $to, string $subject, string $htmlBody, string $textBody, string $replyTo, string $replyToName): string {
        $boundary = 'b_' . bin2hex(random_bytes(16));

        // Encode headers
        $fromHeader = $fromName ? $this->encodeHeader($fromName) . " <{$from}>" : $from;
        $subjectHeader = $this->encodeHeader($subject);

        // Build headers
        $headers = [];
        $headers[] = "From: {$fromHeader}";
        $headers[] = "To: {$to}";
        $headers[] = "Subject: {$subjectHeader}";

        if ($replyTo) {
            $replyToHeader = $replyToName ? $this->encodeHeader($replyToName) . " <{$replyTo}>" : $replyTo;
            $headers[] = "Reply-To: {$replyToHeader}";
        }

        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $headers[] = "Date: " . date('r');
        $headers[] = "Message-ID: <" . bin2hex(random_bytes(16)) . "@{$this->host}>";

        // Build body
        $body = [];

        // Plain text part
        if (!$textBody) {
            $textBody = strip_tags(preg_replace('/<br\s*\/?\>/i', "\n", $htmlBody));
        }

        $body[] = "--{$boundary}";
        $body[] = "Content-Type: text/plain; charset=UTF-8";
        $body[] = "Content-Transfer-Encoding: base64";
        $body[] = "";
        $body[] = chunk_split(base64_encode($textBody));

        // HTML part
        $body[] = "--{$boundary}";
        $body[] = "Content-Type: text/html; charset=UTF-8";
        $body[] = "Content-Transfer-Encoding: base64";
        $body[] = "";
        $body[] = chunk_split(base64_encode($htmlBody));

        $body[] = "--{$boundary}--";

        // Combine headers and body
        return implode(self::CRLF, $headers) . self::CRLF . self::CRLF . implode(self::CRLF, $body);
    }

    /**
     * Encode header using MIME encoding
     *
     * @param string $text Text to encode
     * @return string Encoded text
     */
    private function encodeHeader(string $text): string {
        // Check if encoding is needed
        if (mb_check_encoding($text, 'ASCII')) {
            return $text;
        }

        return mb_encode_mimeheader($text, 'UTF-8', 'B');
    }
}
?>
