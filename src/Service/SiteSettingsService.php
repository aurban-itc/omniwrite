<?php
declare(strict_types=1);

namespace OmniWrite\Service;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\User;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Repository\SiteSettingsRepository;
use PDO;

/** Redaktionelle Einstellungen pro Site (Style-Guide, Firmenfakten, Bildstil). Leer speichern stellt den Standard wieder her. */
final class SiteSettingsService
{
    public const KEYS = ['style_guide', 'company_facts', 'image_style'];
    private const MAX_LENGTH = 8000;

    private const DEFAULTS = [
        'de' => [
            'style_guide' => "Ton: sachkundig, klar und hilfreich, wie ein vertrauenswürdiger Fachberater. Sprechen Sie die Leser direkt an.\nErst helfen, zuletzt verkaufen: Der Leser soll auch dann etwas mitnehmen, wenn er nie anruft. Ein einzelner dezenter Handlungsaufruf am Ende genügt.\nKeine erfundenen Zahlen, Studien, Kundennamen, Zitate, Preise oder Auszeichnungen. Fehlt eine Zahl im Material, die Wirkung in Worten beschreiben.\nKeine Floskeln als Einstieg, keine Übertreibungen, keine Ausrufezeichen.",
            'company_facts' => '',
            'image_style' => 'Fotorealistisch, redaktionell, natürliches Licht, kein Text im Bild, keine Logos.',
        ],
        'en' => [
            'style_guide' => "Voice: knowledgeable, plain-spoken and helpful, like a trusted advisor. Address readers directly.\nTeach first, sell last: the reader should learn something useful even if they never get in touch. A single soft call to action near the end is enough.\nNever invent statistics, studies, customer names, quotes, prices or awards. If a number is not in the provided material, describe the effect in words.\nAvoid filler openings, hype words and exclamation marks.",
            'company_facts' => '',
            'image_style' => 'Photorealistic, editorial, natural light, no text in the image, no logos.',
        ],
    ];

    public function __construct(private readonly PDO $pdo, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string, string> Alle Schlüssel, mit Standardwert der Site-Sprache, wo nichts gespeichert ist. */
    public function get(ResolvedSite $s): array
    {
        $repo = new SiteSettingsRepository($this->pdo, $s->context);
        $defaults = self::DEFAULTS[$s->locale()] ?? self::DEFAULTS['de'];
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $repo->get($key, $defaults[$key]);
        }
        return $out;
    }

    /** @param array<string, mixed> $in */
    public function save(ResolvedSite $s, User $actor, array $in, ?string $ip = null): void
    {
        if (!$s->canPublish()) {
            throw new AccessDeniedException('Nur Owner dürfen Einstellungen ändern');
        }
        $errors = [];
        $values = [];
        foreach (self::KEYS as $key) {
            $value = trim((string) ($in[$key] ?? ''));
            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[$key] = 'err.too_long';
            }
            $values[$key] = $value;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $repo = new SiteSettingsRepository($this->pdo, $s->context);
        foreach ($values as $key => $value) {
            $value === '' ? $repo->delete($key) : $repo->set($key, $value);
        }
        $this->audit->log('site.settings', $actor->id, $s->accountId(), ['site_id' => $s->id()], $ip);
    }
}
