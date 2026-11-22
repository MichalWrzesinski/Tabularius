<?php

declare(strict_types=1);

namespace App\Module\Mail;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * On whose behalf a message may run (docs/product/areas/identity-access.md):
 * - production (ID-1, ID-4): the sender address only *selects a candidate*: an active representative account with
 *   exactly that address (normalized). Nothing runs before that person confirms in the portal (ID-2). Unknown,
 *   blocked or missing senders get no owner and go to manual handling.
 * - demo (ID-DEMO-1, ID-DEMO-3): any sender runs as the shared demo representative; the ticket shows only a synthetic
 *   alias derived from the pseudonymous sender hash, stable per sender.
 */
final class MessageIdentity
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire(env: 'APP_MODE')]
        private readonly string $mode,
    ) {
    }

    public function demo(): bool
    {
        return 'demo' === $this->mode;
    }

    /**
     * @return array{owner: ?string, alias: ?string}
     */
    public function resolve(?string $sender, ?string $senderHash): array
    {
        if ($this->demo()) {
            $owner = $this->db->fetchOne("SELECT id FROM account WHERE shared AND role = 'rep' AND status = 'active' ORDER BY created_at LIMIT 1");

            return ['owner' => \is_string($owner) ? $owner : null, 'alias' => self::alias($senderHash)];
        }
        if (null === $sender) {
            return ['owner' => null, 'alias' => null];
        }
        $owner = $this->db->fetchOne("SELECT id FROM account WHERE lower(email) = ? AND role = 'rep' AND status = 'active' AND NOT shared", [$sender]);

        return ['owner' => \is_string($owner) ? $owner : null, 'alias' => null];
    }

    public static function alias(?string $senderHash): string
    {
        if (null === $senderHash) {
            return 'Uczestnik #----';
        }
        $code = strtoupper(base_convert(substr($senderHash, 0, 10), 16, 36));

        return 'Uczestnik #'.substr(str_pad($code, 4, '0', \STR_PAD_LEFT), -4);
    }
}
