<?php
declare(strict_types=1);

namespace OmniWrite\Http;

use OmniWrite\Support\Translator;

/** Rendert templates/<name>.php in templates/layout.php. Ausgabe wird im Template mit $e() escaped. */
final class View
{
    public function __construct(
        private readonly string $dir,
        private readonly Translator $translator,
        private readonly Csrf $csrf,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        $locale = $request->locale;
        $vars = $data + [
            'user' => $request->user,
            'impersonator' => $request->impersonator,
            'locale' => $locale,
            'csrf' => $this->csrf->token($request->session),
            'e' => static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            't' => fn (string $key, array $p = []): string => $this->translator->t($locale, $key, $p),
        ];
        $content = $this->capture($this->dir . '/' . $template . '.php', $vars);
        $page = $this->capture($this->dir . '/layout.php', $vars + ['content' => $content]);
        return Response::html($page, $status);
    }

    /**
     * Öffentliche Blog-Seite (templates/public/<name>.php in templates/public/layout.php), ohne Portal-Navigation.
     * Bilder dürfen von https-Adressen kommen; Skripte bleiben verboten.
     *
     * @param array<string, mixed> $data
     */
    public function renderPublic(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        $locale = $request->locale;
        $vars = $data + [
            'locale' => $locale,
            'e' => static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            't' => fn (string $key, array $p = []): string => $this->translator->t($locale, $key, $p),
        ];
        $content = $this->capture($this->dir . '/public/' . $template . '.php', $vars);
        $page = $this->capture($this->dir . '/public/layout.php', $vars + ['content' => $content]);
        return Response::html($page, $status)->withHeaders([
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /** @param array<string, mixed> $vars */
    private function capture(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
