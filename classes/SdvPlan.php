<?php

namespace CCTC\SourceDataVerificationModule\Classes;

/**
 * Decides whether each field requires SDV. Action tags give the default (flag regex → required,
 * ignore tag → excluded, otherwise optional) and rows in sdv_plan, set by monitors, override it.
 */
class SdvPlan
{
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';
    public const EXCLUDED = 'excluded';
    public const VALUES = [self::REQUIRED, self::OPTIONAL, self::EXCLUDED];

    private ?array $overrides = null;
    private array $byInstrument = [];

    public function __construct(
        private readonly \Project $proj,
        private readonly Settings $settings,
        private readonly Repository $repo
    ) {}

    private function overrides(): array
    {
        return $this->overrides ??= $this->repo->getPlanOverrides();
    }

    // Fields on the instrument that can take part in SDV: excludes the record id, descriptive fields and form status
    public function sdvFieldNames(string $instrument): array
    {
        $names = [];
        foreach (array_keys($this->proj->forms[$instrument]['fields'] ?? []) as $field) {
            if ($field === $this->proj->table_pk) continue;
            if ($field === "{$instrument}_complete") continue;
            if (($this->proj->metadata[$field]['element_type'] ?? '') === 'descriptive') continue;
            $names[] = $field;
        }
        return $names;
    }

    /**
     * @return array<string, array{requirement:string, default:string, override:?string, tags:string[], label:string}>
     */
    public function forInstrument(string $instrument): array
    {
        if (isset($this->byInstrument[$instrument])) return $this->byInstrument[$instrument];

        $regex = $this->settings->flagRegex();
        $ignoreTag = $this->settings->ignoreTag();
        $overrides = $this->overrides();
        $plan = [];

        foreach ($this->sdvFieldNames($instrument) as $field) {
            $misc = (string)($this->proj->metadata[$field]['misc'] ?? '');
            $tags = [];
            if ($misc !== '' && @preg_match_all($regex, $misc, $m)) {
                $tags = array_values(array_unique($m[0]));
            }

            if ($ignoreTag !== '' && self::hasActionTag($misc, $ignoreTag)) {
                $default = self::EXCLUDED;
            } elseif (!empty($tags)) {
                $default = self::REQUIRED;
            } else {
                $default = self::OPTIONAL;
            }

            $override = $overrides[$field] ?? null;
            $plan[$field] = [
                'requirement' => $override ?? $default,
                'default' => $default,
                'override' => $override,
                'tags' => $tags,
                'label' => self::cleanLabel($this->proj->metadata[$field]['element_label'] ?? ''),
            ];
        }

        return $this->byInstrument[$instrument] = $plan;
    }

    public function requiredFields(string $instrument): array
    {
        return array_keys(array_filter($this->forInstrument($instrument), fn($f) => $f['requirement'] === self::REQUIRED));
    }

    // Instruments that currently have at least one field requiring SDV
    public function instrumentsRequiringSdv(): array
    {
        $out = [];
        foreach (array_keys($this->proj->forms) as $instrument) {
            if (!empty($this->requiredFields($instrument))) $out[] = $instrument;
        }
        return $out;
    }

    public static function hasActionTag(string $misc, string $tag): bool
    {
        return (bool)preg_match('/(^|\s)' . preg_quote($tag, '/') . '(?![\w-])/i', $misc);
    }

    public static function cleanLabel(string $label): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($label, ENT_QUOTES, 'UTF-8'))));
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '...' : $text;
    }
}
