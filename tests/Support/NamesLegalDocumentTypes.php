<?php

namespace Tests\Support;

/**
 * Whether a name of the platform's registry of operating rules names a legal document type (I2-06). A group, a policy or a title that took the name of a text a customer accepts
 * would blur two stores that are meant to stay apart: an operating rule is a number or a switch in `business_settings`, a legal text is a row of `storefront_legal_documents`.
 *
 * The judgement is about meaning, not about two strings being equal. A name is read as the words it is made of — case, camelCase and hyphens folded away, the singular and the
 * plural counting as one word — and it names a type when it holds every word of that type, in order and as whole words. So 'payment_terms' names 'terms' and 'returns_window'
 * names 'return', while 'returnable_items', 'terminal_id' and 'shippingless' name nothing. A match is not by itself a defect: what an operating rule is called is the owner's to
 * say, and the tests that use this list by name, with the reason, the names that hold a type and are operating rules all the same.
 *
 * It also keeps the names the business pages of the platform are stored under, which the guards of both stores need: one so that no operating rule takes the name of a page, the
 * other so that a readiness answer is proved not to follow a text written under one.
 */
trait NamesLegalDocumentTypes
{
    /**
     * Every name of the registry that holds a legal document type as a whole word today, and what it really is. 'shipping', 'return', 'refund' and 'cancellation' are also what
     * operating rules are about: the group 'shipping' and its two policies are courier silence on a parcel, two policies are the hours a seller has to answer a refund request and
     * to process a return, and six seller-standing bands are rates of cancelled, returned and refunded orders. None is a legal text, none is read or written as one, and renaming
     * them would change the registry, which the pin of what the registry holds forbids. Any other name that does the same fails the guards. Whether the owner wants these renamed
     * is the owner's to say.
     *
     * @return array<string, string> the reason, by the label a failure prints
     */
    protected function operatingRuleNamesThatHoldALegalDocumentType(): array
    {
        return [
            'group:shipping' => 'the courier-silence thresholds of the fulfilment policy: a parcel that does not move, not the shipping policy a customer reads',
            'policy:shipping_silent_hours' => 'hours a parcel may go without courier movement before it is raised',
            'policy:shipping_stop_after_days' => 'days after which a silent parcel is no longer raised',
            'policy:ops_returns_response_hours' => 'hours a seller has to answer a refund request before the action center raises it: a window of the work, not the return policy a customer reads',
            'policy:ops_returns_processing_hours' => 'hours a seller has to process an authorised return before the action center raises it: a window of the work, not the return policy a customer reads',
            'policy:health_watch_cancellation_rate' => 'the share of a seller\'s orders cancelled that puts the seller on watch: a rate, not the cancellation policy',
            'policy:health_watch_return_rate' => 'the share of a seller\'s orders returned that puts the seller on watch: a rate, not the return policy',
            'policy:health_watch_refund_rate' => 'the share of a seller\'s orders refunded that puts the seller on watch: a rate, not the refund policy',
            'policy:health_at_risk_cancellation_rate' => 'the share of a seller\'s orders cancelled that puts the seller at risk: a rate, not the cancellation policy',
            'policy:health_at_risk_return_rate' => 'the share of a seller\'s orders returned that puts the seller at risk: a rate, not the return policy',
            'policy:health_at_risk_refund_rate' => 'the share of a seller\'s orders refunded that puts the seller at risk: a rate, not the refund policy',
        ];
    }

    /**
     * How the business pages of the platform and its cookie text are named in `business_settings`, in both spellings the codebase uses: the installer seeds them and
     * AppServiceProvider reads them hyphenated ('refund-policy'), the older pages and the settings screen use the underscored form ('terms_condition'). An operating rule that took
     * one of these names would be saved over a legal page, and a readiness answer that read a text under one of them would read the Saudi page as another market's text.
     *
     * @return list<string>
     */
    protected function settingNamesOfTheLegalPages(): array
    {
        return [
            'terms_condition', 'privacy_policy', 'refund_policy', 'return_policy', 'cancellation_policy', 'shipping_policy',
            'refund-policy', 'return-policy', 'cancellation-policy', 'shipping-policy',
            'terms-and-conditions', 'privacy-policy', 'about_us', 'about-us',
            'cookie_setting', 'cookie_policy', 'cookie-policy', 'cookies_policy',
        ];
    }

    /** @return list<string> the legal document types the code of the storefronts names: the documents a storefront must publish and the ones it must take an explicit acceptance of */
    protected function legalDocumentTypesInTheCode(): array
    {
        $types = array_merge(
            (array) config('gcc.storefronts.legal.required_documents', []),
            (array) config('gcc.storefronts.legal.explicit_acceptance', []),
        );

        return array_values(array_unique(array_map(static fn (mixed $type): string => (string) $type, $types)));
    }

    /** @return list<string> the words of a name, lower case and in the singular: 'privacy_policy', 'privacy-policy' and 'privacyPolicy' are 'privacy' and 'policy', and 'returns' is 'return' */
    protected function wordsOf(string $name): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name)), -1, PREG_SPLIT_NO_EMPTY);

        return array_map(static fn (string $word): string => (string) preg_replace('/s$/', '', $word), $words);
    }

    /**
     * @param array<string, string> $names what is named, by the label a failure prints
     * @param list<string> $types
     * @return array<string, list<string>> each label whose name holds a type as a whole word, with the types it names
     */
    protected function legalDocumentTypesNamedIn(array $names, array $types): array
    {
        $found = [];
        foreach ($names as $label => $name) {
            $spokenName = ' ' . implode(' ', $this->wordsOf(name: $name)) . ' ';
            $named = array_values(array_filter($types, fn (string $type): bool => str_contains($spokenName, ' ' . implode(' ', $this->wordsOf(name: $type)) . ' ')));
            if ($named !== []) {
                $found[$label] = $named;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * @param array<string, array{policies: array<string, mixed>}> $groups
     * @return array<string, string> every group and every policy of a registry, by the label a failure prints
     */
    protected function namesOfTheRegistry(array $groups): array
    {
        $names = [];
        foreach ($groups as $group => $meta) {
            $names['group:' . $group] = (string) $group;
            foreach (array_keys($meta['policies']) as $policy) {
                $names['policy:' . $policy] = (string) $policy;
            }
        }

        return $names;
    }

    /**
     * @param array<string, array{title?: string}> $groups
     * @return array<string, string> the title of every group of a registry, by the label a failure prints
     */
    protected function titlesOfTheRegistry(array $groups): array
    {
        $titles = [];
        foreach ($groups as $group => $meta) {
            $titles['title of group:' . $group] = (string) ($meta['title'] ?? '');
        }

        return $titles;
    }
}
