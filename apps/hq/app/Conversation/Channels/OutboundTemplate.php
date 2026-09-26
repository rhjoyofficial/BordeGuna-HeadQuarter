<?php

namespace App\Conversation\Channels;

/**
 * A WhatsApp message template by name, as Meta approved it (docs/88 A point 2).
 *
 * Outside the customer service window only a template may be sent, and only one Meta approved in the
 * named language. HQ does not author or submit templates: it names one from its allow-list
 * (`config('hq.messaging.whatsapp.templates')`, empty) and fills the body variables in order.
 *
 * ⛔ The category is carried because it decides consent: a `marketing` template is a marketing message,
 * which the Service consent that a customer's own message grants never covers. The reply tool refuses
 * one outright; a marketing send would be a different tool with the Marketing purpose.
 *
 * @param  list<string>  $bodyParameters  the `{{1}}`, `{{2}}` values of the body, in order
 */
final readonly class OutboundTemplate
{
    public const UTILITY = 'utility';

    public const AUTHENTICATION = 'authentication';

    public const MARKETING = 'marketing';

    public function __construct(
        public string $name,
        public string $languageCode,
        public string $category,
        public array $bodyParameters = [],
    ) {}

    /** The `template` object of Meta's send request. */
    public function toGraph(): array
    {
        $template = ['name' => $this->name, 'language' => ['code' => $this->languageCode]];
        if ($this->bodyParameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(static fn (string $v) => ['type' => 'text', 'text' => $v], $this->bodyParameters),
            ]];
        }

        return $template;
    }
}
