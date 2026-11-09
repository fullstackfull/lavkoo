<?php

namespace App\Services\SalesChannels\Noon;

use App\Models\SalesChannelAccount;
use App\Services\SalesChannels\Data\ChannelResponse;

/**
 * noon's product groups — the variants of one product shown together — and the bulk SKU map beside them.
 *
 * Paths and field names from `noon-docs.noonpartners.dev/docs/api-reference/content/*` and `.../catalog/*`, read 2026-11-22.
 * These were registered long ago and never called; the hygiene guard did not notice because the registry names each noon
 * operation twice, so two mentions looked like a call.
 *
 * ## The trap that makes `upsert` dangerous, in noon's own words
 *
 * **`attribute_codes` and `sku_parents` REPLACE, they do not merge.** noon: "the list you send replaces the current one, so
 * any attribute you leave out is removed from the group", and the same sentence again for the products. So an upsert built
 * from "the products I am publishing today" silently removes every other product from the group — the group survives, the
 * products survive, and the grouping that made them one listing is gone.
 *
 * noon offers the way out in the same paragraph: "Omit this field, or send an empty list, to leave the attributes unchanged."
 * So this class will not send a partial list. Either the caller states the WHOLE membership, or it changes neither, and the
 * only way to add one product is to send the current list plus the new one — which is why `addMembers()` exists and reads the
 * group first.
 *
 * ## Four more published facts, each a wrong answer if ignored
 *
 *  1. **`partner_group_ref` is matched ignoring case and punctuation.** noon: "my-group, My-Group and my_group are all the
 *     same group." A caller minting references from product names would collide two groups into one and not be told.
 *  2. **`category` and `brand` cannot be changed afterwards.** At least one is required to create. So a create is a decision
 *     and an update must not carry them.
 *  3. **A product can belong to one group only.** "If it is already in another of your groups the request fails and names
 *     that group" — a readable refusal, carried through rather than flattened into "it did not work".
 *  4. **`next_token` is opaque.** noon: "a token you build yourself is not rejected, it simply returns the wrong page." So
 *     paging is only ever continued with the token noon gave, and the walk stops when it comes back empty.
 *
 * ## And what the bulk SKU map does NOT establish
 *
 * `POST /catplat/v1/sku/batch-map` ties our `partner_sku` values to `nsku_child` values. **Whether an `nsku_child` is the
 * same thing as the `sku_parent` the content calls use is NOT STATED anywhere noon publishes** — the docs call `nsku_child`
 * "the unique identifier generated and managed by noon itself for gated brands", which is not the same sentence as
 * `sku_parent`. So this maps what it is documented to map and nothing here resolves a `sku_parent` from it. The earlier
 * decision not to use it for that was right and stands.
 *
 * `#del#` as an `nsku_child` **deletes a mapping** — noon documents that sentinel in the field's own description. It is
 * therefore refused unless the caller asks for a deletion in as many words, because a value that arrived as that literal
 * from anywhere else would quietly unmap a SKU.
 */
class NoonProductGroups
{
    /** noon's cap on `page_size` for reading a group's members. */
    private const MOST_MEMBERS_PER_PAGE = 200;

    /** noon's cap on `attribute_codes`. */
    private const MOST_ATTRIBUTES = 100;

    /** noon's cap on `sku_parents` in one group. */
    public const MOST_MEMBERS = 2000;

    /** The sentinel noon documents in `nsku_child` to DELETE a mapping. Never sent unless asked for by name. */
    public const UNMAP = '#del#';

    public function __construct(private readonly NoonClient $client)
    {
    }

