<?php
declare(strict_types=1);

namespace Noxdk\MonologJournalHandler;

use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Socket;
use Stringable;
use Throwable;

class JournalHandler extends AbstractProcessingHandler
{
    private const FORMAT = '%level_name%: %message% %context% %extra%';

    private const RESERVED_FIELDS = [
        'MESSAGE',
        'PRIORITY',
        'SYSLOG_IDENTIFIER',
    ];

    private string $path;
    private bool $isConnected = false;
    private ?Socket $socket = null;

    public function __construct(string           $path = '/run/systemd/journal/socket',
                                int|string|Level $level = Level::Debug,
                                bool             $bubble = true,
    )
    {
        parent::__construct($level, $bubble);
        $this->path = $path;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
        }
        parent::close();
    }

    protected function write(LogRecord $record): void
    {
        $this->connectIfNotConnected();
        $message = $this->encodeMessage($record);
        $this->writeToSocket($message);
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter(self::FORMAT, null, false, true, true);
    }

    private function connectIfNotConnected(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        if ($socket === false) {
            throw new RuntimeException('Failed to create socket');
        }
        $this->socket = $socket;

        $this->isConnected = socket_connect($this->socket, $this->path);
        if (!$this->isConnected()) {
            throw new RuntimeException('Failed to connect to journal socket');
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && $this->isConnected;
    }

    protected function encodeMessage(LogRecord $record): string
    {
        $fields = array_merge([
            $this->buildMultilineField('MESSAGE', $record->formatted),
            "PRIORITY={$record->level->toRFC5424Level()}",
            "SYSLOG_IDENTIFIER=$record->channel",
        ], $this->getAdditionalFields($record));

        return implode("\n", $fields) . "\n";
    }

    /**
     * @param LogRecord $record
     * @return string[]
     */
    protected function getAdditionalFields(LogRecord $record): array
    {
        $collectedException = false;
        $fields = [];
        $metadata = array_merge_recursive($record->context, $record->extra);

        foreach ($metadata as $key => $value) {

            // Collect first exception
            if (!$collectedException && $value instanceof Throwable) {
                $fields[] = "CODE_FILE={$value->getFile()}";
                $fields[] = "CODE_LINE={$value->getLine()}";
                $collectedException = true;
                continue;
            }

            // Collect additional fields, but only if they are in key=value form
            if (!is_string($key)) {
                continue;
            }

            $key = strtoupper($key);
            if (in_array($key, self::RESERVED_FIELDS, true)) {
                continue;
            }

            if (!$this->isStringable($value)) {
                continue;
            }

            $fields[] = $this->buildMultilineField($key, $value);
        }

        return $fields;
    }

    /**
     * @param string $data
     * @return void
     */
    protected function writeToSocket(string $data): void
    {
        if ($this->socket === null || !$this->isConnected()) {
            throw new RuntimeException('Socket not connected');
        }

        socket_write($this->socket, $data);
    }

    /**
     * @link https://systemd.io/JOURNAL_NATIVE_PROTOCOL In-depth information about how to handle multiline values
     *
     * @param string $field
     * @param string $value
     * @return string
     */
    protected function buildMultilineField(string $field, string $value): string
    {
        // If no newlines are found in the value, we can simply use key=value pair
        if (!str_contains($value, "\n")) {
            return "$field=$value";
        }

        // If newlines are found, we use the extended method, which is a bit more complicated
        $messageBytes = unpack('C*', $value);
        $messageSizeBytes = unpack('C*', pack('P', strlen($value)));

        if ($messageBytes === false || $messageSizeBytes === false) {
            throw new RuntimeException('Failed to decode message');
        }

        $bytes = array_merge($messageSizeBytes, $messageBytes);
        return "$field\n" . pack('C*', ...$bytes);
    }

    protected function isStringable(mixed $value): bool
    {
        return $value === null
            || is_scalar($value)
            || $value instanceof Stringable;
    }
}