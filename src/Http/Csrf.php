<?php
declare(strict_types=1);

namespace OmniWrite\Http;

final class Csrf
{
    public const FIELD = '_csrf';

    public function token(Session $session): string
    {
        $token = $session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set('csrf', $token);
        }
        return $token;
    }

    public function verify(Session $session, mixed $given): bool
    {
        $expected = $session->get('csrf');
        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }
}
