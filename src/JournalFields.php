<?php
declare(strict_types=1);

namespace Noxdk\MonologJournalHandler;

enum JournalFields: string
{
    case Message = 'MESSAGE';
    case Priority = 'PRIORITY';
    case SyslogIdentifier = 'SYSLOG_IDENTIFIER';
    case CodeFile = 'CODE_FILE';
    case CodeLine = 'CODE_LINE';

    public static function isReserved(string $field): bool {
        foreach (self::cases() as $case) {
            if ($case->value === $field) {
                return true;
            }
        }
        return false;
    }
}