    /**
     * Reads one group: which attributes it varies by, and every product in it, across all pages.
     *
     * @return array{ref: string|null, attribute_codes: list<string>, members: array<string, array<string, mixed>>, total: int|null, problem: string|null}
     */
    public function read(SalesChannelAccount $account, string $groupRef, string $anySkuParent): array
    {
        $groupRef = trim($groupRef);
        $anySkuParent = trim($anySkuParent);
        if ($groupRef === '' || $anySkuParent === '') {
            // Both are required on the request. `sku_parent` is "sku_parent of any product in the group", so a read needs
            // one member to start from and cannot be done from the reference alone.
            return $this->unreadable('noon_group_read_needs_a_reference_and_a_member');
        }

        $members = [];
        $attributes = [];
        $total = null;
        $token = '';
        $pages = 0;

        do {
            $response = $this->client->call(
                account: $account,
                operation: 'noon.product_group_get',
                method: 'POST',
                path: '/content/v1/product/group/get',
                json: [
                    'partner_group_ref' => $groupRef,
                    'sku_parent' => $anySkuParent,
                    // Empty reads the first page. **Never a token of our own**: noon says one we build "is not rejected, it
                    // simply returns the wrong page".
                    'next_token' => $token,
                    'page_size' => self::MOST_MEMBERS_PER_PAGE,
                ],
            );
            if (!$response->ok()) {
                return $this->unreadable($this->problemOf($response, 'noon_group_could_not_be_read'));
            }
            $body = is_array($response->json) ? $response->json : [];
            if (!is_array($body['members'] ?? null)) {
                // `members` is required on the response. Without it this is not a group, and an empty group is a different
                // fact from a body we could not read.
                return $this->unreadable('noon_group_reply_is_not_the_documented_shape');
            }

            foreach ($body['members'] as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $sku = trim((string) ($member['sku_parent'] ?? ''));
                if ($sku !== '') {
                    $members[$sku] = is_array($member['attributes'] ?? null) ? $member['attributes'] : [];
                }
            }
            $attributes = array_values(array_filter(array_map('strval', (array) ($body['attribute_codes'] ?? []))));
            $total = isset($body['total_count']) ? (int) $body['total_count'] : $total;
            $token = trim((string) ($body['next_token'] ?? ''));
            $pages++;
        } while ($token !== '' && $pages < 100);

        return ['ref' => $groupRef, 'attribute_codes' => $attributes, 'members' => $members, 'total' => $total, 'problem' => null];
    }

    /**
     * Creates a group, naming its whole membership.
     *
     * `category` or `brand` is required and **neither can be changed afterwards**, so this is the only call that may carry
     * them and it is a decision rather than a step.
     *
     * @param list<string> $attributeCodes attributes the group varies by; each must have `is_grouping` true in noon's own list
     * @param list<string> $skuParents the whole membership
     * @return array{ok: bool, ref: string|null, refusal: string|null}
     */
    public function create(SalesChannelAccount $account, string $groupRef, array $attributeCodes, array $skuParents, ?string $category = null, ?string $brand = null): array
    {
        if (trim((string) $category) === '' && trim((string) $brand) === '') {
            return ['ok' => false, 'ref' => null, 'refusal' => 'noon_group_create_needs_a_category_or_a_brand'];
        }
        $body = $this->membershipBody($groupRef, $attributeCodes, $skuParents, requireBoth: true);
        if (is_string($body)) {
            return ['ok' => false, 'ref' => null, 'refusal' => $body];
        }
        if (trim((string) $category) !== '') {
            $body['category'] = trim((string) $category);
        }
        if (trim((string) $brand) !== '') {
            $body['brand'] = trim((string) $brand);
        }

        return $this->upsert($account, $body);
    }

    /**
     * Replaces a group's whole membership, with the whole membership.
     *
     * The name says `replace` because that is what noon does, and the parameter is the complete list for the same reason: a
     * method called `update` taking "the products I am publishing" is the shape of the defect this class exists to avoid.
     *
     * @param list<string> $attributeCodes the WHOLE list, or empty to leave the attributes unchanged
     * @param list<string> $skuParents the WHOLE membership, or empty to leave it unchanged
     * @return array{ok: bool, ref: string|null, refusal: string|null}
     */
    public function replaceMembership(SalesChannelAccount $account, string $groupRef, array $attributeCodes, array $skuParents): array
    {
        $body = $this->membershipBody($groupRef, $attributeCodes, $skuParents, requireBoth: false);
        if (is_string($body)) {
            return ['ok' => false, 'ref' => null, 'refusal' => $body];
        }
        // `category` and `brand` are deliberately absent: noon states neither can be changed after the group is created.

        return $this->upsert($account, $body);
    }

    /**
     * Adds products to a group by sending the current membership plus the new ones — which is the only way noon offers.
     *
     * It READS first, and a read it could not complete stops the write. Sending the new products alone would remove every
     * product already in the group, and guessing the current membership from our own records would remove any product noon
     * holds that we do not.
     *
     * @param list<string> $skuParents the products to add
     * @return array{ok: bool, ref: string|null, added: int, refusal: string|null}
     */
    public function addMembers(SalesChannelAccount $account, string $groupRef, string $anySkuParent, array $skuParents): array
    {
        $current = $this->read($account, $groupRef, $anySkuParent);
        if ($current['problem'] !== null) {
            // **The write does not happen.** A partial membership sent to this endpoint removes everything it leaves out.
            return ['ok' => false, 'ref' => null, 'added' => 0, 'refusal' => 'noon_group_members_not_added_because_the_group_could_not_be_read:' . $current['problem']];
        }

        $wanted = array_values(array_filter(array_map('trim', $skuParents), static fn (string $sku): bool => $sku !== ''));
        $whole = array_values(array_unique([...array_keys($current['members']), ...$wanted]));
        $added = count($whole) - count($current['members']);
        if ($added === 0) {
            // Every one of them is already in the group. Nothing is sent, which is the honest no-op.
            return ['ok' => true, 'ref' => $groupRef, 'added' => 0, 'refusal' => null];
        }

        $result = $this->replaceMembership($account, $groupRef, [], $whole);

        return ['ok' => $result['ok'], 'ref' => $result['ref'], 'added' => $result['ok'] ? $added : 0, 'refusal' => $result['refusal']];
    }

