<?php

namespace App\Content\Render;

use App\Research\ResearchRefused;
use Normalizer;

/**
 * One card to render with Bangla text on it (docs/85 C.8: "Bangla text on an image is rendered by a
 * browser engine, never by GraphicsMagick or a similar library without shaping").
 *
 * The request fixes what the render service must honour: the text exactly as approved (NFC), `lang`
 * set so the browser picks Bangla shaping, a font that carries the Bengali script, and JPEG for
 * Instagram (Meta: "JPEG is the only image format supported"). The only engine it can name is
 * `chromium`; there is no field through which a non-shaping renderer could be chosen.
 */
final readonly class CardRenderRequest
{
    public const ENGINE = 'chromium';

    /** Fonts with full Bengali coverage the render image installs. */
    public const BANGLA_FONTS = ['Noto Sans Bengali', 'Noto Serif Bengali', 'Hind Siliguri', 'Tiro Bangla'];

    private function __construct(
        public string $text,
        public string $lang,
        public string $font,
        public int $width,
        public int $height,
        public string $format,
    ) {}

    public static function for(string $text, string $platform, string $font = 'Noto Sans Bengali', int $width = 1080, int $height = 1080): self
    {
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }
        if (trim($text) === '' || mb_strlen($text) > 600) {
            throw new ResearchRefused('A card carries between 1 and 600 characters.');
        }

        $bangla = BanglaText::isBangla($text);
        if ($bangla && ! in_array($font, self::BANGLA_FONTS, true)) {
            throw new ResearchRefused("[{$font}] is not a font with the Bengali script; Bangla is rendered in one of ".implode(', ', self::BANGLA_FONTS).'.');
        }
        if ($width < 320 || $width > 2160 || $height < 320 || $height > 2700) {
            throw new ResearchRefused('A card is between 320 and 2160 pixels wide and 320 and 2700 tall.');
        }

        // JPEG for every platform (`$platform` is named for the reader): Instagram accepts nothing else,
        // and one format keeps one checksum per card across the Page and the account.
        return new self($text, $bangla ? 'bn' : 'en', $font, $width, $height, 'jpeg');
    }

    /**
     * The hash the render service must echo: of the text as laid out, whitespace folded, the same fold
     * the service applies to the rendered element's innerText (line breaks become `<br>` in the page).
     */
    public function textHash(): string
    {
        return hash('sha256', trim(preg_replace('/\s+/u', ' ', $this->text) ?? $this->text));
    }

    /** The page the service renders: the text escaped, never interpreted. */
    public function html(): string
    {
        $text = nl2br(htmlspecialchars($this->text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $font = htmlspecialchars($this->font, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="'.$this->lang.'"><head><meta charset="utf-8">'
            .'<style>html,body{margin:0}#card{box-sizing:border-box;width:'.$this->width.'px;height:'.$this->height.'px;'
            .'display:flex;align-items:center;justify-content:center;padding:64px;font-family:"'.$font.'",sans-serif;'
            .'font-size:56px;line-height:1.5;text-align:center;background:#ffffff;color:#1a1a1a}</style>'
            .'</head><body><div id="card">'.$text.'</div></body></html>';
    }

    public function toPayload(): array
    {
        return [
            'engine' => self::ENGINE,
            'html' => $this->html(),
            'lang' => $this->lang,
            'font' => $this->font,
            'width' => $this->width,
            'height' => $this->height,
            'format' => $this->format,
            'text_sha256' => $this->textHash(),
        ];
    }
}
