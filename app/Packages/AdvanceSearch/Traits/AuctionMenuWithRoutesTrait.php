<?php


namespace App\Packages\AdvanceSearch\Traits;

trait AuctionMenuWithRoutesTrait
{
    public function getAuctionAdminMenuRoutes(): array
    {
        if (!$this->isAuctionAddonPublished()) {
            return [];
        }

        $entries = [];

        // Same files the sidebar reads (Modules/Auction/Addon/*), so adding a
        // new auction sidebar item flows straight through to search.
        foreach ($this->loadAuctionAddonRoutes('Modules/Auction/Addon/admin_routes.php') as $group) {
            $groupName = (string)($group['name'] ?? '');
            $entries[] = $this->buildAuctionEntry(
                name: $groupName,
                path: (string)($group['path'] ?? ''),
                groupName: 'Auction Management',
            );
            foreach (($group['routes'] ?? []) as $route) {
                $entries[] = $this->buildAuctionEntry(
                    name: (string)($route['name'] ?? ''),
                    path: (string)($route['path'] ?? ''),
                    groupName: $groupName,
                );
            }
        }

        foreach ($this->loadAuctionAddonRoutes('Modules/Auction/Addon/auction_report_routes.php') as $report) {
            $reportName = (string)($report['name'] ?? '');
            $entries[] = $this->buildAuctionEntry(
                name: $reportName,
                path: (string)($report['path'] ?? ''),
                groupName: 'Auction Reports',
            );
            foreach (($report['sub_routes'] ?? []) as $sub) {
                $entries[] = $this->buildAuctionEntry(
                    name: (string)($sub['name'] ?? ''),
                    path: (string)($sub['path'] ?? ''),
                    groupName: $reportName,
                );
            }
        }

        // Sidebar entries hardcoded in _side-bar.blade.php rather than the
        // addon files — index them here so search stays in parity.
        $extras = [
            ['name' => 'Auction Withdraw', 'path' => 'admin/auction/auction-withdraw', 'group' => 'Auction Management'],
            ['name' => 'Entry Fee Payments Offline', 'path' => 'admin/auction/entry-fee-payments/offline', 'group' => 'Auction Management'],
            ['name' => 'Entry Fee Payments Digital', 'path' => 'admin/auction/entry-fee-payments/digital', 'group' => 'Auction Management'],
            ['name' => 'Auction Config', 'path' => 'admin/business-settings/auction-config', 'group' => 'Business Settings'],
            ['name' => 'Company Reliability', 'path' => 'admin/auction/company-reliability', 'group' => 'Auction Management'],
        ];
        foreach ($extras as $extra) {
            $entries[] = $this->buildAuctionEntry($extra['name'], $extra['path'], $extra['group']);
        }

        return array_values(array_filter($entries));
    }

    private function isAuctionAddonPublished(): bool
    {
        return function_exists('getCheckAddonPublishedStatus')
            && (bool)getCheckAddonPublishedStatus(moduleName: 'Auction');
    }

    private function loadAuctionAddonRoutes(string $relativePath): array
    {
        $full = base_path($relativePath);
        if (!is_file($full)) {
            return [];
        }
        $data = include $full;
        return is_array($data) ? $data : [];
    }

    private function buildAuctionEntry(string $name, string $path, string $groupName): ?array
    {
        $name = trim($name);
        $path = trim($path);
        if ($name === '' || $path === '') {
            return null;
        }
        $keywords = implode(', ', $this->buildAuctionKeywords($name, $groupName, $path));
        return [
            'page_title' => $name,
            'page_title_value' => $name,
            'uri' => $path,
            'key' => base64_encode('dbsearch' . $path),
            'uri_count' => count(explode('/', $path)),
            'method' => 'GET',
            'keywords' => $keywords,
            'type' => 'menu',
            // Empty module = visible to any admin, matching how auction routes
            // are actually exposed (no `module:auction` middleware on them).
            'module' => '',
            'priority' => '1',
        ];
    }

    /**
     * Builds a comma-separated keyword bag so common search phrases hit.
     * searchMenuList() does substring/equality on each comma-token, so we
     * pre-generate plural/singular variants and "auction" pairings to cover
     * what a user is likely to type ("new auction request", "pending
     * auction", etc.) without enumerating every phrase by hand.
     */
    private function buildAuctionKeywords(string $name, string $groupName, string $path): array
    {
        $variants = [];
        $add = static function (string $s) use (&$variants): void {
            $s = trim((string)preg_replace('/\s+/', ' ', strtolower($s)));
            if ($s === '') {
                return;
            }
            $variants[$s] = true;
        };

        $nameLc = strtolower($name);
        $groupLc = strtolower($groupName);

        $add($nameLc);
        if ($groupLc !== '') {
            $add($groupLc);
            $add("$nameLc $groupLc");
            $add("$groupLc $nameLc");
        }

        $nameSingular = $this->toSingularPhrase($nameLc);
        $groupSingular = $this->toSingularPhrase($groupLc);
        if ($nameSingular !== $nameLc) {
            $add($nameSingular);
            if ($groupSingular !== '') {
                $add("$nameSingular $groupSingular");
                $add("$groupSingular $nameSingular");
            }
        }

        foreach ([$nameLc, $nameSingular] as $variant) {
            if ($variant === '' || str_contains($variant, 'auction')) {
                continue;
            }
            $add("auction $variant");
            $add("$variant auction");
        }

        $ignoreSegments = ['admin', 'vendor', 'products', 'list', 'auction', 'business settings'];
        foreach (explode('/', $path) as $segment) {
            $seg = strtolower(str_replace(['-', '_'], ' ', $segment));
            if ($seg === '' || strlen($seg) < 3 || in_array($seg, $ignoreSegments, true)) {
                continue;
            }
            $add("auction $seg");
            $add("$seg auction");
        }

        return array_keys($variants);
    }

    private function toSingularPhrase(string $phrase): string
    {
        $tokens = preg_split('/\s+/', trim($phrase)) ?: [];
        foreach ($tokens as &$t) {
            $tl = strtolower($t);
            if (strlen($tl) <= 3) {
                continue;
            }
            if (str_ends_with($tl, 'ies')) {
                $t = substr($tl, 0, -3) . 'y';
            } elseif (str_ends_with($tl, 'sses')) {
                $t = substr($tl, 0, -2);
            } elseif (str_ends_with($tl, 's') && !str_ends_with($tl, 'ss')) {
                $t = substr($tl, 0, -1);
            }
        }
        unset($t);
        return implode(' ', $tokens);
    }
}