    /**
     * Deletes a group. The products themselves are not deleted — noon is explicit that leaving a group does not delete the
     * product — but the grouping that made them one listing is gone and there is no undo documented.
     *
     * @return array{ok: bool, ref: string|null, refusal: string|null}
     */
    public function delete(SalesChannelAccount $account, string $groupRef): array
    {
        $groupRef = trim($groupRef);
        if ($groupRef === '') {
            return ['ok' => false, 'ref' => null, 'refusal' => 'noon_group_delete_needs_a_reference'];
        }

        $response = $this->client->call($account, 'noon.product_group_delete', 'POST', '/content/v1/product/group/delete', json: ['partner_group_ref' => $groupRef]);

        return $this->outcomeOf($response, 'noon_group_could_not_be_deleted');
    }

    /**
     * Ties our `partner_sku` values to noon's `nsku_child` values, in bulk.
     *
     * **This does not resolve a `sku_parent`.** Whether an `nsku_child` is the same identifier as the `sku_parent` the
     * content calls return is stated nowhere noon publishes, so nothing here treats one as the other.
     *
     * Each item carries its own `status`, so a 200 is a per-item answer and never a whole-batch success. An item noon did not
     * mention at all is reported as unanswered rather than as mapped.
     *
     * @param array<string, string> $nskuBySku partner_sku => nsku_child
     * @param bool $overrideExisting noon's `force_map`: "overrides the existing mapping"
     * @param bool $allowUnmapping must be true to send noon's `#del#` sentinel, which DELETES a mapping
     * @return array{ok: bool, mapped: list<string>, failed: array<string, string>, unanswered: list<string>, refusal: string|null}
     */
    public function mapSkus(SalesChannelAccount $account, array $nskuBySku, bool $overrideExisting = false, bool $allowUnmapping = false): array
    {
        $items = [];
        foreach ($nskuBySku as $sku => $nsku) {
            $sku = trim((string) $sku);
            $nsku = trim((string) $nsku);
            if ($sku === '' || $nsku === '') {
                return $this->mapRefusal('noon_sku_map_needs_both_identifiers');
            }
            if ($nsku === self::UNMAP && !$allowUnmapping) {
                // noon documents `#del#` in this field's own description as the way to delete a mapping. A value that
                // arrived as that literal from a spreadsheet, a feed or a typo would quietly unmap a live SKU.
                return $this->mapRefusal('noon_sku_map_will_not_delete_a_mapping_unless_asked:' . $sku);
            }
            $item = ['partner_sku' => $sku, 'nsku_child' => $nsku];
            if ($overrideExisting) {
                $item['force_map'] = true;
            }
            $items[] = $item;
        }
        if ($items === []) {
            return $this->mapRefusal('noon_sku_map_has_nothing_to_map');
        }

        $response = $this->client->call($account, 'noon.sku_batch_map', 'POST', '/catplat/v1/sku/batch-map', json: ['items' => $items]);
        if (!$response->ok()) {
            return $this->mapRefusal($this->problemOf($response, 'noon_sku_map_could_not_be_sent'));
        }
        $body = is_array($response->json) ? $response->json : [];
        if (!is_array($body['items'] ?? null)) {
            // `items` is required on the response. Without it nothing is known per SKU, and a 200 alone is not a mapping.
            return $this->mapRefusal('noon_sku_map_reply_is_not_the_documented_shape');
        }

        $mapped = [];
        $failed = [];
        foreach ($body['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sku = trim((string) ($item['partner_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $status = is_array($item['status'] ?? null) ? $item['status'] : [];
            // `status_id` is how noon states success on every one of these per-item replies; a reply with no status_id says
            // nothing, and saying nothing is not success.
            if (!isset($status['status_id'])) {
                $failed[$sku] = 'noon_said_nothing_about_this_sku';

                continue;
            }
            if ((int) $status['status_id'] === 0) {
                $mapped[] = $sku;

                continue;
            }
            $failed[$sku] = trim((string) ($status['status_code'] ?? '')) . ': ' . trim((string) ($status['message'] ?? ''));
        }

        $answered = [...$mapped, ...array_keys($failed)];
        $unanswered = array_values(array_diff(array_keys($nskuBySku), $answered));

        return [
            // Not a whole-batch success: every SKU sent has to come back, and one noon never mentioned is unanswered.
            'ok' => $failed === [] && $unanswered === [],
            'mapped' => $mapped,
            'failed' => $failed,
            'unanswered' => $unanswered,
            'refusal' => null,
        ];
    }

    // ---- the small shared parts ----------------------------------------------------------------------------------

    /**
     * @param list<string> $attributeCodes
     * @param list<string> $skuParents
     * @return array<string, mixed>|string the body, or the refusal
     */
    private function membershipBody(string $groupRef, array $attributeCodes, array $skuParents, bool $requireBoth): array|string
    {
        $groupRef = trim($groupRef);
        if ($groupRef === '' || mb_strlen($groupRef) > 255) {
            return 'noon_group_reference_is_missing_or_too_long';
        }
        $attributeCodes = array_values(array_unique(array_filter(array_map('trim', $attributeCodes), static fn (string $code): bool => $code !== '')));
        $skuParents = array_values(array_unique(array_filter(array_map('trim', $skuParents), static fn (string $sku): bool => $sku !== '')));

        if ($requireBoth && ($attributeCodes === [] || $skuParents === [])) {
            // On create noon requires at least one of each.
            return 'noon_group_create_needs_at_least_one_attribute_and_one_product';
        }
        if (count($attributeCodes) > self::MOST_ATTRIBUTES) {
            return 'noon_group_takes_at_most_' . self::MOST_ATTRIBUTES . '_attributes:' . count($attributeCodes);
        }
        if (count($skuParents) > self::MOST_MEMBERS) {
            return 'noon_group_takes_at_most_' . self::MOST_MEMBERS . '_products:' . count($skuParents);
        }

        $body = ['partner_group_ref' => $groupRef];
        // **Omitted rather than sent empty when there is nothing to change**: noon treats an absent field and an empty list
        // alike as "leave it unchanged", and sending a list we did not mean is what removes members.
        if ($attributeCodes !== []) {
            $body['attribute_codes'] = $attributeCodes;
        }
        if ($skuParents !== []) {
            $body['sku_parents'] = $skuParents;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, ref: string|null, refusal: string|null}
     */
    private function upsert(SalesChannelAccount $account, array $body): array
    {
        $response = $this->client->call($account, 'noon.product_group_upsert', 'POST', '/content/v1/product/group/upsert', json: $body);

        return $this->outcomeOf($response, 'noon_group_could_not_be_saved');
    }

    /** @return array{ok: bool, ref: string|null, refusal: string|null} */
    private function outcomeOf(ChannelResponse $response, string $code): array
    {
        if ($response->isDryRun()) {
            return ['ok' => false, 'ref' => null, 'refusal' => 'noon_rehearsal_sent_nothing'];
        }
        if (!$response->ok()) {
            return ['ok' => false, 'ref' => null, 'refusal' => $this->problemOf($response, $code)];
        }
        $body = is_array($response->json) ? $response->json : [];
        $status = is_array($body['status'] ?? null) ? $body['status'] : [];
        if (!isset($status['status_id'])) {
            // The same rule as every other noon reply in this module: `status_id` is how noon states the outcome, and a body
            // without it has told us nothing -- which a 200 does not change.
            return ['ok' => false, 'ref' => null, 'refusal' => 'noon_said_nothing_about_the_group'];
        }
        if ((int) $status['status_id'] !== 0) {
            // noon names the OTHER group when a product already belongs to one. That message is carried through, because it
            // is the one thing a person needs in order to act.
            return ['ok' => false, 'ref' => null, 'refusal' => $code . ':' . trim((string) ($status['status_code'] ?? '')) . ': ' . trim((string) ($status['message'] ?? ''))];
        }

        return ['ok' => true, 'ref' => ($ref = trim((string) ($body['partner_group_ref'] ?? ''))) === '' ? null : $ref, 'refusal' => null];
    }

    /** @return array{ref: null, attribute_codes: array<never>, members: array<never>, total: null, problem: string} */
    private function unreadable(string $problem): array
    {
        return ['ref' => null, 'attribute_codes' => [], 'members' => [], 'total' => null, 'problem' => $problem];
    }

    /** @return array{ok: false, mapped: array<never>, failed: array<never>, unanswered: array<never>, refusal: string} */
    private function mapRefusal(string $refusal): array
    {
        return ['ok' => false, 'mapped' => [], 'failed' => [], 'unanswered' => [], 'refusal' => $refusal];
    }

    private function problemOf(ChannelResponse $response, string $code): string
    {
        return $response->isDryRun() ? 'noon_rehearsal_asked_nothing' : $code . ': ' . $response->message();
    }
}
