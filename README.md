# monolog-journal-handler

Monolog handler for journald (systemd). This package does not depend on a systemd PHP extension.
Logs are being written directly to the journald socket. It uses `LineFormatter` for message formatting, but with a modified format, in order to remove timestamp from the message.
The formatter can be changed with `$handler->setFormatter();`

## Features

- Support for custom tags (using monolog context/extra fields)
- Multiline log support
- Exception file and line log tags

## Installation

Install with composer: `composer require noxdk/monolog-journal-handler`

## Configuration arguments

| Argument  | Description                                                          | Default value                 |
|-----------|----------------------------------------------------------------------|-------------------------------|
| `$path`   | The path to the journald socket                                      | `/run/systemd/journal/socket` |
| `$level`  | The minimum logging level at which this handler will be triggered    | `Level::DEBUG`                |
| `$bubble` | Whether the messages that are handled can bubble up the stack or not | `true`                        |

## Examples

Simple log entry:

```php
<?php
require 'vendor/autoload.php';

use Monolog\Logger;
use Noxdk\MonologJournalHandler\JournalHandler;

$log = new Logger('MyLogger', [new JournalHandler()]);

$log->info('This is logged to the journal');
```

```
nov 20 10:08:14 host MyLogger[1]: INFO: This is logged to the journal
```

\
Multiple lines:

```php
$log->info("This is logged to the journal\nand contains multiple\nlines");
```

```
nov 20 10:08:14 host MyLogger[1]: INFO: This is logged to the journal
                                  and contains multiple
                                  lines
```

\
Custom tags (journal entry as trimmed json output):

```php
$log->info('This is logged to the journal with custom tag', [
    'MY_TAG' => 'MyTag',
    'ANOTHER_TAG' => "With\nnewlines"
]);
```

```
nov 20 10:08:14 host MyLogger[1]: INFO: This is logged to the journal with custom tag {"MY_TAG":"MyTag","ANOTHER_TAG":"With
                                  newlines"}
```

```json
{
    ...
    "SYSLOG_IDENTIFIER": "MyLogger",
    "_HOSTNAME": "host",
    "_UID": "1000",
    "MY_TAG": "MyTag",
    "ANOTHER_TAG": "With\nnewlines",
    "MESSAGE": "INFO: This is logged to the journal with custom tag {\"MY_TAG\":\"MyTag\",\"ANOTHER_TAG\":\"With\nnewlines\"} ",
    "PRIORITY": "6",
    "_CMDLINE": "php test.php",
    ...
}
```

\
Exception log:

```php
$log->error('Some error occurred', [new Exception('Bad something')]);
```

```
nov 20 10:08:14 host MyLogger[1]: ERROR: Some error occurred ["[object] (Exception(code: 0): Bad something at /test.php:18)
                                  [stacktrace]
                                  #0 /test.php(13): TestClass->update()
                                  #1 /test.php(22): TestClass->__construct()
                                  #2 {main}
                                  "]
```
