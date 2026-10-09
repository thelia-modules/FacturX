<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FacturX\Tests\Support;

use Symfony\Component\Mime\Email;
use Thelia\Mailer\MailerFactory;

final class RecordingMailer extends MailerFactory
{
    /** @var list<Email> */
    public array $sent = [];

    /** @var list<array{code: string, parameters: array<string, mixed>, locale: ?string}> */
    public array $created = [];

    public function __construct()
    {
    }

    public function createEmailMessage(string $messageCode, array $from, array $to, array $messageParameters = [], ?string $locale = null, array $cc = [], array $bcc = [], array $replyTo = []): Email
    {
        $this->created[] = ['code' => $messageCode, 'parameters' => $messageParameters, 'locale' => $locale];

        return (new Email())->subject($messageCode)->to(array_key_first($to))->text('body');
    }

    public function send(Email $message): void
    {
        $this->sent[] = $message;
    }
}
