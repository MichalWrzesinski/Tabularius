<?php

declare(strict_types=1);

namespace App\Module\Mail\Imap;

/**
 * Transport or protocol failure talking to the mailbox; never carries credentials or message contents.
 */
final class ImapException extends \RuntimeException
{
}
