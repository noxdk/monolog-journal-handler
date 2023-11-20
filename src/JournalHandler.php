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
    public const FORMAT = '%level_name%: %message% %context% %extra%';

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
        parent::close();

        $this->isConnected = false;
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
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
            $this->buildField(JournalFields::Message, $record->formatted),
            $this->buildField(JournalFields::Priority, $record->level->toRFC5424Level()),
            $this->buildField(JournalFields::SyslogIdentifier, $record->channel),
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
                $fields[] = $this->buildField(JournalFields::CodeFile, $value->getFile());
                $fields[] = $this->buildField(JournalFields::CodeLine, $value->getLine());
                $collectedException = true;
                continue;
            }

            // Collect additional fields, but only if they are in key=value form
            if (!is_string($key)) {
                continue;
            }

            $key = strtoupper($key);
            if (JournalFields::isReserved($key)) {
                continue;
            }

            if (!$this->isStringable($value)) {
                continue;
            }

            $fields[] = $this->buildField($key, $value);
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
     * @param string|JournalFields $field
     * @param bool|int|string $value
     * @return string
     */
    protected function buildField(string|JournalFields $field, bool|int|float|string $value): string
    {
        if ($field instanceof JournalFields) {
            $field = $field->value;
        }

        // If no newlines are found in the value, we can simply use key=value pair
        if (!is_string($value) || !str_contains($value, "\n")) {
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